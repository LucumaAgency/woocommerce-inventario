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
	 * Meta de la sede donde vive su serie de facturas (ej. F001).
	 */
	const META_SERIE_FACTURA = '_msp_serie_factura';

	/** Meta de la serie de notas de crédito que corrigen BOLETAS (ej. BC00). */
	const META_SERIE_NC_BOLETA = '_msp_serie_nc_boleta';

	/** Meta de la serie de notas de crédito que corrigen FACTURAS (ej. FC00). */
	const META_SERIE_NC_FACTURA = '_msp_serie_nc_factura';

	/**
	 * Motivos de nota de crédito (catálogo 09 de SUNAT).
	 *
	 * Tres de los diez que existen. Cada opción de más es una pantalla más
	 * confusa para quien la usa con clientes esperando, y las otras siete no
	 * aparecen en una tienda de ropa.
	 *
	 * @return array código => etiqueta.
	 */
	public static function motivos_nota() {
		return array(
			'01' => __( 'Anulación de la operación', 'multisede-pos' ),
			'02' => __( 'Anulación por error en el RUC', 'multisede-pos' ),
			'06' => __( 'Devolución total o parcial', 'multisede-pos' ),
		);
	}

	/**
	 * Tipo de documento de identidad de SUNAT para el RUC (catálogo 06).
	 *
	 * En una factura el comprador se identifica SIEMPRE con RUC; no hay
	 * "consumidor final" que valga.
	 */
	const DOC_RUC = '6';

	/**
	 * Los dos tipos de comprobante que emite el sistema.
	 *
	 * Todo lo que distingue una factura de una boleta está aquí: su código de
	 * SUNAT (catálogo 01), la letra con la que empieza su serie, la meta de la
	 * sede donde vive esa serie y cómo se llama en pantalla y en el papel. El
	 * resto del motor —firma, envío, cola, conservación— no distingue entre
	 * ambos, y esa es justamente la idea.
	 *
	 * @return array
	 */
	public static function tipos() {
		return array(
			'boleta'  => array(
				'codigo'    => '03',
				'prefijo'   => 'B',
				'meta'      => self::META_SERIE,
				'etiqueta'  => __( 'Boleta de venta electrónica', 'multisede-pos' ),
				'corto'     => __( 'Boleta', 'multisede-pos' ),
			),
			'factura' => array(
				'codigo'    => '01',
				'prefijo'   => 'F',
				'meta'      => self::META_SERIE_FACTURA,
				'etiqueta'  => __( 'Factura electrónica', 'multisede-pos' ),
				'corto'     => __( 'Factura', 'multisede-pos' ),
			),
			// Las notas de crédito son un solo documento ante SUNAT (código 07),
			// pero llevan DOS series según a quién corrijan: la serie hereda la
			// letra del documento afectado (B para boletas, F para facturas).
			// Por eso aquí son dos tipos y no uno.
			'nc_boleta'  => array(
				'codigo'    => '07',
				'prefijo'   => 'B',
				'meta'      => self::META_SERIE_NC_BOLETA,
				'etiqueta'  => __( 'Nota de crédito electrónica', 'multisede-pos' ),
				'corto'     => __( 'Nota de crédito', 'multisede-pos' ),
			),
			'nc_factura' => array(
				'codigo'    => '07',
				'prefijo'   => 'F',
				'meta'      => self::META_SERIE_NC_FACTURA,
				'etiqueta'  => __( 'Nota de crédito electrónica', 'multisede-pos' ),
				'corto'     => __( 'Nota de crédito', 'multisede-pos' ),
			),
		);
	}

	/**
	 * Normaliza un tipo, cayendo a boleta ante cualquier cosa rara.
	 *
	 * La boleta es el valor seguro: se puede emitir siempre, mientras que la
	 * factura exige RUC y razón social del comprador.
	 *
	 * @param string $tipo Tipo.
	 * @return string 'boleta' o 'factura'.
	 */
	public static function tipo_valido( $tipo ) {
		$tipo = sanitize_key( (string) $tipo );
		return isset( self::tipos()[ $tipo ] ) ? $tipo : 'boleta';
	}

	/**
	 * Un dato del tipo de comprobante.
	 *
	 * @param string $tipo  Tipo.
	 * @param string $clave codigo|prefijo|meta|etiqueta|corto.
	 * @return string
	 */
	public static function dato_tipo( $tipo, $clave ) {
		$tipos = self::tipos();
		return $tipos[ self::tipo_valido( $tipo ) ][ $clave ];
	}

	/**
	 * ¿Es este comprobante una nota de crédito?
	 *
	 * @param array|string $comprobante Fila o tipo.
	 * @return bool
	 */
	public static function es_nota( $comprobante ) {
		$tipo = is_array( $comprobante )
			? ( isset( $comprobante['tipo'] ) ? $comprobante['tipo'] : 'boleta' )
			: $comprobante;
		return in_array( self::tipo_valido( $tipo ), array( 'nc_boleta', 'nc_factura' ), true );
	}

	/**
	 * Tipo de nota que corresponde a un comprobante.
	 *
	 * @param array $c Comprobante que se va a corregir.
	 * @return string 'nc_boleta' o 'nc_factura'.
	 */
	public static function tipo_nota_para( $c ) {
		return 'factura' === self::tipo_valido( isset( $c['tipo'] ) ? $c['tipo'] : 'boleta' )
			? 'nc_factura'
			: 'nc_boleta';
	}

	/**
	 * Código de SUNAT del comprobante (catálogo 01): '03' boleta, '01' factura,
	 * '07' nota de crédito.
	 *
	 * @param array|string $comprobante Fila del comprobante, o el tipo suelto.
	 * @return string
	 */
	public static function codigo_sunat( $comprobante ) {
		$tipo = is_array( $comprobante )
			? ( isset( $comprobante['tipo'] ) ? $comprobante['tipo'] : 'boleta' )
			: $comprobante;
		return self::dato_tipo( $tipo, 'codigo' );
	}

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
	 * Entorno de emisión actual ('beta' o 'produccion').
	 *
	 * Cada entorno lleva su propia numeración. Las boletas de prueba ya no
	 * gastan números de la serie real: sin esto, tras veinte pruebas la primera
	 * boleta de verdad salía como B001-00000021.
	 *
	 * @return string
	 */
	public static function entorno_actual() {
		return ( class_exists( 'MSP_Emisor' ) && MSP_Emisor::es_produccion() ) ? 'produccion' : 'beta';
	}

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
	 * Serie configurada en una sede para un tipo de comprobante.
	 *
	 * @param int    $sede_id ID de la sede.
	 * @param string $tipo    'boleta' o 'factura'.
	 * @return string Serie (ej. 'B001', 'F001') o '' si no está configurada.
	 */
	public static function serie_de_sede( $sede_id, $tipo = 'boleta' ) {
		$serie = get_post_meta( (int) $sede_id, self::dato_tipo( $tipo, 'meta' ), true );
		return is_string( $serie ) ? strtoupper( trim( $serie ) ) : '';
	}

	/**
	 * Valida el formato de serie que exige SUNAT.
	 *
	 * Regla: 4 posiciones alfanuméricas que empiezan con "B" en las boletas y
	 * con "F" en las facturas. Ej: B001, F001.
	 *
	 * @param string $serie Serie a validar.
	 * @param string $tipo  'boleta' o 'factura'.
	 * @return bool
	 */
	public static function serie_valida( $serie, $tipo = 'boleta' ) {
		$prefijo = self::dato_tipo( $tipo, 'prefijo' );
		return (bool) preg_match( '/^' . $prefijo . '[0-9A-Z]{3}$/', strtoupper( trim( (string) $serie ) ) );
	}

	/**
	 * Comprueba si una serie ya está usada por OTRA sede.
	 *
	 * Dos sedes no pueden compartir serie: se pisarían el correlativo. Se valida
	 * al guardar la sede.
	 *
	 * Se juzga **dentro del mismo emisor**: dos empresas distintas pueden usar
	 * la misma serie sin pisarse, porque la B100 del RUC A y la B100 del RUC B
	 * son documentos distintos ante SUNAT. Lo que no puede repetirse es la serie
	 * entre dos sedes que emiten con el mismo RUC.
	 *
	 * @param string $serie          Serie a comprobar.
	 * @param int    $excluir_sede_id Sede que se está guardando (se ignora a sí misma).
	 * @return bool True si la serie ya está en uso por otra sede del mismo emisor.
	 */
	public static function serie_en_uso( $serie, $excluir_sede_id = 0 ) {
		$serie = strtoupper( trim( (string) $serie ) );
		if ( '' === $serie ) {
			return false;
		}

		$ruc_propio = class_exists( 'MSP_Emisor' ) ? MSP_Emisor::ruc_de_sede( $excluir_sede_id ) : '';

		// Se buscan las DOS metas, no solo la del tipo que se está guardando:
		// una serie repetida entre una boleta y una factura de sedes distintas
		// seguiría siendo dos documentos compartiendo numeración.
		$args = array(
			'post_type'      => MSP_Sedes::CPT,
			'post_status'    => 'any',
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'post__not_in'   => array( (int) $excluir_sede_id ),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Una sola sede, al guardar.
				'relation' => 'OR',
				array(
					'key'   => self::META_SERIE,
					'value' => $serie,
				),
				array(
					'key'   => self::META_SERIE_FACTURA,
					'value' => $serie,
				),
			),
		);

		$sedes = get_posts( $args );
		if ( empty( $sedes ) ) {
			return false;
		}

		// Choca solo si esa otra sede emite con el mismo RUC.
		if ( class_exists( 'MSP_Emisor' ) && MSP_Emisor::multi_emisor() ) {
			foreach ( $sedes as $otra ) {
				if ( MSP_Emisor::ruc_de_sede( $otra ) === $ruc_propio ) {
					return true;
				}
			}
			return false;
		}

		return true;
	}

	/**
	 * Reserva el siguiente correlativo de una serie y crea la fila del comprobante.
	 *
	 * Estrategia optimista, igual que descontar_si_hay del stock: se calcula el
	 * siguiente número y se intenta INSERTAR. El índice UNIQUE
	 * (entorno, ruc, serie, correlativo) rechaza el duplicado si otro cajero se
	 * adelantó; en ese caso se reintenta con el siguiente. Nunca se repite ni se salta un número, y nunca usamos
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

		$tipo  = self::tipo_valido( isset( $datos['tipo'] ) ? $datos['tipo'] : 'boleta' );
		$serie = self::serie_de_sede( $sede_id, $tipo );
		if ( ! self::serie_valida( $serie, $tipo ) ) {
			return new WP_Error(
				'msp_serie_invalida',
				sprintf(
					/* translators: 1: nombre del tipo de comprobante, 2: ID de la sede, 3: ejemplo de serie. */
					__( 'La sede %2$d no tiene una serie de %1$s válida (ej. %3$s). Configúrala en la sede.', 'multisede-pos' ),
					strtolower( self::dato_tipo( $tipo, 'corto' ) ),
					$sede_id,
					self::dato_tipo( $tipo, 'prefijo' ) . '001'
				)
			);
		}

		// Una factura sin RUC y razón social del comprador la rechaza SUNAT.
		// Mejor pararla aquí, antes de gastar un correlativo que luego habría
		// que dejar anulado, que descubrirlo en la respuesta del envío.
		if ( in_array( $tipo, array( 'factura', 'nc_factura' ), true ) ) {
			$num_doc = isset( $datos['cliente_num_doc'] ) ? preg_replace( '/[^0-9]/', '', (string) $datos['cliente_num_doc'] ) : '';
			$nombre  = isset( $datos['cliente_nombre'] ) ? trim( (string) $datos['cliente_nombre'] ) : '';
			if ( 11 !== strlen( $num_doc ) || '' === $nombre ) {
				return new WP_Error(
					'msp_factura_sin_comprador',
					__( 'Una factura (y su nota de crédito) necesita el RUC de 11 dígitos y la razón social del cliente.', 'multisede-pos' )
				);
			}
		}

		$tabla   = self::tabla();
		$ahora   = current_time( 'mysql' );
		$entorno = self::entorno_actual();

		// Con qué RUC emite esta sede. Se guarda en la fila y no se deduce
		// después: una sede puede cambiar de empresa, y un comprobante emitido
		// tiene que seguir perteneciendo al emisor con el que salió.
		$ruc = class_exists( 'MSP_Emisor' ) ? MSP_Emisor::ruc_de_sede( $sede_id ) : '';

		for ( $intento = 0; $intento < self::MAX_REINTENTOS_RESERVA; $intento++ ) {
			// Siguiente correlativo de ESTA serie EN ESTE ENTORNO (empieza en 1
			// si no hay ninguno). Beta y producción no comparten numeración.
			// El correlativo se cuenta por (emisor, serie, entorno): cada empresa
			// lleva su propia numeración, aunque dos usaran la misma serie.
			$max = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(correlativo) FROM {$tabla} WHERE ruc = %s AND serie = %s AND entorno = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ruc,
					$serie,
					$entorno
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
					'ruc'              => $ruc,
					'tipo'             => $tipo,
					'doc_afectado_id'  => isset( $datos['doc_afectado_id'] ) ? (int) $datos['doc_afectado_id'] : null,
					'motivo'           => isset( $datos['motivo'] ) ? substr( sanitize_text_field( $datos['motivo'] ), 0, 4 ) : '',
					'motivo_texto'     => isset( $datos['motivo_texto'] ) ? substr( sanitize_text_field( $datos['motivo_texto'] ), 0, 255 ) : '',
					'entorno'          => $entorno,
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
				array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s' )
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

		// Campos de la baja (Fase 4).
		if ( array_key_exists( 'baja_estado', $datos ) ) {
			$campos['baja_estado'] = sanitize_key( $datos['baja_estado'] );
			$formatos[]            = '%s';
		}
		if ( array_key_exists( 'anulado_at', $datos ) ) {
			$campos['anulado_at'] = $datos['anulado_at'] ? $datos['anulado_at'] : null;
			$formatos[]           = '%s';
		}
		if ( array_key_exists( 'resumen_id', $datos ) ) {
			$campos['resumen_id'] = (int) $datos['resumen_id'];
			$formatos[]           = '%d';
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
				'entorno' => '',
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
		if ( $args['entorno'] ) {
			$where[]  = 'entorno = %s';
			$params[] = sanitize_key( $args['entorno'] );
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
	 * @param string $entorno Limita el conteo a un entorno ('' = todos).
	 * @return array Mapa estado => cantidad.
	 */
	public static function contar_por_estado( $entorno = '' ) {
		global $wpdb;
		$tabla = self::tabla();

		$filas = $entorno
			? $wpdb->get_results(
				$wpdb->prepare(
					"SELECT estado, COUNT(*) AS n FROM {$tabla} WHERE entorno = %s GROUP BY estado", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					sanitize_key( $entorno )
				),
				ARRAY_A
			)
			: $wpdb->get_results(
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

		// Solo los del entorno activo. Un pendiente que quedó en beta no puede
		// reintentarse cuando el sitio ya apunta a producción: se emitiría de
		// verdad una boleta que era una prueba.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tabla}
				 WHERE estado IN ('pendiente','error')
				   AND entorno = %s
				   AND proximo_intento IS NOT NULL
				   AND proximo_intento <= %s
				 ORDER BY proximo_intento ASC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::entorno_actual(),
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

		// Solo el entorno activo: en producción, unas pruebas de beta olvidadas
		// no deben disparar la alarma. La alarma tiene que doler solo cuando hay
		// una venta real sin comprobante.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tabla}
				 WHERE estado IN ('pendiente','error','rechazado')
				   AND entorno = %s
				   AND emitido_at <= %s
				   AND alertado_at IS NULL
				 ORDER BY emitido_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::entorno_actual(),
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
