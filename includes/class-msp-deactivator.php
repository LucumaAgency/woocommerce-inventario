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
	}
}
