<?php
/**
 * Activación: crea tablas, roles y registra la versión del esquema.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tareas que se ejecutan al activar el plugin.
 */
class MSP_Activator {

	/**
	 * Versión del esquema de base de datos.
	 */
	const DB_VERSION = '9';

	/**
	 * Aplica el esquema si cambió desde la última vez.
	 *
	 * Git Updater no dispara el hook de activación al actualizar, así que sin
	 * esto una columna nueva no llegaría nunca a las instalaciones existentes.
	 * dbDelta es idempotente: si el esquema ya está al día, no hace nada.
	 *
	 * Corre en CUALQUIER tipo de petición (incluidas AJAX y cron) a propósito.
	 * Restringirlo al admin dejaba una ventana entre la actualización y la
	 * primera carga del panel en la que el código ya consultaba columnas que
	 * todavía no existían: un cobro del POS (que va por AJAX) fallaba en
	 * silencio y el efectivo de esa venta no entraba a la caja.
	 */
	public static function migrar_db() {
		if ( get_option( 'msp_db_version' ) === self::DB_VERSION ) {
			return;
		}

		// Lock para que dos peticiones simultáneas no lancen dbDelta a la vez.
		if ( get_transient( 'msp_migrando_db' ) ) {
			return;
		}
		set_transient( 'msp_migrando_db', 1, 30 );

		self::crear_tablas();
		self::reparar_series_1260();
		update_option( 'msp_db_version', self::DB_VERSION );

		delete_transient( 'msp_migrando_db' );
	}

	/**
	 * Repara los comprobantes que la v1.26.0 guardó con los datos corridos.
	 *
	 * Al reservar faltaba un formato en el INSERT y wpdb corrió los demás una
	 * posición: la serie quedó en 0, el nombre del cliente en 0 y la fecha de
	 * emisión vacía. Un comprobante así no lo acepta SUNAT y el ticket sale sin
	 * número.
	 *
	 * Se toma de nuevo la serie de la sede y el SIGUIENTE correlativo libre de
	 * esa serie (no el que tenía: se calculó contra la serie buena, así que dos
	 * filas rotas seguidas pueden compartirlo). Nunca se toca un comprobante
	 * aceptado. Es idempotente: solo actúa sobre series inválidas.
	 */
	private static function reparar_series_1260() {
		global $wpdb;

		if ( ! class_exists( 'MSP_Comprobante' ) ) {
			return;
		}

		$tabla = MSP_Comprobante::tabla();
		$filas = $wpdb->get_results(
			"SELECT * FROM {$tabla} WHERE serie NOT REGEXP '^[A-Z][0-9A-Z]{3}$' AND estado <> 'aceptado' ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( empty( $filas ) ) {
			return;
		}

		$reparados = array();

		foreach ( $filas as $c ) {
			$serie = MSP_Comprobante::serie_de_sede( (int) $c['sede_id'], $c['tipo'] );
			if ( ! MSP_Comprobante::serie_valida( $serie, $c['tipo'] ) ) {
				continue;
			}

			$order = ( $c['pedido_id'] && function_exists( 'wc_get_order' ) ) ? wc_get_order( (int) $c['pedido_id'] ) : null;

			$nombre = trim( (string) $c['cliente_nombre'] );
			if ( '' === $nombre || '0' === $nombre ) {
				$nombre = 'CLIENTE VARIOS';
				if ( ! empty( $c['doc_afectado_id'] ) ) {
					$afectado = MSP_Comprobante::obtener( (int) $c['doc_afectado_id'] );
					if ( $afectado && '' !== trim( (string) $afectado['cliente_nombre'] ) && '0' !== $afectado['cliente_nombre'] ) {
						$nombre = $afectado['cliente_nombre'];
					}
				} elseif ( $order && class_exists( 'MSP_Cola' ) ) {
					$nombre = MSP_Cola::nombre_cliente( $order );
				}
			}

			$emitido = (string) $c['emitido_at'];
			if ( '' === $emitido || 0 === strpos( $emitido, '0000' ) ) {
				$emitido = ( $order && $order->get_date_created() )
					? $order->get_date_created()->date( 'Y-m-d H:i:s' )
					: current_time( 'mysql' );
			}

			for ( $intento = 0; $intento < 10; $intento++ ) {
				$max = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT MAX(correlativo) FROM {$tabla} WHERE ruc = %s AND serie = %s AND entorno = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$c['ruc'],
						$serie,
						$c['entorno']
					)
				);

				$suprimir = $wpdb->suppress_errors( true );
				$ok       = $wpdb->update(
					$tabla,
					array(
						'serie'           => $serie,
						'correlativo'     => $max + 1,
						'cliente_nombre'  => substr( $nombre, 0, 255 ),
						'emitido_at'      => $emitido,
						'estado'          => 'pendiente',
						'intentos'        => 0,
						'ultimo_error'    => null,
						'hash'            => '',
						'xml_path'        => '',
						'cdr_path'        => '',
						'proximo_intento' => current_time( 'mysql' ),
					),
					array( 'id' => (int) $c['id'] ),
					array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
				$wpdb->suppress_errors( $suprimir );

				if ( false !== $ok ) {
					$c['serie']       = $serie;
					$c['correlativo'] = $max + 1;
					$reparados[]      = (int) $c['id'];

					if ( $order ) {
						$order->add_order_note(
							sprintf(
								/* translators: %s: número del comprobante. */
								__( 'Comprobante reparado (fallo de la v1.26.0: se había guardado sin serie). Ahora es %s y se reenvía a SUNAT.', 'multisede-pos' ),
								MSP_Comprobante::numero( $c )
							)
						);
					}
					break;
				}
			}
		}

