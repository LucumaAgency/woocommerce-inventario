<?php
/**
 * Plugin Name:       Multisede POS
 * Plugin URI:        https://github.com/LucumaAgency/woocommerce-inventario
 * Description:        Inventario por sede, recojo en tienda, POS de mostrador y caja chica para WooCommerce.
 * Version:           1.20.3
 * Author:            Lucuma Agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       multisede-pos
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * GitHub Plugin URI: LucumaAgency/woocommerce-inventario
 * Primary Branch:    main
 *
 * @package Multisede_POS
 */

// Salida si se accede directamente.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constantes del plugin.
define( 'MSP_VERSION', '1.20.3' );
define( 'MSP_PLUGIN_FILE', __FILE__ );
define( 'MSP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MSP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Comprueba que WooCommerce esté activo antes de arrancar.
 *
 * @return bool
 */
function msp_woocommerce_activo() {
	return in_array(
		'woocommerce/woocommerce.php',
		apply_filters( 'active_plugins', (array) get_option( 'active_plugins', array() ) ),
		true
	) || is_plugin_active_for_network( 'woocommerce/woocommerce.php' );
}

/**
 * Carga las dependencias de Composer (Greenter) si están presentes.
 *
 * Con guarda a propósito: si `vendor/` falta o llega incompleto en un deploy,
 * el módulo de facturación se queda desactivado y el plugin sigue vendiendo e
 * inventariando como siempre. Un error de despliegue no puede dejar a la tienda
 * sin POS ni sin caja.
 *
 * @return bool
 */
function msp_cargar_dependencias() {
	static $cargado = null;

	if ( null !== $cargado ) {
		return $cargado;
	}

	$autoload = MSP_PLUGIN_DIR . 'vendor/autoload.php';
	if ( ! file_exists( $autoload ) ) {
		$cargado = false;
		return $cargado;
	}

	require_once $autoload;
	// Greenter\See es la clase que usa el motor de emisión: si no está, el
	// vendor llegó a medias y es mejor saberlo aquí que al firmar un XML.
	$cargado = class_exists( '\Greenter\See' );
	return $cargado;
}

/**
 * ¿Está disponible el motor de facturación electrónica?
 *
 * @return bool
 */
function msp_facturacion_disponible() {
	return msp_cargar_dependencias();
}

msp_cargar_dependencias();

// Aviso en el panel si las dependencias no llegaron con el deploy.
add_action(
	'admin_notices',
	function () {
		if ( msp_facturacion_disponible() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Multisede POS:', 'multisede-pos' ),
			esc_html__( 'el módulo de facturación electrónica está desactivado porque faltan sus dependencias (carpeta vendor). El resto del plugin funciona con normalidad.', 'multisede-pos' )
		);
	}
);

// Carga de clases.
require_once MSP_PLUGIN_DIR . 'includes/class-msp-roles.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-activator.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-deactivator.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-sedes.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-stock.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-recojo.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-frontend.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-pos.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-caja.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-comprobante.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-emisor.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-resumen.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-cola.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-baja.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-ticket.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-cajas-abiertas.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-entregas.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-checkout-pe.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-pruebas.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-diagnostico.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-comprobantes.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-facturacion.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-inventario.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-wizard.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-ayuda.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-plugin.php';

// Activación / desactivación.
register_activation_hook( __FILE__, array( 'MSP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MSP_Deactivator', 'deactivate' ) );

/**
 * Arranque del plugin.
 */
function msp_run() {
	// Aviso si falta WooCommerce.
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! msp_woocommerce_activo() ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'Multisede POS necesita que WooCommerce esté instalado y activo.', 'multisede-pos' );
				echo '</p></div>';
			}
		);
		return;
	}

	$plugin = new MSP_Plugin();
	$plugin->run();
}
add_action( 'plugins_loaded', 'msp_run' );
