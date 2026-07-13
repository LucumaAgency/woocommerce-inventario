<?php
/**
 * Roles, capacidades y asignación de sedes a los usuarios.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestiona los roles personalizados: gerente de sede y cajero.
 */
class MSP_Roles {

	/**
	 * Versión de los roles. Al subirla, los roles se vuelven a crear en la
	 * siguiente carga del admin (las actualizaciones por Git Updater no
	 * disparan el hook de activación).
	 */
	const ROLES_VERSION = '2';

	/**
	 * Capacidades propias del plugin.
	 *
	 * @return array
	 */
	public static function capacidades() {
		return array(
			'msp_gestionar_sedes',     // Crear/editar sedes (admin).
			'msp_gestionar_stock',     // Ajustar stock por sede.
			'msp_ver_stock',           // Ver inventario de su sede.
			'msp_usar_pos',            // Vender en mostrador.
			'msp_gestionar_caja',      // Abrir/cerrar caja, arqueo.
			'msp_ver_reportes',        // Reportes por sede.
		);
	}

	/**
	 * Engancha los hooks del módulo.
	 */
	public function init() {
		// Mantiene los roles al día tras una actualización del plugin.
		// En 'init' con prioridad 1: antes de que se registre el CPT de sedes
		// (que exige msp_gestionar_sedes) y antes de que se construya el menú,
		// o una capacidad nueva se evaluaría con los roles viejos y la pantalla
		// desaparecería durante esa primera carga.
		add_action( 'init', array( __CLASS__, 'migrar_roles' ), 1 );

		// Campo "Sedes asignadas" en el perfil del usuario.
		add_action( 'show_user_profile', array( $this, 'campo_sedes_usuario' ) );
		add_action( 'edit_user_profile', array( $this, 'campo_sedes_usuario' ) );
		add_action( 'personal_options_update', array( $this, 'guardar_sedes_usuario' ) );
		add_action( 'edit_user_profile_update', array( $this, 'guardar_sedes_usuario' ) );

		// Asignación de sedes también al crear el usuario.
		add_action( 'user_new_form', array( $this, 'campo_sedes_nuevo_usuario' ) );
		add_action( 'user_register', array( $this, 'guardar_sedes_usuario' ) );

		// Columna "Sedes" en el listado de usuarios.
		add_filter( 'manage_users_columns', array( $this, 'columna_usuarios' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'columna_usuarios_contenido' ), 10, 3 );
	}

	/**
	 * Recrea los roles si cambió su definición.
	 */
	public static function migrar_roles() {
		if ( get_option( 'msp_roles_version' ) === self::ROLES_VERSION ) {
			return;
		}

		// Escribir roles es cosa del panel: que no lo dispare la visita de un
		// cliente a la tienda ni una petición AJAX de un cajero.
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		self::crear_roles();
		update_option( 'msp_roles_version', self::ROLES_VERSION );
	}

