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
		add_action( 'admin_notices', array( $this, 'mostrar_avisos' ) );
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
				// Ojo: aquí van SOLO capacidades primitivas. No hay que mapear
				// 'edit_post', 'read_post' ni 'delete_post', que son META
				// capacidades (se preguntan sobre un post concreto): al darles
				// el mismo nombre que la primitiva, WordPress registraba
				// 'msp_gestionar_sedes' en $post_type_meta_caps y, a partir de
				// ahí, cualquier current_user_can('msp_gestionar_sedes') SIN
				// post intentaba resolverla como meta cap, no encontraba el
				// post y devolvía do_not_allow, saliendo antes de aplicar los
				// filtros. La capacidad se envenenaba a sí misma y ni el
				// administrador podía entrar al menú Sedes.
				// Con map_meta_cap => true, WordPress deriva solo las meta caps
				// a partir de estas primitivas.
				'capabilities'        => array(
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
		$serie_boleta    = get_post_meta( $post->ID, MSP_Comprobante::META_SERIE, true );
		$serie_factura   = get_post_meta( $post->ID, MSP_Comprobante::META_SERIE_FACTURA, true );
		$emisor_ruc      = get_post_meta( $post->ID, MSP_Emisor::META_EMISOR, true );
		$serie_nc_boleta  = get_post_meta( $post->ID, MSP_Comprobante::META_SERIE_NC_BOLETA, true );
		$serie_nc_factura = get_post_meta( $post->ID, MSP_Comprobante::META_SERIE_NC_FACTURA, true );
		$emisores        = class_exists( 'MSP_Emisor' ) ? MSP_Emisor::emisores() : array();

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

		<?php if ( count( $emisores ) > 1 ) : ?>
			<p class="msp-field">
				<label for="msp_emisor_ruc"><?php esc_html_e( 'Empresa que emite', 'multisede-pos' ); ?></label>
				<select id="msp_emisor_ruc" name="msp_emisor_ruc" class="widefat">
					<?php foreach ( $emisores as $ruc => $datos ) : ?>
						<option value="<?php echo esc_attr( $ruc ); ?>" <?php selected( $emisor_ruc ? $emisor_ruc : array_key_first( $emisores ), $ruc ); ?>>
							<?php echo esc_html( $datos['razon_social'] . ' — ' . $ruc ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span style="display:block;color:#666;font-size:12px;margin-top:4px">
					<?php esc_html_e( 'Con qué RUC se emiten los comprobantes de esta tienda. Cámbialo solo antes de emitir el primero: los ya emitidos conservan la empresa con la que salieron.', 'multisede-pos' ); ?>
				</span>
			</p>
		<?php endif; ?>

		<p class="msp-field">
			<label for="msp_serie_boleta"><?php esc_html_e( 'Serie de boleta electrónica', 'multisede-pos' ); ?></label>
			<input type="text" id="msp_serie_boleta" name="msp_serie_boleta" class="widefat" maxlength="4"
				style="text-transform:uppercase;max-width:120px"
				value="<?php echo esc_attr( $serie_boleta ); ?>"
				placeholder="B001" />
			<span style="display:block;color:#666;font-size:12px;margin-top:4px">
				<?php esc_html_e( 'Empieza con "B" y 4 caracteres (ej. B001). Debe ser única por sede: dos tiendas no pueden compartir serie. Déjala vacía hasta activar la facturación electrónica.', 'multisede-pos' ); ?>
			</span>
		</p>

		<p class="msp-field">
			<label for="msp_serie_factura"><?php esc_html_e( 'Serie de factura electrónica', 'multisede-pos' ); ?></label>
			<input type="text" id="msp_serie_factura" name="msp_serie_factura" class="widefat" maxlength="4"
				style="text-transform:uppercase;max-width:120px"
				value="<?php echo esc_attr( $serie_factura ); ?>"
				placeholder="F001" />
			<span style="display:block;color:#666;font-size:12px;margin-top:4px">
				<?php esc_html_e( 'Empieza con "F" y 4 caracteres (ej. F001). Única por sede, y tampoco puede repetir una serie de boleta. Déjala vacía si esta tienda no emite facturas: sin ella, el sistema solo emitirá boletas.', 'multisede-pos' ); ?>
			</span>
		</p>

		<p class="msp-field">
			<label for="msp_serie_nc_boleta"><?php esc_html_e( 'Series de nota de crédito', 'multisede-pos' ); ?></label>
			<input type="text" id="msp_serie_nc_boleta" name="msp_serie_nc_boleta" maxlength="4"
				style="text-transform:uppercase;max-width:120px"
				value="<?php echo esc_attr( $serie_nc_boleta ); ?>" placeholder="BC00" />
			<input type="text" id="msp_serie_nc_factura" name="msp_serie_nc_factura" maxlength="4"
				style="text-transform:uppercase;max-width:120px"
				value="<?php echo esc_attr( $serie_nc_factura ); ?>" placeholder="FC00" />
			<span style="display:block;color:#666;font-size:12px;margin-top:4px">
				<?php esc_html_e( 'Son DOS: la nota hereda la letra del documento que corrige, así que la de boletas empieza con "B" (ej. BC00) y la de facturas con "F" (ej. FC00). Sin ellas, esa tienda no puede hacer devoluciones.', 'multisede-pos' ); ?>
			</span>
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

		// Empresa emisora de la sede. Solo se toca si el formulario la traía:
		// en una instalación de un solo emisor el campo no existe y la sede
		// sigue con el principal, sin meta que mantener.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- El nonce lo valida guardar() antes de llamar aquí.
		if ( isset( $_POST['msp_emisor_ruc'] ) ) {
			$ruc_emisor = preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['msp_emisor_ruc'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $ruc_emisor && isset( MSP_Emisor::emisores()[ $ruc_emisor ] ) ) {
				update_post_meta( $post_id, MSP_Emisor::META_EMISOR, $ruc_emisor );
			} else {
				delete_post_meta( $post_id, MSP_Emisor::META_EMISOR );
			}
		}

		// Series de boleta y de factura: opcionales, pero si se ponen deben
		// tener el formato de SUNAT y no chocar con la de otra sede. El bucle
		// evita dos bloques gemelos que luego se corrigen solo en uno.
		foreach ( array( 'boleta', 'factura', 'nc_boleta', 'nc_factura' ) as $tipo ) {
			$campo = 'msp_serie_' . $tipo;
			$meta  = MSP_Comprobante::dato_tipo( $tipo, 'meta' );
			$letra = MSP_Comprobante::dato_tipo( $tipo, 'prefijo' );
			$corto = mb_strtolower( MSP_Comprobante::dato_tipo( $tipo, 'corto' ) );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- El nonce lo valida guardar() antes de llamar aquí.
			$serie = isset( $_POST[ $campo ] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST[ $campo ] ) ) ) : '';

			if ( '' === $serie ) {
				delete_post_meta( $post_id, $meta );
			} elseif ( ! MSP_Comprobante::serie_valida( $serie, $tipo ) ) {
				set_transient(
					'msp_sede_aviso_' . $post_id,
					sprintf(
						/* translators: 1: tipo de comprobante, 2: letra inicial, 3: ejemplo de serie. */
						__( 'La serie de %1$s no se guardó: debe empezar con "%2$s" y tener 4 caracteres (ej. %3$s).', 'multisede-pos' ),
						$corto,
						$letra,
						$letra . '001'
					),
					60
				);
				delete_post_meta( $post_id, $meta );
			} elseif ( MSP_Comprobante::serie_en_uso( $serie, $post_id ) ) {
				set_transient(
					'msp_sede_aviso_' . $post_id,
					/* translators: %s: serie. */
					sprintf( __( 'La serie %s ya la usa otra sede y no se guardó: cada tienda necesita una serie distinta.', 'multisede-pos' ), $serie ),
					60
				);
				delete_post_meta( $post_id, $meta );
			} else {
				update_post_meta( $post_id, $meta, $serie );
			}
		}
	}

	/**
	 * Muestra el aviso si la serie de boleta se rechazó al guardar.
	 */
	public function mostrar_avisos() {
		global $post;
		if ( ! $post || self::CPT !== $post->post_type ) {
			return;
		}
		$aviso = get_transient( 'msp_sede_aviso_' . $post->ID );
		if ( $aviso ) {
			delete_transient( 'msp_sede_aviso_' . $post->ID );
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $aviso ) );
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
				$nuevas['msp_serie']     = __( 'Serie boleta', 'multisede-pos' );
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
			case 'msp_serie':
				$serie = get_post_meta( $post_id, MSP_Comprobante::META_SERIE, true );
				echo $serie
					? '<code>' . esc_html( $serie ) . '</code>'
					: '<span style="color:#999">—</span>';
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
