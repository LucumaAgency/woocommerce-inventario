<?php
/**
 * Desactivación del plugin.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tareas al desactivar. No borra datos (eso es tarea de uninstall.php).
 */
class MSP_Deactivator {

	/**
	 * Punto de entrada de desactivación.
	 */
	public static function deactivate() {
		flush_rewrite_rules();

		// Sin esto, el barrido de comprobantes seguiría programado contra un
		// plugin apagado y llenaría el log de errores.
		if ( class_exists( 'MSP_Cola' ) ) {
			MSP_Cola::limpiar();
		}
		if ( class_exists( 'MSP_Baja' ) ) {
			MSP_Baja::limpiar();
		}
	}
}
