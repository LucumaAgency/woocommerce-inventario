<?php
/**
 * Asistente de configuración (wizard) que aparece al activar el plugin.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wizard de primera configuración: bienvenida, sedes y recojo.
 */
class MSP_Wizard {

	const PAGE       = 'msp-wizard';
	const OPT_DONE   = 'msp_wizard_done';
	const TRANSIENT  = 'msp_activation_redirect';

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'registrar_pagina' ) );
		add_action( 'admin_init', array( $this, 'redirigir_tras_activar' ) );
		add_action( 'admin_init', array( $this, 'procesar' ) );
		add_action( 'admin_notices', array( $this, 'aviso_pendiente' ) );
	}

	/**
	 * Marca que hay que redirigir al wizard (se llama desde la activación).
	 */
	public static function marcar_redireccion() {
		if ( ! get_option( self::OPT_DONE ) ) {
			set_transient( self::TRANSIENT, 1, 60 );
		}
	}

	/**
	 * Registra la página del wizard (oculta del menú).
	 */
	public function registrar_pagina() {
		add_submenu_page(
			'', // Sin padre: accesible por URL pero no aparece en el menú.
			__( 'Configuración de Multisede POS', 'multisede-pos' ),
			__( 'Asistente', 'multisede-pos' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Redirige al wizard justo después de activar el plugin.
	 */
	public function redirigir_tras_activar() {
		if ( ! get_transient( self::TRANSIENT ) ) {
			return;
		}
		delete_transient( self::TRANSIENT );

		// No redirigir en activaciones masivas ni en peticiones AJAX.
		if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&step=1' ) );
		exit;
	}

	/**
	 * Aviso para volver al wizard si quedó pendiente.
	 */
	public function aviso_pendiente() {
		if ( get_option( self::OPT_DONE ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// No mostrar el aviso dentro del propio wizard.
		if ( isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) {
			return;
		}
		$url = esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&step=1' ) );
		echo '<div class="notice notice-info"><p>';
		printf(
			/* translators: %s: enlace al asistente. */
			esc_html__( 'Multisede POS está casi listo. %s para configurar tus sedes.', 'multisede-pos' ),
			'<a href="' . $url . '"><strong>' . esc_html__( 'Abre el asistente', 'multisede-pos' ) . '</strong></a>'
		);
		echo '</p></div>';
	}

	/**
	 * Procesa los envíos del wizard (crear sede / finalizar).
	 */
	public function procesar() {
		if ( ! isset( $_POST['msp_wizard_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'msp_wizard', 'msp_wizard_nonce' );

		$accion = sanitize_key( wp_unslash( $_POST['msp_wizard_action'] ) );

		if ( 'crear_sede' === $accion ) {
			$this->crear_sede_desde_post();
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&step=2&creada=1' ) );
			exit;
		}

		if ( 'asignar_personal' === $accion ) {
			$this->asignar_personal_desde_post();
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&step=3&asignado=1' ) );
			exit;
		}

		if ( 'finalizar' === $accion ) {
			update_option( self::OPT_DONE, 1 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . MSP_Ayuda::PAGE ) );
			exit;
		}
	}

	/**
	 * Guarda las sedes de cada persona del paso "Personal".
	 */
	private function asignar_personal_desde_post() {
		// La lista de usuarios va en un campo aparte: un usuario al que se le
		// desmarcan todas las sedes no aparecería en msp_personal.
		if ( ! isset( $_POST['msp_personal_ids'] ) || ! is_array( $_POST['msp_personal_ids'] ) ) {
			return;
		}

		$ids      = array_map( 'absint', wp_unslash( $_POST['msp_personal_ids'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitized
		$personal = isset( $_POST['msp_personal'] ) ? (array) wp_unslash( $_POST['msp_personal'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitized

		foreach ( $ids as $user_id ) {
			if ( ! $user_id ) {
				continue;
			}
			$sedes = isset( $personal[ $user_id ] ) ? (array) $personal[ $user_id ] : array();
			MSP_Roles::set_sedes_de_usuario( $user_id, $sedes );
		}
	}

	/**
	 * Crea una sede a partir de los datos del formulario.
	 */
	private function crear_sede_desde_post() {
		$nombre = isset( $_POST['msp_nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['msp_nombre'] ) ) : '';
		if ( '' === $nombre ) {
			return;
		}

		$sede_id = wp_insert_post(
			array(
				'post_type'   => MSP_Sedes::CPT,
				'post_title'  => $nombre,
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $sede_id ) || ! $sede_id ) {
			return;
		}

		update_post_meta( $sede_id, '_msp_direccion', isset( $_POST['msp_direccion'] ) ? sanitize_text_field( wp_unslash( $_POST['msp_direccion'] ) ) : '' );
		update_post_meta( $sede_id, '_msp_horario', isset( $_POST['msp_horario'] ) ? sanitize_text_field( wp_unslash( $_POST['msp_horario'] ) ) : '' );

		foreach ( array( 'vende_web', 'vende_mostrador', 'es_virtual', 'activa' ) as $check ) {
			update_post_meta( $sede_id, '_msp_' . $check, isset( $_POST[ 'msp_' . $check ] ) ? '1' : '0' );
		}
	}

	/**
	 * Renderiza el wizard según el paso.
	 */
	public function render() {
		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Asistente de Multisede POS', 'multisede-pos' ); ?></h1>
			<?php $this->barra_pasos( $step ); ?>
			<div style="max-width:720px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;margin-top:16px">
				<?php
				switch ( $step ) {
					case 2:
						$this->paso_sedes();
						break;
					case 3:
						$this->paso_personal();
						break;
					case 4:
						$this->paso_recojo();
						break;
					case 1:
					default:
						$this->paso_bienvenida();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Barra de progreso de pasos.
	 *
	 * @param int $actual Paso actual.
	 */
	private function barra_pasos( $actual ) {
		$pasos = array(
			1 => __( 'Bienvenida', 'multisede-pos' ),
			2 => __( 'Sedes', 'multisede-pos' ),
			3 => __( 'Personal', 'multisede-pos' ),
			4 => __( 'Recojo y fin', 'multisede-pos' ),
		);
		echo '<div style="display:flex;gap:8px;margin-top:12px">';
		foreach ( $pasos as $n => $label ) {
			$activo = $n === $actual;
			$color  = $activo ? '#1C8E80' : '#dcdcde';
			$texto  = $activo ? '#fff' : '#50575e';
			printf(
				'<span style="background:%1$s;color:%2$s;padding:6px 14px;border-radius:999px;font-weight:600">%3$d. %4$s</span>',
				esc_attr( $color ),
				esc_attr( $texto ),
				(int) $n,
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	/**
	 * Paso 1: bienvenida.
	 */
	private function paso_bienvenida() {
		$wc_ok = function_exists( 'WC' );
		?>
		<h2><?php esc_html_e( 'Bienvenido', 'multisede-pos' ); ?></h2>
		<p><?php esc_html_e( 'Este asistente te ayuda a configurar tus tiendas físicas y la tienda virtual.', 'multisede-pos' ); ?></p>
		<p>
			<?php if ( $wc_ok ) : ?>
				<span style="color:#1C8E80;font-weight:600">✓ <?php esc_html_e( 'WooCommerce está activo.', 'multisede-pos' ); ?></span>
			<?php else : ?>
				<span style="color:#d63638;font-weight:600">✗ <?php esc_html_e( 'WooCommerce no está activo. Actívalo antes de continuar.', 'multisede-pos' ); ?></span>
			<?php endif; ?>
		</p>
		<p style="margin-top:24px">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&step=2' ) ); ?>" class="button button-primary button-hero">
				<?php esc_html_e( 'Empezar →', 'multisede-pos' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Paso 2: alta de sedes.
	 */
	private function paso_sedes() {
		$sedes = get_posts(
			array(
				'post_type'      => MSP_Sedes::CPT,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		if ( isset( $_GET['creada'] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Sede creada. Puedes añadir otra o continuar.', 'multisede-pos' ) . '</p></div>';
		}
		?>
		<h2><?php esc_html_e( 'Tus sedes', 'multisede-pos' ); ?></h2>

		<?php if ( $sedes ) : ?>
			<ul style="margin:0 0 20px;padding:0;list-style:none">
				<?php foreach ( $sedes as $sede ) : ?>
					<?php
					$virtual   = '1' === get_post_meta( $sede->ID, '_msp_es_virtual', true );
					$mostrador = '1' === get_post_meta( $sede->ID, '_msp_vende_mostrador', true );
					$web       = '1' === get_post_meta( $sede->ID, '_msp_vende_web', true );
					$tags      = array();
					if ( $virtual ) {
						$tags[] = __( 'Virtual', 'multisede-pos' );
					}
					if ( $web ) {
						$tags[] = __( 'Web/recojo', 'multisede-pos' );
					}
					if ( $mostrador ) {
						$tags[] = __( 'Mostrador', 'multisede-pos' );
					}
					?>
					<li style="padding:10px 12px;border:1px solid #f0f0f1;border-radius:6px;margin-bottom:8px">
						<strong><?php echo esc_html( $sede->post_title ); ?></strong>
						<span style="color:#787c82"> — <?php echo esc_html( $tags ? implode( ', ', $tags ) : '—' ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Añadir una sede', 'multisede-pos' ); ?></h3>
		<form method="post" action="">
			<?php wp_nonce_field( 'msp_wizard', 'msp_wizard_nonce' ); ?>
			<input type="hidden" name="msp_wizard_action" value="crear_sede" />
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="msp_nombre"><?php esc_html_e( 'Nombre', 'multisede-pos' ); ?></label></th>
					<td><input name="msp_nombre" id="msp_nombre" type="text" class="regular-text" required
						placeholder="<?php esc_attr_e( 'Ej: Tienda Miraflores', 'multisede-pos' ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="msp_direccion"><?php esc_html_e( 'Dirección', 'multisede-pos' ); ?></label></th>
					<td><input name="msp_direccion" id="msp_direccion" type="text" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="msp_horario"><?php esc_html_e( 'Horario', 'multisede-pos' ); ?></label></th>
					<td><input name="msp_horario" id="msp_horario" type="text" class="regular-text"
						placeholder="<?php esc_attr_e( 'Ej: Lun a Sáb 9:00 a 18:00', 'multisede-pos' ); ?>" /></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Canales', 'multisede-pos' ); ?></th>
					<td>
						<label><input type="checkbox" name="msp_vende_mostrador" value="1" checked /> <?php esc_html_e( 'Vende en mostrador (POS)', 'multisede-pos' ); ?></label><br>
						<label><input type="checkbox" name="msp_vende_web" value="1" /> <?php esc_html_e( 'Surte pedidos web con recojo', 'multisede-pos' ); ?></label><br>
						<label><input type="checkbox" name="msp_es_virtual" value="1" /> <?php esc_html_e( 'Es la tienda virtual', 'multisede-pos' ); ?></label>
					</td>
				</tr>
			</table>
			<input type="hidden" name="msp_activa" value="1" />
			<p><button type="submit" class="button button-secondary"><?php esc_html_e( '+ Guardar y añadir otra', 'multisede-pos' ); ?></button></p>
		</form>

		<hr style="margin:24px 0">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&step=3' ) ); ?>" class="button button-primary">
			<?php esc_html_e( 'Continuar →', 'multisede-pos' ); ?>
		</a>
		<?php
	}

	/**
	 * Paso 3: personal — asignar cada cajero/gerente a sus sedes.
	 */
	private function paso_personal() {
		$sedes = MSP_Sedes::obtener_sedes_activas();

		$personal = get_users(
			array(
				'role__in' => array( 'msp_cajero', 'msp_gerente_sede' ),
				'orderby'  => 'display_name',
			)
		);

		if ( isset( $_GET['asignado'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success inline"><p>' .
				esc_html__( 'Sedes asignadas.', 'multisede-pos' ) . '</p></div>';
		}
		?>
		<h2><?php esc_html_e( 'Quién trabaja en cada sede', 'multisede-pos' ); ?></h2>
		<p><?php esc_html_e( 'El POS y la Caja solo muestran las sedes de cada persona. Quien no tenga sede asignada entrará y no verá nada que hacer.', 'multisede-pos' ); ?></p>

		<?php if ( empty( $sedes ) ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'Primero crea al menos una sede en el paso anterior.', 'multisede-pos' ); ?>
			</p></div>

		<?php elseif ( empty( $personal ) ) : ?>
			<div class="notice notice-info inline"><p>
				<?php
				printf(
					/* translators: %s: enlace para crear usuarios. */
					esc_html__( 'Todavía no hay nadie con el rol "Cajero" o "Gerente de sede". Créalos en %s y vuelve aquí para asignarles su tienda.', 'multisede-pos' ),
					'<a href="' . esc_url( admin_url( 'user-new.php' ) ) . '" target="_blank"><strong>' .
						esc_html__( 'Usuarios → Añadir nuevo', 'multisede-pos' ) . '</strong></a>'
				);
				?>
			</p></div>

		<?php else : ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'msp_wizard', 'msp_wizard_nonce' ); ?>
				<input type="hidden" name="msp_wizard_action" value="asignar_personal" />

				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Persona', 'multisede-pos' ); ?></th>
							<th><?php esc_html_e( 'Sedes donde trabaja', 'multisede-pos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $personal as $user ) : ?>
							<?php $suyas = MSP_Roles::sedes_de_usuario( $user->ID ); ?>
							<tr>
								<td>
									<input type="hidden" name="msp_personal_ids[]" value="<?php echo esc_attr( $user->ID ); ?>" />
									<strong><?php echo esc_html( $user->display_name ); ?></strong><br>
									<span style="color:#787c82">
										<?php
										echo esc_html(
											in_array( 'msp_gerente_sede', (array) $user->roles, true )
												? __( 'Gerente de sede', 'multisede-pos' )
												: __( 'Cajero', 'multisede-pos' )
										);
										?>
									</span>
								</td>
								<td>
									<?php foreach ( MSP_Roles::sedes_para_asignar( $user->ID ) as $sede ) : ?>
										<label style="display:inline-block;margin:0 16px 6px 0">
											<input type="checkbox"
												name="msp_personal[<?php echo esc_attr( $user->ID ); ?>][]"
												value="<?php echo esc_attr( $sede['id'] ); ?>"
												<?php checked( in_array( $sede['id'], $suyas, true ) ); ?> />
											<?php echo esc_html( $sede['titulo'] ); ?>
											<?php if ( $sede['inactiva'] ) : ?>
												<span style="color:#996800">(<?php esc_html_e( 'inactiva', 'multisede-pos' ); ?>)</span>
											<?php endif; ?>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p style="margin-top:16px">
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Guardar asignaciones', 'multisede-pos' ); ?></button>
				</p>
			</form>
		<?php endif; ?>

		<p style="color:#787c82">
			<?php esc_html_e( 'Esto también se puede cambiar después desde el perfil de cada usuario.', 'multisede-pos' ); ?>
		</p>

		<hr style="margin:24px 0">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&step=4' ) ); ?>" class="button button-primary">
			<?php esc_html_e( 'Continuar →', 'multisede-pos' ); ?>
		</a>
		<?php
	}

	/**
	 * Paso 4: recojo en tienda y finalización.
	 */
	private function paso_recojo() {
		$lp_url = esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) );
		?>
		<h2><?php esc_html_e( 'Recojo en tienda', 'multisede-pos' ); ?></h2>
		<p><?php esc_html_e( 'El recojo en tienda usa el método "Recogida local" de WooCommerce. Por ahora no se ofrece delivery por la web.', 'multisede-pos' ); ?></p>
		<ol>
			<li><?php
				printf(
					/* translators: %s: enlace a ajustes de envío. */
					esc_html__( 'Ve a %s y activa "Recogida local" (Local Pickup).', 'multisede-pos' ),
					'<a href="' . $lp_url . '" target="_blank">' . esc_html__( 'WooCommerce → Ajustes → Envío', 'multisede-pos' ) . '</a>'
				);
			?></li>
			<li><?php esc_html_e( 'Desactiva los demás métodos de envío si solo quieres recojo.', 'multisede-pos' ); ?></li>
			<li><?php
				printf(
					/* translators: %s: shortcode del selector de tienda. */
					esc_html__( 'Coloca el selector de tienda en la web con el shortcode %s. El cliente elige su tienda y desde ese momento solo ve el stock de esa sede; en el checkout, su pedido queda fijado a esa sede de recojo.', 'multisede-pos' ),
					'<code>[msp_selector_sede]</code>'
				);
			?></li>
		</ol>

		<h3 style="margin-top:24px"><?php esc_html_e( 'Falta solo el stock', 'multisede-pos' ); ?></h3>
		<p><?php
			printf(
				/* translators: %s: enlace a la pantalla de inventario. */
				esc_html__( 'Carga las existencias de cada sede en %s. Hasta que lo hagas no se podrá vender nada: todo figura en cero.', 'multisede-pos' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=' . MSP_Inventario::PAGE ) ) . '"><strong>' .
					esc_html__( 'Inventario', 'multisede-pos' ) . '</strong></a>'
			);
		?></p>

		<form method="post" action="" style="margin-top:24px">
			<?php wp_nonce_field( 'msp_wizard', 'msp_wizard_nonce' ); ?>
			<input type="hidden" name="msp_wizard_action" value="finalizar" />
			<button type="submit" class="button button-primary button-hero"><?php esc_html_e( '✓ Finalizar y ver cómo se usa', 'multisede-pos' ); ?></button>
		</form>
		<p style="color:#787c82;margin-top:8px">
			<?php esc_html_e( 'Al finalizar te llevamos a la página de Ayuda, donde están explicados los flujos del día a día: abrir caja, vender en mostrador, entregar un pedido web y cerrar caja con arqueo.', 'multisede-pos' ); ?>
		</p>
		<?php
	}
}
