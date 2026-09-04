<?php
/**
 * API REST de gestión (namespace msp/v1).
 *
 * Permite crear/editar/borrar sedes y fijar el stock por sede desde fuera del
 * panel, autenticando con una contraseña de aplicación. Existe porque el CPT
 * `msp_sede` no está expuesto en la REST del core y su meta va con prefijo `_`
 * (protegida), que WordPress no deja escribir por REST/XML-RPC genéricos. Es la
 * vía para cargar el catálogo y el inventario real en lote.
 *
 * Solo para administradores (`manage_options`).
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controlador REST de gestión.
 */
class MSP_REST {

	const NS = 'msp/v1';

	/**
	 * Engancha el registro de rutas.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'registrar_rutas' ) );
	}

	/**
	 * Solo administradores.
	 *
	 * @return bool
	 */
	public function permiso() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Registra todas las rutas.
	 */
	public function registrar_rutas() {
		register_rest_route(
			self::NS,
			'/sedes',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'listar_sedes' ),
					'permission_callback' => array( $this, 'permiso' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'crear_sede' ),
					'permission_callback' => array( $this, 'permiso' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/sedes/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'POST', // Actúa como update (POST/PUT/PATCH).
					'callback'            => array( $this, 'editar_sede' ),
					'permission_callback' => array( $this, 'permiso' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'borrar_sede' ),
					'permission_callback' => array( $this, 'permiso' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/stock',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'fijar_stock' ),
				'permission_callback' => array( $this, 'permiso' ),
			)
		);

		register_rest_route(
			self::NS,
			'/stock/(?P<producto_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stock_producto' ),
				'permission_callback' => array( $this, 'permiso' ),
			)
		);

		register_rest_route(
			self::NS,
			'/diagnostico',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'diagnostico' ),
				'permission_callback' => array( $this, 'permiso' ),
			)
		);
	}

	/**
	 * GET /diagnostico
	 *
	 * Comprueba, sin llamar a SUNAT ni exponer material de la clave, que el
	 * certificado está bien instalado: la ruta resuelve, el archivo se lee, el
	 * certificado corresponde al RUC del emisor, cuándo vence, y que la clave
	 * privada casa con el certificado. Refleja la misma resolución de ruta que
	 * usa el motor de emisión (MSP_Emisor::ruta_certificado).
	 *
	 * @return WP_REST_Response
	 */
	public function diagnostico() {
		$ajustes = MSP_Emisor::ajustes();
		$ruc     = isset( $ajustes['ruc'] ) ? (string) $ajustes['ruc'] : '';
		$sol     = isset( $ajustes['sol_usuario'] ) ? (string) $ajustes['sol_usuario'] : '';

		$out = array(
			'entorno'              => isset( $ajustes['entorno'] ) ? $ajustes['entorno'] : '',
			'es_produccion'        => MSP_Emisor::es_produccion(),
			'msp_cert_path_const'  => defined( 'MSP_CERT_PATH' ) ? (string) MSP_CERT_PATH : null,
			'ruta_usada'           => MSP_Emisor::ruta_certificado(),
			'ruc_emisor'           => $ruc,
			'razon_social'         => isset( $ajustes['razon_social'] ) ? $ajustes['razon_social'] : '',
			'sol_usuario'          => $sol,
			'sol_es_por_defecto'   => ( 'MODDATOS' === $sol ),
			'archivo_existe'       => false,
			'archivo_legible'      => false,
			'cert_valido'          => false,
			'cert_cn'              => '',
			'cert_ruc_coincide'    => false,
			'cert_vence'           => '',
			'cert_vigente'         => false,
			'clave_privada_casa'   => false,
			'mensaje'              => '',
		);

		$ruta = $out['ruta_usada'];
		if ( ! $ruta ) {
			$out['mensaje'] = 'No hay certificado configurado: define MSP_CERT_PATH en wp-config.php o el campo cert_path.';
			return rest_ensure_response( $out );
		}
		if ( ! file_exists( $ruta ) ) {
			$out['mensaje'] = 'La ruta está configurada pero el archivo no existe ahí. Revisa la ruta absoluta.';
			return rest_ensure_response( $out );
		}
		$out['archivo_existe'] = true;

		$contenido = @file_get_contents( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $contenido ) {
			$out['mensaje'] = 'El archivo existe pero PHP no puede leerlo. Revisa permisos (0400/0440) y propietario.';
			return rest_ensure_response( $out );
		}
		$out['archivo_legible'] = true;

		if ( ! function_exists( 'openssl_x509_parse' ) ) {
			$out['mensaje'] = 'El archivo se lee, pero la extensión OpenSSL no está disponible para validarlo.';
			return rest_ensure_response( $out );
		}

		$cert = openssl_x509_parse( $contenido );
		if ( false === $cert ) {
			$out['mensaje'] = 'El archivo se lee pero no es un certificado PEM válido (¿subiste el .p12 en vez del .pem convertido?).';
			return rest_ensure_response( $out );
		}
		$out['cert_valido'] = true;
		$out['cert_cn']     = isset( $cert['subject']['CN'] ) ? $cert['subject']['CN'] : '';

		// El RUC de SUNAT va en el subject (serialNumber o CN). Buscamos la cadena.
		if ( $ruc ) {
			$subject_txt = wp_json_encode( isset( $cert['subject'] ) ? $cert['subject'] : array() );
			$out['cert_ruc_coincide'] = ( false !== strpos( (string) $subject_txt, $ruc ) );
		}

		if ( isset( $cert['validTo_time_t'] ) ) {
			$out['cert_vence']   = gmdate( 'Y-m-d', (int) $cert['validTo_time_t'] );
			$out['cert_vigente'] = ( time() < (int) $cert['validTo_time_t'] )
				&& ( ! isset( $cert['validFrom_time_t'] ) || time() >= (int) $cert['validFrom_time_t'] );
		}

		if ( function_exists( 'openssl_x509_check_private_key' ) ) {
			$out['clave_privada_casa'] = openssl_x509_check_private_key( $contenido, $contenido );
		}

		// Resumen legible.
		if ( ! $out['cert_ruc_coincide'] ) {
			$out['mensaje'] = 'ATENCIÓN: el certificado NO parece corresponder al RUC ' . $ruc . '. Verifica que subiste el certificado correcto.';
		} elseif ( ! $out['clave_privada_casa'] ) {
			$out['mensaje'] = 'El certificado es del RUC correcto, pero el PEM no incluye la clave privada que le corresponde. Reconvierte el .p12 con -nodes.';
		} elseif ( ! $out['cert_vigente'] ) {
			$out['mensaje'] = 'El certificado corresponde al RUC pero está fuera de vigencia (vence ' . $out['cert_vence'] . ').';
		} else {
			$out['mensaje'] = 'OK: certificado del RUC ' . $ruc . ', con su clave privada, vigente hasta ' . $out['cert_vence'] . '. Listo para firmar.';
		}

		return rest_ensure_response( $out );
	}

	/**
	 * Serializa una sede a array plano.
	 *
	 * @param int $id ID de sede.
	 * @return array
	 */
	private function sede_a_array( $id ) {
		return array(
			'id'              => (int) $id,
			'nombre'          => get_the_title( $id ),
			'estado'          => get_post_status( $id ),
			'direccion'       => get_post_meta( $id, '_msp_direccion', true ),
			'horario'         => get_post_meta( $id, '_msp_horario', true ),
			'serie_boleta'    => get_post_meta( $id, '_msp_serie_boleta', true ),
			'vende_web'       => '1' === get_post_meta( $id, '_msp_vende_web', true ),
			'vende_mostrador' => '1' === get_post_meta( $id, '_msp_vende_mostrador', true ),
			'es_virtual'      => '1' === get_post_meta( $id, '_msp_es_virtual', true ),
			'activa'          => '1' === get_post_meta( $id, '_msp_activa', true ),
		);
	}

	/**
	 * GET /sedes
	 *
	 * @return WP_REST_Response
	 */
	public function listar_sedes() {
		$posts = get_posts(
			array(
				'post_type'   => MSP_Sedes::CPT,
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = $this->sede_a_array( $p->ID );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Aplica los campos recibidos a la meta de una sede.
	 *
	 * @param int             $id  ID de sede.
	 * @param WP_REST_Request $req Petición.
	 */
	private function aplicar_meta_sede( $id, $req ) {
		$texto = array(
			'direccion'    => '_msp_direccion',
			'horario'      => '_msp_horario',
			'serie_boleta' => '_msp_serie_boleta',
		);
		foreach ( $texto as $param => $meta ) {
			if ( null !== $req->get_param( $param ) ) {
				update_post_meta( $id, $meta, sanitize_text_field( (string) $req->get_param( $param ) ) );
			}
		}
		$flags = array(
			'vende_web'       => '_msp_vende_web',
			'vende_mostrador' => '_msp_vende_mostrador',
			'es_virtual'      => '_msp_es_virtual',
			'activa'          => '_msp_activa',
		);
		foreach ( $flags as $param => $meta ) {
			if ( null !== $req->get_param( $param ) ) {
				update_post_meta( $id, $meta, rest_sanitize_boolean( $req->get_param( $param ) ) ? '1' : '0' );
			}
		}
	}

	/**
	 * POST /sedes
	 *
	 * @param WP_REST_Request $req Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function crear_sede( $req ) {
		$nombre = sanitize_text_field( (string) $req->get_param( 'nombre' ) );
		if ( '' === $nombre ) {
			return new WP_Error( 'msp_falta_nombre', 'Falta el nombre de la sede.', array( 'status' => 400 ) );
		}

		$serie = $req->get_param( 'serie_boleta' );
		if ( $serie ) {
			$dup = $this->serie_en_uso( sanitize_text_field( (string) $serie ), 0 );
			if ( $dup ) {
				return new WP_Error(
					'msp_serie_duplicada',
					sprintf( 'La serie %s ya la usa la sede #%d.', $serie, $dup ),
					array( 'status' => 409 )
				);
			}
		}

		$id = wp_insert_post(
			array(
				'post_type'   => MSP_Sedes::CPT,
				'post_status' => 'publish',
				'post_title'  => $nombre,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// Valores por defecto para una tienda física antes de aplicar lo recibido.
		update_post_meta( $id, '_msp_vende_mostrador', '1' );
		update_post_meta( $id, '_msp_activa', '1' );
		update_post_meta( $id, '_msp_es_virtual', '0' );

		$this->aplicar_meta_sede( $id, $req );

		return rest_ensure_response( $this->sede_a_array( $id ) );
	}

	/**
	 * POST /sedes/{id}
	 *
	 * @param WP_REST_Request $req Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function editar_sede( $req ) {
		$id = (int) $req->get_param( 'id' );
		if ( MSP_Sedes::CPT !== get_post_type( $id ) ) {
			return new WP_Error( 'msp_sede_no_existe', 'No existe esa sede.', array( 'status' => 404 ) );
		}

		if ( null !== $req->get_param( 'nombre' ) ) {
			$nombre = sanitize_text_field( (string) $req->get_param( 'nombre' ) );
			if ( '' !== $nombre ) {
				wp_update_post(
					array(
						'ID'         => $id,
						'post_title' => $nombre,
					)
				);
			}
		}

		$serie = $req->get_param( 'serie_boleta' );
		if ( $serie ) {
			$dup = $this->serie_en_uso( sanitize_text_field( (string) $serie ), $id );
			if ( $dup ) {
				return new WP_Error(
					'msp_serie_duplicada',
					sprintf( 'La serie %s ya la usa la sede #%d.', $serie, $dup ),
					array( 'status' => 409 )
				);
			}
		}

		$this->aplicar_meta_sede( $id, $req );

		return rest_ensure_response( $this->sede_a_array( $id ) );
	}

	/**
	 * DELETE /sedes/{id}
	 *
	 * Por defecto va a la papelera (reversible). `force=true` borra del todo.
	 *
	 * @param WP_REST_Request $req Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function borrar_sede( $req ) {
		$id = (int) $req->get_param( 'id' );
		if ( MSP_Sedes::CPT !== get_post_type( $id ) ) {
			return new WP_Error( 'msp_sede_no_existe', 'No existe esa sede.', array( 'status' => 404 ) );
		}
		$force = rest_sanitize_boolean( $req->get_param( 'force' ) );
		$res   = wp_delete_post( $id, $force );
		if ( ! $res ) {
			return new WP_Error( 'msp_no_borrada', 'No se pudo borrar la sede.', array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'id'       => $id,
				'borrada'  => true,
				'papelera' => ! $force,
			)
		);
	}

	/**
	 * Devuelve el ID de la sede que ya usa una serie, excluyendo una.
	 *
	 * @param string $serie   Serie a comprobar.
	 * @param int    $excluir ID de sede a excluir.
	 * @return int ID de la sede en conflicto, o 0.
	 */
	private function serie_en_uso( $serie, $excluir ) {
		if ( '' === $serie ) {
			return 0;
		}
		$posts = get_posts(
			array(
				'post_type'   => MSP_Sedes::CPT,
				'post_status' => 'any',
				'numberposts' => -1,
				'meta_key'    => '_msp_serie_boleta',
				'meta_value'  => $serie,
				'exclude'     => array( (int) $excluir ),
				'fields'      => 'ids',
			)
		);
		return $posts ? (int) $posts[0] : 0;
	}

	/**
	 * POST /stock
	 *
	 * Acepta un item suelto {producto_id, sede_id, stock} o un lote en `items`.
	 * Sincroniza el stock agregado de Woo por cada producto tocado.
	 *
	 * @param WP_REST_Request $req Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function fijar_stock( $req ) {
		$items = $req->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			$items = array(
				array(
					'producto_id' => $req->get_param( 'producto_id' ),
					'sede_id'     => $req->get_param( 'sede_id' ),
					'stock'       => $req->get_param( 'stock' ),
				),
			);
		}

		$tocados  = array();
		$aplicado = 0;
		foreach ( $items as $it ) {
			$producto_id = isset( $it['producto_id'] ) ? (int) $it['producto_id'] : 0;
			$sede_id     = isset( $it['sede_id'] ) ? (int) $it['sede_id'] : 0;
			$stock       = isset( $it['stock'] ) ? (int) $it['stock'] : 0;
			if ( ! $producto_id || ! $sede_id ) {
				continue;
			}
			if ( MSP_Sedes::CPT !== get_post_type( $sede_id ) ) {
				continue;
			}
			if ( ! in_array( get_post_type( $producto_id ), array( 'product', 'product_variation' ), true ) ) {
				continue;
			}
			MSP_Stock::set( $producto_id, $sede_id, $stock );
			$tocados[ $producto_id ] = true;
			++$aplicado;
		}

		foreach ( array_keys( $tocados ) as $pid ) {
			MSP_Stock::sincronizar_woo( $pid );
		}

		return rest_ensure_response(
			array(
				'aplicados' => $aplicado,
				'productos' => count( $tocados ),
			)
		);
	}

	/**
	 * GET /stock/{producto_id}
	 *
	 * @param WP_REST_Request $req Petición.
	 * @return WP_REST_Response
	 */
	public function stock_producto( $req ) {
		$producto_id = (int) $req->get_param( 'producto_id' );
		return rest_ensure_response(
			array(
				'producto_id' => $producto_id,
				'por_sede'    => MSP_Stock::por_sede( $producto_id ),
				'total'       => MSP_Stock::total( $producto_id ),
			)
		);
	}
}
