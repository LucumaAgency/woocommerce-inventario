<?php
/**
 * Comprobantes electrónicos (boletas SUNAT) — Fase 1: base de datos.
 *
 * Esta clase es la capa que el POS y la web usarán para emitir. En la Fase 1
 * solo asigna serie + correlativo y guarda la fila; la firma y el envío a SUNAT
 * (driver Greenter) llegan en la Fase 2. El POS nunca habla con SUNAT: habla
 * con esta capa.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reserva de correlativos y acceso a la tabla de comprobantes.
 */
class MSP_Comprobante {

	/**
	 * Meta de la sede donde vive su serie de boletas (ej. B001).
	 */
	const META_SERIE = '_msp_serie_boleta';

	/**
	 * Reintentos máximos al reservar un correlativo cuando dos cajeros chocan.
	 */
	const MAX_REINTENTOS_RESERVA = 25;

	/**
	 * Importe a partir del cual SUNAT exige identificar al comprador.
	 *
	 * Regla de boletas: por encima de S/ 700 hay que consignar el tipo y número
	 * de documento del cliente. Es una constante y no un ajuste porque no es
	 * una preferencia de la tienda: es la norma.
	 */
	const LIMITE_DNI = 700;

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'msp_comprobantes';
	}

	/**
	 * Serie de boletas configurada en una sede.
	 *
	 * @param int $sede_id ID de la sede.
	 * @return string Serie (ej. 'B001') o '' si no está configurada.
	 */
	public static function serie_de_sede( $sede_id ) {
		$serie = get_post_meta( (int) $sede_id, self::META_SERIE, true );
		return is_string( $serie ) ? strtoupper( trim( $serie ) ) : '';
	}

	/**
	 * Valida el formato de serie de boleta que exige SUNAT.
	 *
	 * Regla: 4 posiciones alfanuméricas que empiezan con "B". Ej: B001.
	 *
	 * @param string $serie Serie a validar.
	 * @return bool
	 */
	public static function serie_valida( $serie ) {
		return (bool) preg_match( '/^B[0-9A-Z]{3}$/', strtoupper( trim( (string) $serie ) ) );
	}

	/**
	 * Comprueba si una serie ya está usada por OTRA sede.
	 *
	 * Dos sedes no pueden compartir serie: se pisarían el correlativo. Se valida
	 * al guardar la sede.
	 *
	 * @param string $serie          Serie a comprobar.
	 * @param int    $excluir_sede_id Sede que se está guardando (se ignora a sí misma).
	 * @return bool True si la serie ya está en uso por otra sede.
	 */
	public static function serie_en_uso( $serie, $excluir_sede_id = 0 ) {
		$serie = strtoupper( trim( (string) $serie ) );
		if ( '' === $serie ) {
			return false;
		}

		$args = array(
			'post_type'      => MSP_Sedes::CPT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post__not_in'   => array( (int) $excluir_sede_id ),
			'meta_key'       => self::META_SERIE,
			'meta_value'     => $serie,
		);

		return ! empty( get_posts( $args ) );
	}

	/**
	 * Reserva el siguiente correlativo de una serie y crea la fila del comprobante.
	 *
	 * Estrategia optimista, igual que descontar_si_hay del stock: se calcula el
	 * siguiente número y se intenta INSERTAR. El índice UNIQUE (serie, correlativo)
	 * rechaza el duplicado si otro cajero se adelantó; en ese caso se reintenta con
	 * el siguiente. Nunca se repite ni se salta un número, y nunca usamos
	 * SELECT MAX()+1 sin la red del índice único.
	 *
	 * @param array $datos {
	 *     Datos del comprobante.
	 *
	 *     @type int    $sede_id          Sede emisora (obligatorio).
	 *     @type int    $pedido_id        Pedido Woo asociado.
	 *     @type string $tipo             'boleta' por defecto.
	 *     @type string $cliente_tipo_doc '0' (sin doc) o '1' (DNI).
	 *     @type string $cliente_num_doc  Número de documento.
	 *     @type string $cliente_nombre   Nombre del cliente.
	 *     @type float  $total            Total con IGV.
	 *     @type float  $igv              IGV desglosado.
	 * }
	 * @return array|WP_Error Fila creada (con id, serie, correlativo) o WP_Error.
	 */
	public static function reservar( $datos ) {
		global $wpdb;

		$sede_id = isset( $datos['sede_id'] ) ? (int) $datos['sede_id'] : 0;
		if ( ! $sede_id ) {
			return new WP_Error( 'msp_sin_sede', __( 'Falta la sede emisora del comprobante.', 'multisede-pos' ) );
		}

		$serie = self::serie_de_sede( $sede_id );
		if ( ! self::serie_valida( $serie ) ) {
			return new WP_Error(
				'msp_serie_invalida',
				sprintf(
					/* translators: %d: ID de la sede. */
					__( 'La sede %d no tiene una serie de boleta válida (ej. B001). Configúrala en la sede.', 'multisede-pos' ),
					$sede_id
				)
			);
		}

		$tabla = self::tabla();
		$ahora = current_time( 'mysql' );

		for ( $intento = 0; $intento < self::MAX_REINTENTOS_RESERVA; $intento++ ) {
			// Siguiente correlativo de ESTA serie (empieza en 1 si no hay ninguno).
			$max = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(correlativo) FROM {$tabla} WHERE serie = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$serie
				)
			);
			$siguiente = $max + 1;

			// El INSERT falla si otro cajero ya tomó este (serie, correlativo).
			// suppress_errors evita que el duplicado ensucie el log: es esperado.
			$suprimir = $wpdb->suppress_errors( true );
			$ok       = $wpdb->insert(
				$tabla,
				array(
					'pedido_id'        => isset( $datos['pedido_id'] ) ? (int) $datos['pedido_id'] : null,
					'sede_id'          => $sede_id,
					'tipo'             => isset( $datos['tipo'] ) ? sanitize_key( $datos['tipo'] ) : 'boleta',
					'serie'            => $serie,
					'correlativo'      => $siguiente,
					'cliente_tipo_doc' => isset( $datos['cliente_tipo_doc'] ) ? substr( sanitize_text_field( $datos['cliente_tipo_doc'] ), 0, 2 ) : '0',
					'cliente_num_doc'  => isset( $datos['cliente_num_doc'] ) ? substr( sanitize_text_field( $datos['cliente_num_doc'] ), 0, 20 ) : '',
					'cliente_nombre'   => isset( $datos['cliente_nombre'] ) ? substr( sanitize_text_field( $datos['cliente_nombre'] ), 0, 255 ) : '',
					'total'            => isset( $datos['total'] ) ? round( (float) $datos['total'], 2 ) : 0,
					'igv'              => isset( $datos['igv'] ) ? round( (float) $datos['igv'], 2 ) : 0,
					'estado'           => 'pendiente',
					'emitido_at'       => $ahora,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s' )
			);
			$wpdb->suppress_errors( $suprimir );

			if ( $ok ) {
				return self::obtener( (int) $wpdb->insert_id );
			}
			// Choque en el índice único → otro cajero ganó este número. Reintenta.
		}

		return new WP_Error(
			'msp_correlativo_ocupado',
			__( 'No se pudo reservar un correlativo tras varios intentos. Reintenta la emisión.', 'multisede-pos' )
		);
	}

	/**
	 * Actualiza campos de un comprobante ya reservado.
	 *
	 * Solo se permiten los campos del resultado de la emisión: la serie y el
	 * correlativo NO se tocan nunca desde aquí. SUNAT exige numeración sin
	 * saltos, así que un número reservado se conserva aunque el envío falle.
	 *
	 * @param int   $id    ID del comprobante.
	 * @param array $datos Campos a actualizar.
	 * @return bool
	 */
	public static function actualizar( $id, $datos ) {
		global $wpdb;

		$permitidos = array( 'estado', 'ultimo_error', 'hash', 'xml_path', 'cdr_path' );
		$campos     = array();
		$formatos   = array();

		foreach ( $permitidos as $campo ) {
			if ( array_key_exists( $campo, $datos ) ) {
				$campos[ $campo ] = is_string( $datos[ $campo ] ) ? $datos[ $campo ] : (string) $datos[ $campo ];
				$formatos[]       = '%s';
			}
		}

		// Campos de la cola (Fase 3). Van aparte porque admiten NULL: una fecha
		// de próximo intento vacía significa "no hay reintento programado", y
		// convertirla a cadena la guardaría como '0000-00-00', que sí entraría
		// en la consulta de pendientes y reintentaría para siempre.
		foreach ( array( 'proximo_intento', 'alertado_at', 'enviado_at' ) as $campo ) {
			if ( array_key_exists( $campo, $datos ) ) {
				$campos[ $campo ] = $datos[ $campo ] ? $datos[ $campo ] : null;
				$formatos[]       = '%s';
			}
		}

		if ( array_key_exists( 'intentos', $datos ) ) {
			$campos['intentos'] = (int) $datos['intentos'];
			$formatos[]         = '%d';
		}

		if ( ! $campos ) {
			return false;
		}

		return (bool) $wpdb->update( self::tabla(), $campos, array( 'id' => (int) $id ), $formatos, array( '%d' ) );
	}

	/**
	 * Obtiene un comprobante por su ID.
	 *
	 * @param int $id ID del comprobante.
	 * @return array|null
	 */
	public static function obtener( $id ) {
		global $wpdb;
		$tabla = self::tabla();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tabla} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $id
			),
			ARRAY_A
		);
	}

	/**
	 * Comprobante asociado a un pedido (evita emitir dos veces por el mismo).
	 *
	 * @param int $pedido_id ID del pedido Woo.
	 * @return array|null
	 */
	public static function obtener_por_pedido( $pedido_id ) {
		global $wpdb;
		$tabla = self::tabla();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tabla} WHERE pedido_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $pedido_id
			),
			ARRAY_A
		);
	}

	/**
	 * Lista comprobantes para la pantalla de gerencia, con filtros y paginación.
	 *
	 * @param array $args {
	 *     @type string $estado   Filtro por estado ('' = todos).
	 *     @type int    $sede_id  Filtro por sede (0 = todas).
	 *     @type string $buscar   Serie-correlativo o número de pedido.
	 *     @type int    $por_pag  Filas por página.
	 *     @type int    $pagina   Página (1 en adelante).
	 * }
	 * @return array {
	 *     @type array $filas Filas encontradas.
	 *     @type int   $total Total de filas que cumplen el filtro.
	 * }
	 */
	public static function listar( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'estado'  => '',
				'sede_id' => 0,
				'buscar'  => '',
				'por_pag' => 30,
				'pagina'  => 1,
			)
		);

		$tabla  = self::tabla();
		$where  = array( '1=1' );
		$params = array();

		if ( $args['estado'] ) {
			$where[]  = 'estado = %s';
			$params[] = sanitize_key( $args['estado'] );
		}
		if ( $args['sede_id'] ) {
			$where[]  = 'sede_id = %d';
			$params[] = (int) $args['sede_id'];
		}
		if ( '' !== trim( (string) $args['buscar'] ) ) {
			$buscar   = trim( (string) $args['buscar'] );
			$where[]  = '( CONCAT(serie, "-", LPAD(correlativo, 8, "0")) LIKE %s OR pedido_id = %d )';
			$params[] = '%' . $wpdb->esc_like( strtoupper( $buscar ) ) . '%';
			$params[] = (int) preg_replace( '/[^0-9]/', '', $buscar );
		}

		$sql_where = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var(
			$params
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$tabla} WHERE {$sql_where}", $params )
				: "SELECT COUNT(*) FROM {$tabla}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$por_pag = max( 1, (int) $args['por_pag'] );
		$offset  = max( 0, ( (int) $args['pagina'] - 1 ) * $por_pag );

		$filas = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$tabla} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $params, array( $por_pag, $offset ) )
			),
			ARRAY_A
		);

		return array(
			'filas' => $filas ? $filas : array(),
			'total' => $total,
		);
	}

	/**
	 * Cuenta comprobantes por estado, para el resumen de la pantalla.
	 *
	 * @return array Mapa estado => cantidad.
	 */
	public static function contar_por_estado() {
		global $wpdb;
		$tabla = self::tabla();

		$filas = $wpdb->get_results(
			"SELECT estado, COUNT(*) AS n FROM {$tabla} GROUP BY estado", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $filas as $f ) {
			$out[ $f['estado'] ] = (int) $f['n'];
		}
		return $out;
	}

	/**
	 * Comprobantes que tocan reintentar ahora.
	 *
	 * @param int $limite Máximo de filas.
	 * @return array
	 */
	public static function pendientes_de_reintento( $limite = 20 ) {
		global $wpdb;
		$tabla = self::tabla();

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tabla}
				 WHERE estado IN ('pendiente','error')
				   AND proximo_intento IS NOT NULL
				   AND proximo_intento <= %s
				 ORDER BY proximo_intento ASC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql' ),
				(int) $limite
			),
			ARRAY_A
		);
	}

	/**
	 * Comprobantes atascados desde hace más de N días y todavía sin avisar.
	 *
	 * @param int $dias Antigüedad mínima en días.
	 * @return array
	 */
	public static function atascados( $dias = 2 ) {
		global $wpdb;
		$tabla  = self::tabla();
		$limite = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( (int) $dias * DAY_IN_SECONDS ) );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tabla}
				 WHERE estado IN ('pendiente','error','rechazado')
				   AND emitido_at <= %s
				   AND alertado_at IS NULL
				 ORDER BY emitido_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limite
			),
			ARRAY_A
		);
	}

	/**
	 * Número legible del comprobante (ej. "B001-00000042").
	 *
	 * @param array $comprobante Fila del comprobante.
	 * @return string
	 */
	public static function numero( $comprobante ) {
		if ( empty( $comprobante['serie'] ) ) {
			return '';
		}
		return sprintf( '%s-%08d', $comprobante['serie'], (int) $comprobante['correlativo'] );
	}
}
