<?php
/**
 * Plugin Name:       Multisede POS
 * Plugin URI:        https://github.com/LucumaAgency/woocommerce-inventario
 * Description:        Inventario por sede, recojo en tienda, POS de mostrador y caja chica para WooCommerce.
 * Version:           0.3.0
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
define( 'MSP_VERSION', '0.3.0' );
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

// Carga de clases.
require_once MSP_PLUGIN_DIR . 'includes/class-msp-roles.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-activator.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-deactivator.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-sedes.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-stock.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-recojo.php';
require_once MSP_PLUGIN_DIR . 'includes/class-msp-wizard.php';
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
