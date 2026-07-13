<?php
/**
 * Sedes: Custom Post Type y campos de cada tienda.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra y gestiona el CPT `sede`.
 */
class MSP_Sedes {

	const CPT = 'msp_sede';

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'init', array( __CLASS__, 'registrar_cpt' ) );
		add_action( 'add_meta_boxes', array( $this, 'registrar_metabox' ) );
		add_action( 'save_post_' . self::CPT, array( $this, 'guardar_meta' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( $this, 'columnas' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'columna_contenido' ), 10, 2 );
	}

	/**
	 * Registra el Custom Post Type de sedes.
	 */
	public static function registrar_cpt() {
		$labels = array(
			'name'               => __( 'Sedes', 'multisede-pos' ),
			'singular_name'      => __( 'Sede', 'multisede-pos' ),
			'menu_name'          => __( 'Sedes', 'multisede-pos' ),
			'add_new'            => __( 'Añadir sede', 'multisede-pos' ),
			'add_new_item'       => __( 'Añadir nueva sede', 'multisede-pos' ),
			'edit_item'          => __( 'Editar sede', 'multisede-pos' ),
			'new_item'           => __( 'Nueva sede', 'multisede-pos' ),
			'view_item'          => __( 'Ver sede', 'multisede-pos' ),
			'search_items'       => __( 'Buscar sedes', 'multisede-pos' ),
			'not_found'          => __( 'No se encontraron sedes', 'multisede-pos' ),
			'all_items'          => __( 'Todas las sedes', 'multisede-pos' ),
		);

		register_post_type(
			self::CPT,
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-store',
				'menu_position'       => 56,
				'supports'            => array( 'title' ),
				// Gestionar sedes requiere msp_gestionar_sedes (solo el admin la tiene).
				'capabilities'        => array(
					'edit_post'              => 'msp_gestionar_sedes',
					'read_post'              => 'msp_gestionar_sedes',
					'delete_post'            => 'msp_gestionar_sedes',
					'create_posts'           => 'msp_gestionar_sedes',
					'edit_posts'             => 'msp_gestionar_sedes',
					'edit_others_posts'      => 'msp_gestionar_sedes',
					'publish_posts'          => 'msp_gestionar_sedes',
					'read_private_posts'     => 'msp_gestionar_sedes',
					'delete_posts'           => 'msp_gestionar_sedes',
					'delete_others_posts'    => 'msp_gestionar_sedes',
					'delete_private_posts'   => 'msp_gestionar_sedes',
					'delete_published_posts' => 'msp_gestionar_sedes',
					'edit_private_posts'     => 'msp_gestionar_sedes',
					'edit_published_posts'   => 'msp_gestionar_sedes',
				),
				'map_meta_cap'        => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * Registra el metabox con los datos de la sede.
	 */
	public function registrar_metabox() {
		add_meta_box(
			'msp_sede_datos',
			__( 'Datos de la sede', 'multisede-pos' ),
			array( $this, 'render_metabox' ),
			self::CPT,
			'normal',
			'high'
		);
	}

	/**
	 * Renderiza el formulario del metabox.
	 *
	 * @param WP_Post $post Post de la sede.
	 */
	public function render_metabox( $post ) {
		wp_nonce_field( 'msp_guardar_sede', 'msp_sede_nonce' );

		$direccion       = get_post_meta( $post->ID, '_msp_direccion', true );
		$horario         = get_post_meta( $post->ID, '_msp_horario', true );
		$vende_web       = get_post_meta( $post->ID, '_msp_vende_web', true );
		$vende_mostrador = get_post_meta( $post->ID, '_msp_vende_mostrador', true );
		$es_virtual      = get_post_meta( $post->ID, '_msp_es_virtual', true );
		$activa          = get_post_meta( $post->ID, '_msp_activa', true );

		// Por defecto una sede nueva está activa y vende en mostrador.
		if ( '' === $activa && 'auto-draft' === $post->post_status ) {
			$activa          = '1';
			$vende_mostrador = '1';
		}
		?>
		<style>.msp-field{margin:0 0 14px} .msp-field label{font-weight:600;display:block;margin-bottom:4px}</style>

		<p class="msp-field">
			<label for="msp_direccion"><?php esc_html_e( 'Dirección', 'multisede-pos' ); ?></label>
			<input type="text" id="msp_direccion" name="msp_direccion" class="widefat"
				value="<?php echo esc_attr( $direccion ); ?>" />
		</p>

		<p class="msp-field">
			<label for="msp_horario"><?php esc_html_e( 'Horario de atención', 'multisede-pos' ); ?></label>
			<input type="text" id="msp_horario" name="msp_horario" class="widefat"
				value="<?php echo esc_attr( $horario ); ?>"
				placeholder="<?php esc_attr_e( 'Ej: Lun a Sáb 9:00 a 18:00', 'multisede-pos' ); ?>" />
		</p>

		<p class="msp-field">
			<label>
				<input type="checkbox" name="msp_vende_web" value="1" <?php checked( $vende_web, '1' ); ?> />
				<?php esc_html_e( 'Surte pedidos web con recojo en tienda', 'multisede-pos' ); ?>
			</label>
		</p>

		<p class="msp-field">
			<label>
				<input type="checkbox" name="msp_vende_mostrador" value="1" <?php checked( $vende_mostrador, '1' ); ?> />
				<?php esc_html_e( 'Vende en mostrador (POS)', 'multisede-pos' ); ?>
			</label>
		</p>

		<p class="msp-field">
			<label>
				<input type="checkbox" name="msp_es_virtual" value="1" <?php checked( $es_virtual, '1' ); ?> />
				<?php esc_html_e( 'Es la tienda virtual (no es una tienda física)', 'multisede-pos' ); ?>
			</label>
		</p>

		<p class="msp-field">
			<label>
				<input type="checkbox" name="msp_activa" value="1" <?php checked( $activa, '1' ); ?> />
				<?php esc_html_e( 'Sede activa', 'multisede-pos' ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Guarda los campos del metabox.
	 *
	 * @param int     $post_id ID del post.
	 * @param WP_Post $post    Objeto post.
	 */
	public function guardar_meta( $post_id, $post ) {
		// Verificaciones de seguridad.
		if ( ! isset( $_POST['msp_sede_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['msp_sede_nonce'] ), 'msp_guardar_sede' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Campos de texto.
		update_post_meta(
			$post_id,
			'_msp_direccion',
			isset( $_POST['msp_direccion'] ) ? sanitize_text_field( wp_unslash( $_POST['msp_direccion'] ) ) : ''
		);
		update_post_meta(
			$post_id,
			'_msp_horario',
			isset( $_POST['msp_horario'] ) ? sanitize_text_field( wp_unslash( $_POST['msp_horario'] ) ) : ''
		);

		// Checkboxes.
		$checks = array( 'vende_web', 'vende_mostrador', 'es_virtual', 'activa' );
		foreach ( $checks as $check ) {
			update_post_meta(
				$post_id,
				'_msp_' . $check,
				isset( $_POST[ 'msp_' . $check ] ) ? '1' : '0'
			);
		}
	}

	/**
	 * Columnas del listado de sedes.
	 *
	 * @param array $columns Columnas actuales.
	 * @return array
	 */
	public function columnas( $columns ) {
		$nuevas = array();
		foreach ( $columns as $key => $label ) {
			$nuevas[ $key ] = $label;
			if ( 'title' === $key ) {
				$nuevas['msp_direccion'] = __( 'Dirección', 'multisede-pos' );
				$nuevas['msp_canales']   = __( 'Canales', 'multisede-pos' );
				$nuevas['msp_activa']    = __( 'Estado', 'multisede-pos' );
			}
		}
		return $nuevas;
	}

	/**
	 * Contenido de las columnas personalizadas.
	 *
	 * @param string $column  Columna.
	 * @param int    $post_id ID del post.
	 */
	public function columna_contenido( $column, $post_id ) {
		switch ( $column ) {
			case 'msp_direccion':
				echo esc_html( get_post_meta( $post_id, '_msp_direccion', true ) );
				break;
			case 'msp_canales':
				$canales = array();
				if ( '1' === get_post_meta( $post_id, '_msp_vende_web', true ) ) {
					$canales[] = __( 'Web', 'multisede-pos' );
				}
				if ( '1' === get_post_meta( $post_id, '_msp_vende_mostrador', true ) ) {
					$canales[] = __( 'Mostrador', 'multisede-pos' );
				}
				echo esc_html( $canales ? implode( ' + ', $canales ) : '—' );
				break;
			case 'msp_activa':
				$activa = '1' === get_post_meta( $post_id, '_msp_activa', true );
				echo $activa
					? '<span style="color:#1C8E80;font-weight:600">' . esc_html__( 'Activa', 'multisede-pos' ) . '</span>'
					: '<span style="color:#999">' . esc_html__( 'Inactiva', 'multisede-pos' ) . '</span>';
				break;
		}
	}

	/**
	 * Helper: devuelve las sedes activas.
	 *
	 * @return WP_Post[]
	 */
	public static function obtener_sedes_activas() {
		return get_posts(
			array(
				'post_type'      => self::CPT,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_key'       => '_msp_activa',
				'meta_value'     => '1',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Helper: sedes activas habilitadas para recojo de pedidos web.
	 *
	 * @return WP_Post[]
	 */
	public static function obtener_sedes_recojo() {
		return get_posts(
			array(
				'post_type'      => self::CPT,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_msp_activa',
						'value' => '1',
					),
					array(
						'key'   => '_msp_vende_web',
						'value' => '1',
					),
				),
			)
		);
	}
}
