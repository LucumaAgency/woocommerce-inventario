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
	const DB_VERSION = '3';

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
		// (serie, correlativo) impide repetir o saltar números aunque dos
		// cajeros emitan a la vez. Mismo principio que descontar_si_hay del stock.
		$sql_comprobantes = "CREATE TABLE {$prefix}msp_comprobantes (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			pedido_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			sede_id BIGINT(20) UNSIGNED NOT NULL,
			tipo VARCHAR(20) NOT NULL DEFAULT 'boleta',
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
			xml_path VARCHAR(255) NOT NULL DEFAULT '',
			cdr_path VARCHAR(255) NOT NULL DEFAULT '',
			pdf_url VARCHAR(255) NOT NULL DEFAULT '',
			emitido_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			enviado_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY serie_correlativo (serie, correlativo),
			KEY pedido_id (pedido_id),
			KEY sede_id (sede_id),
			KEY estado (estado)
		) {$charset_collate};";

		dbDelta( $sql_stock );
		dbDelta( $sql_caja_sesiones );
		dbDelta( $sql_caja_movimientos );
		dbDelta( $sql_comprobantes );
	}
}
