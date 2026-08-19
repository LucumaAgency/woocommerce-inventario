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
	const DB_VERSION = '6';

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
		update_option( 'msp_db_version', self::DB_VERSION );

		delete_transient( 'msp_migrando_db' );
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
			tipo VARCHAR(20) NOT NULL DEFAULT 'boleta',
			entorno VARCHAR(12) NOT NULL DEFAULT 'beta',
			serie VARCHAR(4) NOT NULL,
			correlativo INT(11) UNSIGNED NOT NULL,
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
			UNIQUE KEY entorno_serie_correlativo (entorno, serie, correlativo),
			KEY pedido_id (pedido_id),
			KEY sede_id (sede_id),
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
		dbDelta( $sql_comprobantes );
		dbDelta( $sql_resumenes );

		self::migrar_indice_comprobantes();
	}

	/**
	 * Retira el índice único antiguo de comprobantes (serie, correlativo).
	 *
	 * dbDelta sabe crear índices nuevos pero no borrar los que sobran, así que
	 * el viejo hay que quitarlo a mano. Mientras siga puesto, beta y producción
	 * no pueden compartir un número: justo lo que la v1.9.1 quiere permitir.
	 *
	 * Se hace después de dbDelta y comprobando que el índice nuevo exista: si
	 * la creación hubiera fallado, quitar el viejo dejaría la tabla sin ninguna
	 * red contra correlativos duplicados, que es el peor escenario posible.
	 */
	private static function migrar_indice_comprobantes() {
		global $wpdb;

		$tabla = $wpdb->prefix . 'msp_comprobantes';

		$existe_nuevo = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM {$tabla} WHERE Key_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'entorno_serie_correlativo'
			)
		);
		if ( ! $existe_nuevo ) {
			return;
		}

		$existe_viejo = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM {$tabla} WHERE Key_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'serie_correlativo'
			)
		);
		if ( $existe_viejo ) {
			$wpdb->query( "ALTER TABLE {$tabla} DROP INDEX serie_correlativo" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}
}
