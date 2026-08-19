<?php
/**
 * Bootstrap del plugin: carga textdomain y arranca los módulos.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orquestador principal.
 */
class MSP_Plugin {

	/**
	 * Engancha todo.
	 */
	public function run() {
		add_action( 'init', array( $this, 'cargar_textdomain' ) );

		// Aplica cambios de esquema al actualizar (Git Updater no dispara el
		// hook de activación).
		add_action( 'init', array( 'MSP_Activator', 'migrar_db' ), 1 );

		// Roles, capacidades y asignación usuario↔sede.
		$roles = new MSP_Roles();
		$roles->init();

		// Módulo de sedes (Fase 1).
		$sedes = new MSP_Sedes();
		$sedes->init();

		// Inventario multi-sede (Fase 2).
		$stock = new MSP_Stock();
		$stock->init();

		// Recojo en tienda (Fase 3).
		$recojo = new MSP_Recojo();
		$recojo->init();

		// Compra por tienda en el frontend (stock de la sede elegida).
		$frontend = new MSP_Frontend();
		$frontend->init();

		// POS de mostrador (Fase 4).
		$pos = new MSP_POS();
		$pos->init();

		// Caja chica (Fase 5).
		$caja = new MSP_Caja();
		$caja->init();

		// Cola de emisión electrónica (Fase 3). Fuera del bloque de admin a
		// propósito: la venta del POS llega por AJAX y el trabajo de fondo por
		// cron, y ninguno de los dos es una pantalla del panel.
		$cola = new MSP_Cola();
		$cola->init();

		// Bajas de comprobantes (Fase 4): la contraparte de la cola.
		$baja = new MSP_Baja();
		$baja->init();

		// Ticket imprimible (Fase 5). Fuera del bloque de admin: lo sirve
		// admin-post, y quien más lo usa es el cajero.
		$ticket = new MSP_Ticket();
		$ticket->init();

		// Pantallas de solo-admin: inventario, asistente y ayuda.
		if ( is_admin() ) {
			$comprobantes = new MSP_Comprobantes();
			$comprobantes->init();

			$inventario = new MSP_Inventario();
			$inventario->init();

			$wizard = new MSP_Wizard();
			$wizard->init();

			$ayuda = new MSP_Ayuda();
			$ayuda->init();

			$facturacion = new MSP_Facturacion();
			$facturacion->init();
		}
	}

	/**
	 * Carga las traducciones.
	 */
	public function cargar_textdomain() {
		load_plugin_textdomain(
			'multisede-pos',
			false,
			dirname( MSP_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