	/**
	 * Crea (o actualiza) los roles del plugin.
	 */
	public static function crear_roles() {
		// Gerente de sede: inventario, POS, caja y reportes de su sede.
		$gerente = array(
			'read'                => true,
			'msp_ver_stock'       => true,
			'msp_gestionar_stock' => true,
			'msp_usar_pos'        => true,
			'msp_gestionar_caja'  => true,
			'msp_ver_reportes'    => true,
		);

		// Cajero: POS y su caja. Ve el stock, no lo ajusta.
		$cajero = array(
			'read'               => true,
			'msp_ver_stock'      => true,
			'msp_usar_pos'       => true,
			'msp_gestionar_caja' => true,
		);

		// add_role() no hace nada si el rol ya existe: para poder cambiar las
		// capacidades entre versiones, se recrea.
		remove_role( 'msp_gerente_sede' );
		remove_role( 'msp_cajero' );
		add_role( 'msp_gerente_sede', __( 'Gerente de sede', 'multisede-pos' ), $gerente );
		add_role( 'msp_cajero', __( 'Cajero', 'multisede-pos' ), $cajero );

		// El administrador recibe todas las capacidades.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::capacidades() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Elimina los roles al desinstalar.
	 */
	public static function eliminar_roles() {
		remove_role( 'msp_gerente_sede' );
		remove_role( 'msp_cajero' );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::capacidades() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Relación usuario ↔ sede
	 * ------------------------------------------------------------------- */

	/**
	 * Devuelve las sedes asignadas a un usuario.
	 *
	 * @param int $user_id ID de usuario.
	 * @return int[] Lista de IDs de sede.
	 */
	public static function sedes_de_usuario( $user_id ) {
		$sedes = get_user_meta( $user_id, '_msp_sedes', true );
		return is_array( $sedes ) ? array_map( 'absint', $sedes ) : array();
	}

	/**
	 * Guarda las sedes de un usuario.
	 *
	 * @param int   $user_id ID de usuario.
	 * @param int[] $sedes   IDs de sede.
	 */
	public static function set_sedes_de_usuario( $user_id, $sedes ) {
		$sedes = array_values( array_unique( array_filter( array_map( 'absint', (array) $sedes ) ) ) );
		update_user_meta( $user_id, '_msp_sedes', $sedes );
	}

	/**
	 * Sedes que hay que mostrar al asignar personal a un usuario.
	 *
	 * Son las sedes activas MÁS las que el usuario ya tenga asignadas aunque
	 * estén inactivas: si solo pintáramos las activas, desactivar una sede y
	 * guardar el perfil por cualquier otro motivo borraría esa asignación sin
	 * que nadie lo pidiera, y no volvería al reactivar la sede.
	 *
	 * @param int $user_id Usuario.
	 * @return array<int,array{id:int,titulo:string,inactiva:bool}>
	 */
	public static function sedes_para_asignar( $user_id ) {
		$lista = array();

		foreach ( MSP_Sedes::obtener_sedes_activas() as $sede ) {
			$lista[ (int) $sede->ID ] = array(
				'id'       => (int) $sede->ID,
				'titulo'   => $sede->post_title,
				'inactiva' => false,
			);
		}

		foreach ( self::sedes_de_usuario( $user_id ) as $sede_id ) {
			if ( isset( $lista[ $sede_id ] ) ) {
				continue;
			}
			$post = get_post( $sede_id );
			if ( ! $post || MSP_Sedes::CPT !== $post->post_type ) {
				continue; // La sede se borró: la asignación ya no significa nada.
			}
			$lista[ $sede_id ] = array(
				'id'       => $sede_id,
				'titulo'   => $post->post_title,
				'inactiva' => true,
			);
		}

		return $lista;
	}

	/**
	 * ¿Tiene el usuario acceso a esta sede?
	 *
	 * El administrador tiene acceso a todas.
	 *
	 * @param int $sede_id Sede.
	 * @param int $user_id Usuario (por defecto, el actual).
	 * @return bool
	 */
	public static function puede_usuario_sede( $sede_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		return in_array( (int) $sede_id, self::sedes_de_usuario( $user_id ), true );
	}

	/* ---------------------------------------------------------------------
	 * UI en el perfil de usuario
	 * ------------------------------------------------------------------- */

	/**
	 * ¿Este usuario trabaja en sedes (cajero, gerente o admin)?
	 *
	 * @param WP_User $user Usuario.
	 * @return bool
	 */
	private function es_personal( $user ) {
		return $user->has_cap( 'msp_usar_pos' ) ||
			$user->has_cap( 'msp_gestionar_caja' ) ||
			$user->has_cap( 'msp_ver_stock' );
	}

	/**
	 * Pinta el selector de sedes en el perfil del usuario.
	 *
	 * @param WP_User $user Usuario que se está editando.
	 */
	public function campo_sedes_usuario( $user ) {
		// Solo el administrador reparte sedes.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$sedes = self::sedes_para_asignar( $user->ID );
		$mias  = self::sedes_de_usuario( $user->ID );
		?>
		<h2><?php esc_html_e( 'Multisede POS', 'multisede-pos' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label><?php esc_html_e( 'Sedes asignadas', 'multisede-pos' ); ?></label></th>
				<td>
					<?php if ( empty( $sedes ) ) : ?>
						<p><?php esc_html_e( 'Todavía no hay sedes activas. Crea una en el menú "Sedes".', 'multisede-pos' ); ?></p>
					<?php else : ?>
						<?php wp_nonce_field( 'msp_sedes_usuario', 'msp_sedes_usuario_nonce' ); ?>
						<fieldset>
							<?php foreach ( $sedes as $sede ) : ?>
								<label style="display:block;margin-bottom:6px">
									<input type="checkbox" name="msp_sedes[]" value="<?php echo esc_attr( $sede['id'] ); ?>"
										<?php checked( in_array( $sede['id'], $mias, true ) ); ?> />
									<?php echo esc_html( $sede['titulo'] ); ?>
									<?php if ( $sede['inactiva'] ) : ?>
										<span style="color:#996800">(<?php esc_html_e( 'sede inactiva', 'multisede-pos' ); ?>)</span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'El POS y la Caja solo muestran las sedes marcadas aquí. Sin ninguna marcada, esta persona no podrá vender ni abrir caja (el administrador siempre ve todas).', 'multisede-pos' ); ?>
						</p>
						<?php if ( ! $this->es_personal( $user ) ) : ?>
							<p class="description" style="color:#b32d2e">
								<?php esc_html_e( 'Ojo: este usuario no tiene el rol de Cajero ni de Gerente de sede, así que no verá el POS ni la Caja aunque le asignes sedes.', 'multisede-pos' ); ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Selector de sedes en el formulario de "Añadir nuevo usuario".
	 *
	 * @param string $tipo Contexto del formulario ('add-new-user' | 'add-existing-user').
	 */
	public function campo_sedes_nuevo_usuario( $tipo ) {
		if ( 'add-new-user' !== $tipo || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$sedes = MSP_Sedes::obtener_sedes_activas();
		if ( empty( $sedes ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Multisede POS', 'multisede-pos' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label><?php esc_html_e( 'Sedes asignadas', 'multisede-pos' ); ?></label></th>
				<td>
					<?php wp_nonce_field( 'msp_sedes_usuario', 'msp_sedes_usuario_nonce' ); ?>
					<fieldset>
						<?php foreach ( $sedes as $sede ) : ?>
							<label style="display:block;margin-bottom:6px">
								<input type="checkbox" name="msp_sedes[]" value="<?php echo esc_attr( $sede->ID ); ?>" />
								<?php echo esc_html( $sede->post_title ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description">
						<?php esc_html_e( 'Solo aplica si le das el rol de Cajero o Gerente de sede.', 'multisede-pos' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Guarda las sedes elegidas en el perfil (o al crear el usuario).
	 *
	 * @param int $user_id Usuario editado.
	 */
	public function guardar_sedes_usuario( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['msp_sedes_usuario_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['msp_sedes_usuario_nonce'] ), 'msp_sedes_usuario' ) ) {
			return;
		}

		$sedes = isset( $_POST['msp_sedes'] ) ? (array) wp_unslash( $_POST['msp_sedes'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitized
		self::set_sedes_de_usuario( $user_id, $sedes );
	}

	/* ---------------------------------------------------------------------
	 * Columna en el listado de usuarios
	 * ------------------------------------------------------------------- */

	/**
	 * Añade la columna "Sedes".
	 *
	 * @param array $columns Columnas.
	 * @return array
	 */
	public function columna_usuarios( $columns ) {
		$columns['msp_sedes'] = __( 'Sedes', 'multisede-pos' );
		return $columns;
	}

	/**
	 * Contenido de la columna "Sedes".
	 *
	 * @param string $salida  Contenido actual.
	 * @param string $columna Columna.
	 * @param int    $user_id Usuario.
	 * @return string
	 */
	public function columna_usuarios_contenido( $salida, $columna, $user_id ) {
		if ( 'msp_sedes' !== $columna ) {
			return $salida;
		}

		$user = get_userdata( $user_id );
		if ( $user && user_can( $user, 'manage_options' ) ) {
			return '<span style="color:#787c82">' . esc_html__( 'Todas (admin)', 'multisede-pos' ) . '</span>';
		}

		// Solo tiene sentido para quien usa POS, caja o inventario.
		if ( ! $user || ! $this->es_personal( $user ) ) {
			return '<span style="color:#999">—</span>';
		}

		$sedes = self::sedes_de_usuario( $user_id );
		if ( empty( $sedes ) ) {
			return '<span style="color:#b32d2e">' . esc_html__( 'Sin asignar', 'multisede-pos' ) . '</span>';
		}

		$nombres = array_filter( array_map( 'get_the_title', $sedes ) );
		return esc_html( implode( ', ', $nombres ) );
	}
}
