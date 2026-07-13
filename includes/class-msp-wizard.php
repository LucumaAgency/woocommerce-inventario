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

		if ( 0 === strpos( $accion, 'practica_' ) ) {
			$this->procesar_practica( $accion );
			exit;
		}

		if ( 'finalizar' === $accion ) {
			update_option( self::OPT_DONE, 1 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . MSP_Ayuda::PAGE ) );
			exit;
		}
	}

	/**
	 * Procesa el turno de caja de práctica (paso 5).
	 *
	 * Usa las mismas funciones que la caja real, pero sobre una sesión marcada
	 * como práctica: no aparece en el reporte de arqueos, no recibe el efectivo
	 * de las ventas del POS y se puede borrar de un clic.
	 *
	 * @param string $accion Acción del formulario.
	 */
	private function procesar_practica( $accion ) {
		$sede_id   = isset( $_POST['sede'] ) ? absint( wp_unslash( $_POST['sede'] ) ) : 0;
		$cajero_id = get_current_user_id();

		// La sede tiene que ser una sede real de mostrador: es la única acción
		// del plugin que escribe en la tabla de cajas, no la dejamos apuntar a
		// un ID de post cualquiera.
		if ( ! $sede_id || ! $this->es_sede_con_caja( $sede_id ) ) {
			wp_safe_redirect( $this->url_practica( 0 ) );
			return;
		}

		if ( 'practica_abrir' === $accion ) {
			$apertura = isset( $_POST['monto_apertura'] ) ? (float) wp_unslash( $_POST['monto_apertura'] ) : 0;
			MSP_Caja::abrir( $sede_id, $cajero_id, $apertura, true );

		} elseif ( 'practica_mov' === $accion ) {
			$sesion = MSP_Caja::sesion_practica_abierta( $sede_id, $cajero_id );
			if ( $sesion ) {
				$tipo     = isset( $_POST['tipo'] ) && 'egreso' === $_POST['tipo'] ? 'egreso' : 'ingreso';
				$concepto = isset( $_POST['concepto'] ) ? sanitize_text_field( wp_unslash( $_POST['concepto'] ) ) : '';
				$monto    = isset( $_POST['monto'] ) ? (float) wp_unslash( $_POST['monto'] ) : 0;
				if ( $monto > 0 ) {
					MSP_Caja::agregar_movimiento( $sesion->id, $tipo, $concepto, $monto );
				}
			}
		} elseif ( 'practica_cerrar' === $accion ) {
			$sesion = MSP_Caja::sesion_practica_abierta( $sede_id, $cajero_id );
			if ( $sesion ) {
				$contado = isset( $_POST['monto_contado'] ) ? (float) wp_unslash( $_POST['monto_contado'] ) : 0;
				MSP_Caja::cerrar( $sesion, $contado );
			}
		} elseif ( 'practica_descartar' === $accion ) {
			$sesion_id = isset( $_POST['sesion'] ) ? absint( wp_unslash( $_POST['sesion'] ) ) : 0;
			if ( $sesion_id ) {
				MSP_Caja::descartar_practica( $sesion_id, $cajero_id );
			}
		}

		wp_safe_redirect( $this->url_practica( $sede_id ) );
	}

	/**
	 * Sedes activas que venden en mostrador (las que tienen caja).
	 *
	 * @return WP_Post[]
	 */
	private function sedes_con_caja() {
		return array_values(
			array_filter(
				MSP_Sedes::obtener_sedes_activas(),
				function ( $sede ) {
					return '1' === get_post_meta( $sede->ID, '_msp_vende_mostrador', true );
				}
			)
		);
	}

	/**
	 * ¿Es esa sede una sede activa con caja?
	 *
	 * @param int $sede_id Sede.
	 * @return bool
	 */
	private function es_sede_con_caja( $sede_id ) {
		foreach ( $this->sedes_con_caja() as $sede ) {
			if ( (int) $sede->ID === (int) $sede_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * URL del paso de práctica para una sede.
	 *
	 * @param int $sede_id Sede.
	 * @return string
	 */
	private function url_practica( $sede_id ) {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'step' => 5,
				'sede' => $sede_id,
			),
			admin_url( 'admin.php' )
		);
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
					case 5:
						$this->paso_practica();
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
			4 => __( 'Recojo', 'multisede-pos' ),
			5 => __( 'Practicar caja', 'multisede-pos' ),
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
	 * Paso 5: practicar un turno de caja completo (apertura → movimiento → arqueo).
	 */
	private function paso_practica() {
		$cajero_id = get_current_user_id();

		// Solo sedes de mostrador: son las que tienen caja.
		$sedes = $this->sedes_con_caja();

		echo '<h2>' . esc_html__( 'Practica un turno de caja', 'multisede-pos' ) . '</h2>';
		echo '<p>' . esc_html__( 'Vamos a abrir una caja, registrar un gasto y cerrarla con arqueo, igual que hará el cajero cada día. Es una caja de práctica de verdad, pero marcada como tal: no entra en el reporte de arqueos, no recibe el efectivo de las ventas del POS y la puedes borrar al terminar.', 'multisede-pos' ) . '</p>';

		if ( empty( $sedes ) ) {
			echo '<div class="notice notice-warning inline"><p>' .
				esc_html__( 'No hay ninguna sede que venda en mostrador, así que no hay caja que practicar. Marca "Vende en mostrador (POS)" en alguna de tus sedes.', 'multisede-pos' ) .
				'</p></div>';
			$this->boton_finalizar();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sede_id = isset( $_GET['sede'] ) ? absint( wp_unslash( $_GET['sede'] ) ) : 0;
		if ( ! $sede_id || ! $this->es_sede_con_caja( $sede_id ) ) {
			$sede_id = (int) $sedes[0]->ID;
		}

		// Selector de sede.
		echo '<form method="get" style="margin:16px 0">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		echo '<input type="hidden" name="step" value="5" />';
		echo '<label><strong>' . esc_html__( 'Sede:', 'multisede-pos' ) . '</strong> <select name="sede" onchange="this.form.submit()">';
		foreach ( $sedes as $sede ) {
			echo '<option value="' . esc_attr( $sede->ID ) . '" ' . selected( $sede->ID, $sede_id, false ) . '>' .
				esc_html( $sede->post_title ) . '</option>';
		}
		echo '</select></label></form>';

		$abierta = MSP_Caja::sesion_practica_abierta( $sede_id, $cajero_id );

		if ( $abierta ) {
			$this->practica_en_curso( $abierta, $sede_id );
		} else {
			$ultima = MSP_Caja::ultima_practica( $sede_id, $cajero_id );
			if ( $ultima && 'cerrada' === $ultima->estado ) {
				$this->practica_cerrada( $ultima, $sede_id );
			} else {
				$this->practica_abrir( $sede_id );
			}
		}

		$this->boton_finalizar();
	}

	/**
	 * Práctica, paso 1: abrir la caja.
	 *
	 * @param int $sede_id Sede.
	 */
	private function practica_abrir( $sede_id ) {
		?>
		<div style="border:1px solid #dcdcde;border-left:4px solid #1C8E80;border-radius:6px;padding:16px 20px">
			<h3 style="margin-top:0"><?php esc_html_e( 'Paso 1 de 3 — Abrir la caja', 'multisede-pos' ); ?></h3>
			<p><?php esc_html_e( 'Al empezar el turno, el cajero cuenta el efectivo con el que arranca y lo registra. Ese es el monto de apertura.', 'multisede-pos' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'msp_wizard', 'msp_wizard_nonce' ); ?>
				<input type="hidden" name="msp_wizard_action" value="practica_abrir" />
				<input type="hidden" name="sede" value="<?php echo esc_attr( $sede_id ); ?>" />
				<p>
					<label><?php esc_html_e( 'Monto de apertura', 'multisede-pos' ); ?><br>
						<input type="number" step="0.01" min="0" name="monto_apertura" value="100" required class="regular-text" />
					</label>
				</p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Abrir caja de práctica', 'multisede-pos' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Práctica, pasos 2 y 3: movimientos y arqueo.
	 *
	 * @param object $sesion  Sesión de práctica abierta.
	 * @param int    $sede_id Sede.
	 */
	private function practica_en_curso( $sesion, $sede_id ) {
		$totales  = MSP_Caja::totales( $sesion->id );
		$esperado = MSP_Caja::esperado( $sesion );
		$movs     = MSP_Caja::movimientos( $sesion->id );
		?>
		<div style="border:1px solid #dcdcde;border-left:4px solid #1C8E80;border-radius:6px;padding:16px 20px;margin-bottom:16px">
			<h3 style="margin-top:0"><?php esc_html_e( 'Paso 2 de 3 — Registrar un movimiento', 'multisede-pos' ); ?></h3>
			<p><?php esc_html_e( 'Durante el turno entra y sale plata del cajón que no son ventas: un gasto pagado del cajón es un egreso, plata que se mete es un ingreso. Registra uno de prueba, por ejemplo un taxi de 20.', 'multisede-pos' ); ?></p>
			<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
				<?php wp_nonce_field( 'msp_wizard', 'msp_wizard_nonce' ); ?>
				<input type="hidden" name="msp_wizard_action" value="practica_mov" />
				<input type="hidden" name="sede" value="<?php echo esc_attr( $sede_id ); ?>" />
				<select name="tipo">
					<option value="egreso"><?php esc_html_e( 'Egreso (gasto)', 'multisede-pos' ); ?></option>
					<option value="ingreso"><?php esc_html_e( 'Ingreso', 'multisede-pos' ); ?></option>
				</select>
				<input type="text" name="concepto" value="<?php esc_attr_e( 'Taxi', 'multisede-pos' ); ?>" />
				<input type="number" step="0.01" min="0" name="monto" value="20" required style="width:110px" />
				<button type="submit" class="button"><?php esc_html_e( 'Registrar', 'multisede-pos' ); ?></button>
			</form>

			<?php if ( $movs ) : ?>
				<table class="widefat striped" style="margin-top:14px">
					<tbody>
						<?php foreach ( $movs as $m ) : ?>
							<tr>
								<td><?php echo esc_html( $m->concepto ); ?></td>
								<td style="width:120px">
									<?php echo esc_html( 'egreso' === $m->tipo ? '−' : '+' ); ?><?php echo wp_kses_post( wc_price( $m->monto ) ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div style="border:1px solid #dcdcde;border-left:4px solid #1C8E80;border-radius:6px;padding:16px 20px">
			<h3 style="margin-top:0"><?php esc_html_e( 'Paso 3 de 3 — Cerrar con arqueo', 'multisede-pos' ); ?></h3>

			<table class="widefat striped" style="max-width:420px;margin-bottom:14px">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Apertura', 'multisede-pos' ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $sesion->monto_apertura ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Ventas en efectivo', 'multisede-pos' ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $totales['ventas'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Otros ingresos', 'multisede-pos' ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $totales['ingresos'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Egresos', 'multisede-pos' ); ?></td>
						<td>−<?php echo wp_kses_post( wc_price( $totales['egresos'] ) ); ?></td>
					</tr>
					<tr style="font-weight:700">
						<td><?php esc_html_e( 'Efectivo esperado', 'multisede-pos' ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $esperado ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<p><?php esc_html_e( 'Eso es lo que el sistema calcula que debería haber en el cajón. Ahora el cajero lo cuenta de verdad y escribe lo que encontró. Escribe un monto distinto a propósito para ver qué es el arqueo.', 'multisede-pos' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'msp_wizard', 'msp_wizard_nonce' ); ?>
				<input type="hidden" name="msp_wizard_action" value="practica_cerrar" />
				<input type="hidden" name="sede" value="<?php echo esc_attr( $sede_id ); ?>" />
				<p>
					<label><?php esc_html_e( 'Efectivo contado', 'multisede-pos' ); ?><br>
						<input type="number" step="0.01" min="0" name="monto_contado"
							value="<?php echo esc_attr( max( 0, round( $esperado - 2, 2 ) ) ); ?>" required class="regular-text" />
					</label>
				</p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Cerrar caja de práctica', 'multisede-pos' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Práctica terminada: se muestra el arqueo y se ofrece borrarla.
	 *
	 * @param object $sesion  Sesión cerrada.
	 * @param int    $sede_id Sede.
	 */
	private function practica_cerrada( $sesion, $sede_id ) {
		$dif     = (float) $sesion->diferencia;
		$cuadra  = 0 === (int) round( $dif * 100 );
		$color   = $cuadra ? '#1C8E80' : ( $dif < 0 ? '#b32d2e' : '#996800' );
		?>
		<div style="border:1px solid #dcdcde;border-left:4px solid <?php echo esc_attr( $color ); ?>;border-radius:6px;padding:16px 20px">
			<h3 style="margin-top:0"><?php esc_html_e( '✓ Turno cerrado. Esto es el arqueo', 'multisede-pos' ); ?></h3>

			<table class="widefat striped" style="max-width:420px;margin-bottom:14px">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Efectivo esperado', 'multisede-pos' ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $sesion->monto_cierre_esperado ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Efectivo contado', 'multisede-pos' ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $sesion->monto_cierre_contado ) ); ?></td>
					</tr>
					<tr style="font-weight:700;color:<?php echo esc_attr( $color ); ?>">
						<td><?php esc_html_e( 'Diferencia', 'multisede-pos' ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $dif ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( $cuadra ) : ?>
				<p><?php esc_html_e( 'La caja cuadró: lo contado coincide con lo esperado.', 'multisede-pos' ); ?></p>
			<?php elseif ( $dif < 0 ) : ?>
				<p><?php esc_html_e( 'Faltó dinero en el cajón: se contó menos de lo esperado. Eso es un faltante.', 'multisede-pos' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Sobró dinero en el cajón: se contó más de lo esperado. Suele ser un vuelto mal dado o un movimiento sin registrar.', 'multisede-pos' ); ?></p>
			<?php endif; ?>

			<p><?php esc_html_e( 'Lo importante no es que la diferencia dé cero, sino que quede registrada. En la caja real, este cierre aparecería en el historial de arqueos de la sede, con el nombre del cajero.', 'multisede-pos' ); ?></p>

			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'msp_wizard', 'msp_wizard_nonce' ); ?>
				<input type="hidden" name="msp_wizard_action" value="practica_descartar" />
				<input type="hidden" name="sede" value="<?php echo esc_attr( $sede_id ); ?>" />
				<input type="hidden" name="sesion" value="<?php echo esc_attr( $sesion->id ); ?>" />
				<button type="submit" class="button">
					<?php esc_html_e( 'Descartar la práctica y volver a empezar', 'multisede-pos' ); ?>
				</button>
			</form>
			<p class="description" style="margin-top:8px">
				<?php esc_html_e( 'Esta caja de práctica no aparece en el reporte de arqueos, así que puedes dejarla o borrarla; da igual.', 'multisede-pos' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Botón de finalización del asistente.
	 */
	private function boton_finalizar() {
		?>
		<form method="post" action="" style="margin-top:24px">
			<?php wp_nonce_field( 'msp_wizard', 'msp_wizard_nonce' ); ?>
			<input type="hidden" name="msp_wizard_action" value="finalizar" />
			<button type="submit" class="button button-primary button-hero"><?php esc_html_e( '✓ Finalizar configuración', 'multisede-pos' ); ?></button>
		</form>
		<p style="color:#787c82;margin-top:8px">
			<?php esc_html_e( 'Te llevamos a la página de Ayuda, donde quedan explicados todos los flujos del día a día: abrir caja, vender en mostrador, entregar un pedido web y cerrar caja con arqueo.', 'multisede-pos' ); ?>
		</p>
		<?php
	}

	/**
	 * Paso 4: recojo en tienda.
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

		<hr style="margin:24px 0">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&step=5' ) ); ?>" class="button button-primary">
			<?php esc_html_e( 'Continuar: practicar un turno de caja →', 'multisede-pos' ); ?>
		</a>
		<?php
	}
}
