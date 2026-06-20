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

		// Módulo de sedes (Fase 1).
		$sedes = new MSP_Sedes();
		$sedes->init();

		// Inventario multi-sede (Fase 2).
		$stock = new MSP_Stock();
		$stock->init();

		// Recojo en tienda (Fase 3).
		$recojo = new MSP_Recojo();
		$recojo->init();

		// POS de mostrador (Fase 4).
		$pos = new MSP_POS();
		$pos->init();

		// Caja chica (Fase 5).
		$caja = new MSP_Caja();
		$caja->init();

		// Asistente de configuración (wizard).
		if ( is_admin() ) {
			$wizard = new MSP_Wizard();
			$wizard->init();
		}

		// Las fases siguientes (stock, recojo, POS, caja) se engancharán aquí.
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
