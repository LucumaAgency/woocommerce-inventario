<?php
/**
 * Resúmenes diarios: el documento con el que se dan de baja las boletas.
 *
 * Fase 4. Una boleta electrónica no se borra ni se corrige: se comunica su baja
 * a SUNAT listándola en un **resumen diario** con estado `3`. Sin eso, anular una
 * venta en WooCommerce deja a saraih declarando ante SUNAT una venta que no
 * ocurrió, y pagando IGV por ella.
 *
 * Esta clase es solo la capa de datos. Quién decide cuándo enviar es MSP_Baja, y
 * quien habla con SUNAT es MSP_Emisor. Mismo reparto que en las boletas.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Acceso a la tabla de resúmenes diarios.
 */
class MSP_Resumen {

	/**
	 * Días de plazo para comunicar una baja.
	 *
	 * SUNAT da siete días calendario desde la emisión del comprobante. Pasados,
	 * la baja ya no se puede comunicar por esta vía y hay que emitir una nota de
	 * crédito, que es otro documento y otro trabajo.
	 */
	const DIAS_PLAZO = 7;

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'msp_resumenes';
	}

	/**
	 * Crea el resumen de un día de emisión, con su correlativo.
	 *
	 * El identificador es `RC-20260819-2`: tipo, **fecha en que se genera** el
	 * resumen y un número que empieza en 1 cada día. La fecha de las boletas que
	 * informa es otra cosa y vive en `fecha_referencia`.
	 *
	 * @param string $fecha Fecha de emisión de los comprobantes (Y-m-d).
	 * @return array|WP_Error Fila creada.
	 */
	public static function crear( $fecha ) {
		global $wpdb;

		$entorno = MSP_Comprobante::entorno_actual();
		$tabla   = self::tabla();
		$fecha   = gmdate( 'Y-m-d', strtotime( $fecha ) );

		// El identificador lleva la fecha en que se GENERA el resumen (hoy), no
		// la de las boletas que informa. Es lo que arma Greenter en `getXmlId()`
		// y por tanto el nombre real del documento ante SUNAT: si aquí
		// guardáramos la fecha de las boletas, el número de la pantalla no
		// coincidiría con el del archivo enviado. La fecha de las boletas vive
		// en `fecha_referencia`, que es lo que agrupa.
		//
		// Por lo mismo, el correlativo es del DÍA DE GENERACIÓN: dos resúmenes
		// creados hoy —uno de boletas de hoy y otro de ayer— son el 1 y el 2 de
		// hoy, no dos "número 1" de días distintos.
		$hoy = gmdate( 'Ymd', strtotime( current_time( 'mysql' ) ) );

		for ( $intento = 0; $intento < 20; $intento++ ) {
			$max = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(correlativo) FROM {$tabla} WHERE entorno = %s AND identificador LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$entorno,
					'RC-' . $hoy . '-%'
				)
			);
			$correlativo   = $max + 1;
			$identificador = sprintf( 'RC-%s-%d', $hoy, $correlativo );

			$suprimir = $wpdb->suppress_errors( true );
			$ok       = $wpdb->insert(
				$tabla,
				array(
					'entorno'          => $entorno,
					'identificador'    => $identificador,
					'fecha_referencia' => $fecha,
					'correlativo'      => $correlativo,
					'estado'           => 'pendiente',
					'creado_at'        => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			$wpdb->suppress_errors( $suprimir );

			if ( $ok ) {
				return self::obtener( (int) $wpdb->insert_id );
			}
		}

		return new WP_Error(
			'msp_resumen_ocupado',
			__( 'No se pudo crear el resumen diario tras varios intentos.', 'multisede-pos' )
		);
	}

	/**
	 * Actualiza campos del resumen.
	 *
	 * @param int   $id    ID del resumen.
	 * @param array $datos Campos.
	 * @return bool
	 */
	public static function actualizar( $id, $datos ) {
		global $wpdb;

		$campos   = array();
		$formatos = array();

		foreach ( array( 'estado', 'ticket', 'ultimo_error', 'xml_path', 'cdr_path' ) as $campo ) {
			if ( array_key_exists( $campo, $datos ) ) {
				$campos[ $campo ] = (string) $datos[ $campo ];
				$formatos[]       = '%s';
			}
		}
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
	 * Obtiene un resumen por su ID.
	 *
	 * @param int $id ID.
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
	 * Últimos resúmenes del entorno activo, para la pantalla.
	 *
	 * @param int $limite Máximo de filas.
	 * @return array
	 */
	public static function ultimos( $limite = 20 ) {
		global $wpdb;
		$tabla = self::tabla();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tabla} WHERE entorno = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				MSP_Comprobante::entorno_actual(),
				(int) $limite
			),
			ARRAY_A
		);
	}

	/**
	 * Resúmenes que toca reintentar o cuyo ticket toca consultar.
	 *
	 * @param int $limite Máximo de filas.
	 * @return array
	 */
	public static function pendientes( $limite = 10 ) {
		global $wpdb;
		$tabla = self::tabla();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tabla}
				 WHERE entorno = %s
				   AND estado IN ('pendiente','enviado','error')
				   AND proximo_intento IS NOT NULL
				   AND proximo_intento <= %s
				 ORDER BY proximo_intento ASC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				MSP_Comprobante::entorno_actual(),
				current_time( 'mysql' ),
				(int) $limite
			),
			ARRAY_A
		);
	}

	/**
	 * Comprobantes de este resumen.
	 *
	 * @param int $resumen_id ID del resumen.
	 * @return array
	 */
	public static function comprobantes( $resumen_id ) {
		global $wpdb;
		$tabla = MSP_Comprobante::tabla();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tabla} WHERE resumen_id = %d ORDER BY correlativo ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $resumen_id
			),
			ARRAY_A
		);
	}

	/**
	 * Días que quedan de plazo para comunicar la baja de un comprobante.
	 *
	 * @param array $comprobante Fila del comprobante.
	 * @return int Días restantes (negativo si ya venció).
	 */
	public static function dias_de_plazo( $comprobante ) {
		$emitido = strtotime( $comprobante['emitido_at'] );
		if ( ! $emitido ) {
			return self::DIAS_PLAZO;
		}
		$vence      = $emitido + ( self::DIAS_PLAZO * DAY_IN_SECONDS );
		$ahora      = strtotime( current_time( 'mysql' ) );
		return (int) floor( ( $vence - $ahora ) / DAY_IN_SECONDS );
	}
}