		// La cola usa Action Scheduler, que no está listo en init prioridad 1.
		// Se programan cuando WordPress terminó de cargar; si eso fallara, el
		// barrido horario los recoge igual por su proximo_intento.
		if ( $reparados && class_exists( 'MSP_Cola' ) ) {
			add_action(
				'wp_loaded',
				function () use ( $reparados ) {
					foreach ( $reparados as $id ) {
						MSP_Cola::programar( $id, 30 );
					}
				}
			);
		}
	}

	/**
	 * Punto de entrada de activación.
	 */
	public static function activate() {
		self::crear_tablas();
		MSP_Roles::crear_roles();
		update_option( 'msp_roles_version', MSP_Roles::ROLES_VERSION );

		// Registramos el CPT antes de refrescar las reglas de reescritura.
		MSP_Sedes::registrar_cpt();
		flush_rewrite_rules();

		update_option( 'msp_db_version', self::DB_VERSION );

		// Programa la redirección al asistente tras activar.
		MSP_Wizard::marcar_redireccion();
	}

	/**
	 * Crea las tablas propias del plugin con dbDelta.
	 *
	 * Nota: el stock por sede y la caja se definen aquí para tener el
	 * esquema completo desde el inicio (las usan las fases 2 y 5).
	 */
	public static function crear_tablas() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		// Stock por sede.
		$sql_stock = "CREATE TABLE {$prefix}msp_stock (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			producto_id BIGINT(20) UNSIGNED NOT NULL,
			sede_id BIGINT(20) UNSIGNED NOT NULL,
			stock INT(11) NOT NULL DEFAULT 0,
			stock_reservado INT(11) NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY producto_sede (producto_id, sede_id),
			KEY sede_id (sede_id)
		) {$charset_collate};";

		// Sesiones de caja.
		$sql_caja_sesiones = "CREATE TABLE {$prefix}msp_caja_sesiones (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			sede_id BIGINT(20) UNSIGNED NOT NULL,
			cajero_id BIGINT(20) UNSIGNED NOT NULL,
			monto_apertura DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			monto_cierre_esperado DECIMAL(10,2) NULL DEFAULT NULL,
			monto_cierre_contado DECIMAL(10,2) NULL DEFAULT NULL,
			diferencia DECIMAL(10,2) NULL DEFAULT NULL,
			estado VARCHAR(20) NOT NULL DEFAULT 'abierta',
			es_practica TINYINT(1) NOT NULL DEFAULT 0,
			abierta_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			cerrada_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY sede_id (sede_id),
			KEY ruc (ruc),
			KEY doc_afectado_id (doc_afectado_id),
			KEY cajero_id (cajero_id),
			KEY estado (estado),
			KEY es_practica (es_practica)
		) {$charset_collate};";

		// Movimientos de caja.
		$sql_caja_movimientos = "CREATE TABLE {$prefix}msp_caja_movimientos (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			sesion_id BIGINT(20) UNSIGNED NOT NULL,
			tipo VARCHAR(20) NOT NULL,
			concepto VARCHAR(255) NOT NULL DEFAULT '',
			monto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			pedido_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			creado_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY sesion_id (sesion_id),
			KEY pedido_id (pedido_id)
		) {$charset_collate};";

		// Comprobantes electrónicos (boletas SUNAT). Fase 1 de facturación.
		// El correlativo se reserva insertando una fila: el índice UNIQUE
		// (entorno, serie, correlativo) impide repetir o saltar números aunque
		// dos cajeros emitan a la vez. Mismo principio que descontar_si_hay.
		//
		// El `entorno` forma parte de la clave desde la v1.9.1: beta y
		// producción llevan numeraciones separadas. Sin eso, cada boleta de
		// prueba gastaba un número de la serie real y la primera boleta de
		// verdad salía con el correlativo veintitantos.
		$sql_comprobantes = "CREATE TABLE {$prefix}msp_comprobantes (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			pedido_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			sede_id BIGINT(20) UNSIGNED NOT NULL,
			ruc VARCHAR(11) NOT NULL DEFAULT '',
			tipo VARCHAR(20) NOT NULL DEFAULT 'boleta',
			entorno VARCHAR(12) NOT NULL DEFAULT 'beta',
			serie VARCHAR(4) NOT NULL,
			correlativo INT(11) UNSIGNED NOT NULL,
			doc_afectado_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			motivo VARCHAR(4) NOT NULL DEFAULT '',
			motivo_texto VARCHAR(255) NOT NULL DEFAULT '',
			cliente_tipo_doc VARCHAR(2) NOT NULL DEFAULT '0',
			cliente_num_doc VARCHAR(20) NOT NULL DEFAULT '',
			cliente_nombre VARCHAR(255) NOT NULL DEFAULT '',
			total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			igv DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
			intentos INT(11) NOT NULL DEFAULT 0,
			ultimo_error TEXT NULL DEFAULT NULL,
			hash VARCHAR(64) NOT NULL DEFAULT '',
			proximo_intento DATETIME NULL DEFAULT NULL,
			alertado_at DATETIME NULL DEFAULT NULL,
			baja_estado VARCHAR(20) NOT NULL DEFAULT '',
			resumen_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			anulado_at DATETIME NULL DEFAULT NULL,
			xml_path VARCHAR(255) NOT NULL DEFAULT '',
			cdr_path VARCHAR(255) NOT NULL DEFAULT '',
			pdf_url VARCHAR(255) NOT NULL DEFAULT '',
			emitido_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			enviado_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY emisor_serie_correlativo (entorno, ruc, serie, correlativo),
			KEY pedido_id (pedido_id),
			KEY sede_id (sede_id),
			KEY ruc (ruc),
			KEY doc_afectado_id (doc_afectado_id),
			KEY estado (estado),
			KEY proximo_intento (proximo_intento),
			KEY baja_estado (baja_estado)
		) {$charset_collate};";

		// Resúmenes diarios (Fase 4). Una boleta no se borra: se comunica su
		// baja en un resumen diario donde va listada con estado 3. El envío es
		// asíncrono —SUNAT devuelve un ticket y el resultado se consulta
		// después—, así que el ticket vive aquí junto con su estado.
		$sql_resumenes = "CREATE TABLE {$prefix}msp_resumenes (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			entorno VARCHAR(12) NOT NULL DEFAULT 'beta',
			ruc VARCHAR(11) NOT NULL DEFAULT '',
			identificador VARCHAR(20) NOT NULL,
			fecha_referencia DATE NOT NULL,
			correlativo INT(11) UNSIGNED NOT NULL DEFAULT 1,
			estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
			ticket VARCHAR(64) NOT NULL DEFAULT '',
			intentos INT(11) NOT NULL DEFAULT 0,
			ultimo_error TEXT NULL DEFAULT NULL,
			proximo_intento DATETIME NULL DEFAULT NULL,
			alertado_at DATETIME NULL DEFAULT NULL,
			xml_path VARCHAR(255) NOT NULL DEFAULT '',
			cdr_path VARCHAR(255) NOT NULL DEFAULT '',
			creado_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			enviado_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY entorno_identificador (entorno, identificador),
			KEY estado (estado),
			KEY proximo_intento (proximo_intento)
		) {$charset_collate};";

		dbDelta( $sql_stock );
		dbDelta( $sql_caja_sesiones );
		dbDelta( $sql_caja_movimientos );
		// Solicitudes de nota de crédito. Van en su propia tabla y NO en
		// msp_comprobantes a propósito: una solicitud es un trámite interno que
		// puede rechazarse, y un comprobante es un documento fiscal con
		// numeración. Mezclarlos obligaría a reservar un correlativo al pedirla
		// y a quemarlo si el gerente dice que no.
		$sql_notas = "CREATE TABLE {$prefix}msp_notas (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			comprobante_id BIGINT(20) UNSIGNED NOT NULL,
			pedido_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			sede_id BIGINT(20) UNSIGNED NOT NULL,
			motivo VARCHAR(4) NOT NULL DEFAULT '',
			detalle TEXT NULL DEFAULT NULL,
			lineas LONGTEXT NULL DEFAULT NULL,
			total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			devolver_stock TINYINT(1) NOT NULL DEFAULT 1,
			estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
			solicitante_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			solicitado_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			revisor_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			revisado_at DATETIME NULL DEFAULT NULL,
			respuesta VARCHAR(255) NOT NULL DEFAULT '',
			nota_comprobante_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY comprobante_id (comprobante_id),
			KEY sede_id (sede_id),
			KEY estado (estado)
		) {$charset_collate};";

		dbDelta( $sql_comprobantes );
		dbDelta( $sql_resumenes );
		dbDelta( $sql_notas );

		self::migrar_indice_comprobantes();
	}

	/**
	 * Pone al día el índice único de comprobantes.
	 *
	 * La clave ha crecido dos veces, y cada vez el índice anterior estorba:
	 *
	 * - `serie_correlativo` — el original. Mientras siguiera puesto, beta y
	 *   producción no podrían compartir un número (v1.9.1).
	 * - `entorno_serie_correlativo` — el de la v1.9.1. Impide que dos EMISORES
	 *   distintos usen la misma serie, que es legítimo: la B100 del RUC A y la
	 *   B100 del RUC B son documentos distintos ante SUNAT (v1.24.0).
	 *
	 * dbDelta sabe crear índices nuevos pero no borrar los que sobran, así que
	 * los viejos hay que quitarlos a mano. Se hace después de dbDelta y solo si
	 * el índice nuevo existe: si su creación hubiera fallado, quitar los
	 * anteriores dejaría la tabla sin ninguna red contra correlativos
	 * duplicados, que es el peor escenario posible.
	 */
	private static function migrar_indice_comprobantes() {
		global $wpdb;

		$tabla = $wpdb->prefix . 'msp_comprobantes';

		// Las filas anteriores al multi-emisor no tienen RUC. Se rellenan con el
		// emisor configurado, que hasta ahora era el único que podía emitirlas.
		// Sin esto quedarían fuera de cualquier consulta por emisor: invisibles
		// para el resumen de bajas y para el correlativo siguiente de su serie.
		if ( class_exists( 'MSP_Emisor' ) ) {
			$a = MSP_Emisor::ajustes();
			if ( ! empty( $a['ruc'] ) && self::columna_existe( $tabla, 'ruc' ) ) {
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"UPDATE {$tabla} SET ruc = %s WHERE ruc = ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$a['ruc']
					)
				);
			}
		}

		$existe_nuevo = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM {$tabla} WHERE Key_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'emisor_serie_correlativo'
			)
		);
		if ( ! $existe_nuevo ) {
			return;
		}

		foreach ( array( 'serie_correlativo', 'entorno_serie_correlativo' ) as $indice ) {
			$existe = $wpdb->get_var(
				$wpdb->prepare(
					"SHOW INDEX FROM {$tabla} WHERE Key_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$indice
				)
			);
			if ( $existe ) {
				$wpdb->query( "ALTER TABLE {$tabla} DROP INDEX `" . esc_sql( $indice ) . "`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/**
	 * ¿Existe esa columna en la tabla?
	 *
	 * @param string $tabla   Tabla.
	 * @param string $columna Columna.
	 * @return bool
	 */
	private static function columna_existe( $tabla, $columna ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$tabla} LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$columna
			)
		);
	}
}
