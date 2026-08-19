<?php
/**
 * Ajustes del checkout para Perú: cómo se llaman las cosas aquí.
 *
 * WooCommerce viene traducido de España, así que el campo nativo `city` sale
 * como **"Población"** y `state` como **"Región / Provincia"**. Ninguna de las
 * dos es la palabra que usa nadie en Perú: aquí una dirección es
 * **departamento** y **distrito**, y "provincia" significa otra cosa.
 *
 * No son campos nuevos: son los nativos de WooCommerce renombrados. Crear
 * campos propios habría roto el envío, los impuestos y las direcciones
 * guardadas de Mi cuenta, que se apoyan en los nativos.
 *
 * Se renombra en `woocommerce_default_address_fields` porque eso cubre de una
 * sola vez facturación, envío y las direcciones de Mi cuenta.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Etiquetas de dirección en peruano.
 */
class MSP_Checkout_PE {

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_filter( 'woocommerce_default_address_fields', array( $this, 'etiquetas' ), 20 );
	}

	/**
	 * ¿Hay otro plugin ocupándose ya de esto?
	 *
	 * El plugin de checkout peruano de Lucuma (el de Vaporis) renombra las
	 * mismas etiquetas y además convierte el distrito en un desplegable con los
	 * 1835 distritos. Si está instalado, manda él: dos filtros peleándose por la
	 * misma etiqueta acaban con una de las dos ganando por prioridad, que es la
	 * clase de detalle que nadie recuerda al depurar seis meses después.
	 *
	 * @return bool
	 */
	private function otro_plugin_manda() {
		return defined( 'VAPORIS_CHECKOUT_PE_DIR' ) || apply_filters( 'msp_omitir_etiquetas_pe', false );
	}

	/**
	 * Renombra los campos nativos de dirección.
	 *
	 * @param array $campos Campos por defecto de dirección.
	 * @return array
	 */
	public function etiquetas( $campos ) {
		if ( $this->otro_plugin_manda() ) {
			return $campos;
		}

		if ( isset( $campos['city'] ) ) {
			$campos['city']['label']       = __( 'Distrito', 'multisede-pos' );
			$campos['city']['placeholder'] = __( 'Ej. Barranco', 'multisede-pos' );
		}

		if ( isset( $campos['state'] ) ) {
			$campos['state']['label'] = __( 'Departamento', 'multisede-pos' );
		}

		return $campos;
	}
}
