<?php
/**
 * Frontend "compra por tienda": el cliente elige una sede y solo ve y
 * compra el stock de esa sede.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hace que la tienda online opere contra una sola sede (la elegida).
 */
class MSP_Frontend {

	const SESSION_KEY = 'msp_sede_activa';

	/**
	 * Cache de la sede activa por petición.
	 *
	 * @var int|null
	 */
	private static $sede_cache = null;

	/**
	 * Engancha hooks.
	 */
	public function init() {
		// Selector y captura de la sede elegida.
		add_shortcode( 'msp_selector_sede', array( $this, 'shortcode_selector' ) );
		add_action( 'template_redirect', array( $this, 'capturar_sede' ) );

		// Mostrar la tienda elegida (o pedir elegir) en páginas de WooCommerce.
		add_action( 'woocommerce_before_main_content', array( $this, 'banner_sede' ), 5 );

		// El stock que ve la web es el de la sede elegida.
		add_filter( 'woocommerce_product_get_stock_quantity', array( $this, 'stock_de_sede' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_stock_quantity', array( $this, 'stock_de_sede' ), 10, 2 );
		add_filter( 'woocommerce_product_is_in_stock', array( $this, 'en_stock_en_sede' ), 10, 2 );
		add_filter( 'woocommerce_get_availability_text', array( $this, 'texto_disponibilidad' ), 10, 2 );

		// Validación al agregar al carrito y en el carrito.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validar_agregar' ), 10, 4 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validar_carrito' ) );
	}

	/* ---------------------------------------------------------------------
	 * Sede activa (en sesión)
	 * ------------------------------------------------------------------- */

	/**
	 * Devuelve la sede activa válida para el cliente, o 0 si no hay.
	 *
	 * @return int
	 */
	public static function sede_activa() {
		if ( null !== self::$sede_cache ) {
			return self::$sede_cache;
		}

		self::$sede_cache = 0;

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return 0;
		}

		$id = absint( WC()->session->get( self::SESSION_KEY ) );
		if ( $id && self::es_sede_valida( $id ) ) {
			self::$sede_cache = $id;
		}
		return self::$sede_cache;
	}

	/**
	 * Comprueba que la sede sea activa y habilitada para venta web.
	 *
	 * @param int $sede_id Sede.
	 * @return bool
	 */
	private static function es_sede_valida( $sede_id ) {
		$post = get_post( $sede_id );
		if ( ! $post || MSP_Sedes::CPT !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}
		return '1' === get_post_meta( $sede_id, '_msp_activa', true )
			&& '1' === get_post_meta( $sede_id, '_msp_vende_web', true );
	}

	/**
	 * Captura la sede elegida desde el selector (?msp_sede=ID).
	 */
	public function capturar_sede() {
		if ( ! isset( $_GET['msp_sede'] ) ) {
			return;
		}
		if ( ! isset( $_GET['msp_sede_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_GET['msp_sede_nonce'] ), 'msp_sede' ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$id = absint( wp_unslash( $_GET['msp_sede'] ) );
		if ( $id && self::es_sede_valida( $id ) ) {
			WC()->session->set( self::SESSION_KEY, $id );
			self::$sede_cache = $id;
		}

		// Limpia la URL.
		wp_safe_redirect( remove_query_arg( array( 'msp_sede', 'msp_sede_nonce' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Selector y banner
	 * ------------------------------------------------------------------- */

	/**
	 * Shortcode [msp_selector_sede]: selector de tienda.
	 *
	 * @return string
	 */
	public function shortcode_selector() {
		$sedes = MSP_Sedes::obtener_sedes_recojo();
		if ( empty( $sedes ) ) {
			return '';
		}

		$actual = self::sede_activa();
		$nonce  = wp_create_nonce( 'msp_sede' );

		ob_start();
		echo '<form method="get" class="msp-selector-sede" action="">';
		echo '<label for="msp-sede-sel"><strong>' . esc_html__( 'Elige tu tienda:', 'multisede-pos' ) . '</strong></label> ';
		echo '<select id="msp-sede-sel" name="msp_sede" onchange="this.form.submit()">';
		echo '<option value="">' . esc_html__( 'Selecciona…', 'multisede-pos' ) . '</option>';
		foreach ( $sedes as $sede ) {
			echo '<option value="' . esc_attr( $sede->ID ) . '" ' . selected( $sede->ID, $actual, false ) . '>' .
				esc_html( $sede->post_title ) . '</option>';
		}
		echo '</select>';
		echo '<input type="hidden" name="msp_sede_nonce" value="' . esc_attr( $nonce ) . '" />';
		echo '<noscript><button type="submit">' . esc_html__( 'Elegir', 'multisede-pos' ) . '</button></noscript>';
		echo '</form>';
		return ob_get_clean();
	}

	/**
	 * Banner con la tienda elegida (o aviso para elegirla).
	 */
	public function banner_sede() {
		// Solo en páginas de tienda/producto/carrito/checkout.
		if ( ! ( is_shop() || is_product_category() || is_product() || is_cart() || is_checkout() ) ) {
			return;
		}

		$sede = self::sede_activa();

		echo '<div class="msp-banner-sede" style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px 14px;margin:0 0 16px">';
		if ( $sede ) {
			echo '<span>' . esc_html__( 'Comprando para recojo en:', 'multisede-pos' ) . ' <strong>' .
				esc_html( get_the_title( $sede ) ) . '</strong></span> ';
		} else {
			echo '<strong>' . esc_html__( 'Elige tu tienda para ver disponibilidad y precios de recojo.', 'multisede-pos' ) . '</strong><br>';
		}
		echo do_shortcode( '[msp_selector_sede]' );
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Stock visible = stock de la sede elegida
	 * ------------------------------------------------------------------- */

	/**
	 * ¿Debe aplicarse la lógica por sede en esta petición?
	 *
	 * @return int Sede activa o 0.
	 */
	private function sede_para_front() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return 0;
		}
		return self::sede_activa();
	}

	/**
	 * Sustituye la cantidad de stock por la disponible en la sede elegida.
	 *
	 * @param int        $qty     Cantidad original.
	 * @param WC_Product $product Producto.
	 * @return int
	 */
	public function stock_de_sede( $qty, $product ) {
		$sede = $this->sede_para_front();
		if ( ! $sede ) {
			return $qty;
		}
		return MSP_Stock::disponible_sede( $product->get_id(), $sede );
	}

	/**
	 * Marca en/sin stock según la sede elegida.
	 *
	 * @param bool       $in_stock Estado original.
	 * @param WC_Product $product  Producto.
	 * @return bool
	 */
	public function en_stock_en_sede( $in_stock, $product ) {
		$sede = $this->sede_para_front();
		if ( ! $sede ) {
			return $in_stock;
		}
		return MSP_Stock::disponible_sede( $product->get_id(), $sede ) > 0;
	}

	/**
	 * Texto de disponibilidad con la sede.
	 *
	 * @param string     $text    Texto.
	 * @param WC_Product $product Producto.
	 * @return string
	 */
	public function texto_disponibilidad( $text, $product ) {
		$sede = $this->sede_para_front();
		if ( ! $sede ) {
			return $text;
		}
		$disp = MSP_Stock::disponible_sede( $product->get_id(), $sede );
		if ( $disp > 0 ) {
			/* translators: %d: unidades disponibles. */
			return sprintf( esc_html__( '%d disponibles en esta tienda', 'multisede-pos' ), $disp );
		}
		return esc_html__( 'Sin stock en esta tienda', 'multisede-pos' );
	}

	/* ---------------------------------------------------------------------
	 * Validación de carrito
	 * ------------------------------------------------------------------- */

	/**
	 * Valida al agregar al carrito contra el stock de la sede.
	 *
	 * @param bool $passed     Si pasa la validación.
	 * @param int  $product_id Producto.
	 * @param int  $cantidad   Cantidad.
	 * @param int  $variation_id Variación (opcional).
	 * @return bool
	 */
	public function validar_agregar( $passed, $product_id, $cantidad, $variation_id = 0 ) {
		$sede = self::sede_activa();
		if ( ! $sede ) {
			wc_add_notice( __( 'Elige primero tu tienda para comprar con recojo.', 'multisede-pos' ), 'error' );
			return false;
		}

		$pid  = $variation_id ? $variation_id : $product_id;
		$disp = MSP_Stock::disponible_sede( $pid, $sede );

		// Considera lo que ya hay en el carrito de ese producto.
		$en_carrito = 0;
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$item_pid = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
				if ( (int) $item_pid === (int) $pid ) {
					$en_carrito += (int) $item['quantity'];
				}
			}
		}

		if ( ( $cantidad + $en_carrito ) > $disp ) {
			wc_add_notice(
				sprintf(
					/* translators: %d: stock disponible. */
					__( 'Solo hay %d unidades disponibles en esta tienda.', 'multisede-pos' ),
					$disp
				),
				'error'
			);
			return false;
		}

		return $passed;
	}

	/**
	 * Revalida todo el carrito contra la sede elegida (antes del checkout).
	 */
	public function validar_carrito() {
		$sede = self::sede_activa();
		if ( ! $sede || ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$pid  = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
			$disp = MSP_Stock::disponible_sede( $pid, $sede );
			if ( (int) $item['quantity'] > $disp ) {
				$product = $item['data'];
				wc_add_notice(
					sprintf(
						/* translators: 1: producto, 2: stock disponible. */
						__( '"%1$s": solo hay %2$d disponibles en la tienda elegida. Ajusta la cantidad.', 'multisede-pos' ),
						$product ? $product->get_name() : $pid,
						$disp
					),
					'error'
				);
			}
		}
	}
}
