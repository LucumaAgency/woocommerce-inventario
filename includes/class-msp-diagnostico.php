<?php
/**
 * Diagnóstico de configuración: lo que está mal puesto y nadie va a notar.
 *
 * Hay dos ajustes de WooCommerce que, mal configurados, rompen el plugin de
 * formas que **no parecen un fallo del plugin**: la web deja de vender stock que
 * sí hay, o el cliente va a la tienda equivocada. Los dos salieron del recorrido
 * de verificación (hallazgos G y B de `AVANCE.md` §4 quinquies), y los dos se
 * arreglan en un ajuste, no en el código.
 *
 * El problema de las cosas que se arreglan con un ajuste es que dependen de que
 * alguien se acuerde. Esto las detecta y las dice en pantalla, que es la única
 * forma de que no vuelvan a pasar.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Avisos de configuración incorrecta.
 */
class MSP_Diagnostico {

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_notices', array( $this, 'avisos' ) );
	}

	/**
	 * Problemas de configuración detectados.
	 *
	 * Público para poder comprobarlo desde el banco de pruebas.
	 *
	 * @return array Lista de {clave, titulo, detalle, url, enlace}.
	 */
	public static function problemas() {
		$problemas = array();

		// Sin sedes configuradas no aplica nada de esto: el sitio todavía no
		// opera por tiendas.
		if ( ! class_exists( 'MSP_Sedes' ) || ! MSP_Sedes::obtener_sedes_activas() ) {
			return $problemas;
		}

		// ── G · La retención de WooCommerce duplica la reserva del plugin ────
		$retencion = get_option( 'woocommerce_hold_stock_minutes' );

		if ( '' !== $retencion && null !== $retencion && (int) $retencion > 0 ) {
			$problemas[] = array(
				'clave'   => 'hold_stock',
				'titulo'  => __( 'La web está bloqueando stock que sí está libre.', 'multisede-pos' ),
				'detalle' => sprintf(
					/* translators: %d: minutos de retención configurados. */
					__( 'WooCommerce retiene existencias %d minutos por cada pedido pendiente de pago, y el plugin ya aparta esas unidades al hacerse el pedido. La misma unidad se cuenta dos veces, así que cada pedido pendiente consume el doble de capacidad de la que debería. Deja el campo "Retener existencias (minutos)" vacío: el plugin ya hace ese trabajo.', 'multisede-pos' ),
					(int) $retencion
				),
				'url'     => admin_url( 'admin.php?page=wc-settings&tab=products&section=inventory' ),
				'enlace'  => __( 'Ajustes → Productos → Inventario', 'multisede-pos' ),
			);
		}

		// ── B · Varios métodos de recogida: el cliente lee una tienda y su
		// pedido se reserva en otra ──────────────────────────────────────────
		$recogidas = self::metodos_de_recogida();

		if ( count( $recogidas ) > 1 ) {
			$problemas[] = array(
				'clave'   => 'local_pickup',
				'titulo'  => __( 'Hay varios métodos de recogida configurados.', 'multisede-pos' ),
				'detalle' => sprintf(
					/* translators: %s: lista de métodos. */
					__( 'El cliente lee en el carrito el método de WooCommerce (%s), pero quien reserva el stock y queda en el pedido es la tienda que eligió con el selector del plugin. Si no coinciden, va a ir a la tienda equivocada. Deja UN solo método de recogida con nombre genérico, como "Recojo en tienda": existe solo para poder cerrar la compra sin dirección de entrega, no decide nada.', 'multisede-pos' ),
					implode( ', ', $recogidas )
				),
				'url'     => admin_url( 'admin.php?page=wc-settings&tab=shipping' ),
				'enlace'  => __( 'Ajustes → Envío', 'multisede-pos' ),
			);
		}

		return $problemas;
	}

	/**
	 * Nombres de los métodos de recogida local activos, en todas las zonas.
	 *
	 * @return string[]
	 */
	private static function metodos_de_recogida() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}

		$nombres = array();
		$zonas   = WC_Shipping_Zones::get_zones();

		// La zona 0 («resto del mundo») no viene en get_zones() y suele ser
		// justo donde se pone el método genérico.
		$zonas[] = array( 'shipping_methods' => WC_Shipping_Zones::get_zone( 0 )->get_shipping_methods( true ) );

		foreach ( $zonas as $zona ) {
			if ( empty( $zona['shipping_methods'] ) ) {
				continue;
			}
			foreach ( $zona['shipping_methods'] as $metodo ) {
				if ( 'local_pickup' !== $metodo->id || 'yes' !== $metodo->enabled ) {
					continue;
				}
				$nombres[] = $metodo->get_title();
			}
		}

		return $nombres;
	}

	/**
	 * Pinta los avisos, solo a quien puede arreglarlos.
	 */
	public function avisos() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Solo en las pantallas del plugin: un aviso que sale en todo el panel
		// se convierte en ruido y se deja de leer, que es justo lo contrario de
		// lo que se busca.
		$pantalla = get_current_screen();
		if ( ! $pantalla || false === strpos( (string) $pantalla->id, 'msp-' ) ) {
			return;
		}

		foreach ( self::problemas() as $problema ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong><br>%2$s<br><a href="%3$s">%4$s →</a></p></div>',
				esc_html( $problema['titulo'] ),
				esc_html( $problema['detalle'] ),
				esc_url( $problema['url'] ),
				esc_html( $problema['enlace'] )
			);
		}
	}
}
