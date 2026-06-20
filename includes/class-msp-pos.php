<?php
/**
 * POS de mostrador: venta presencial que genera pedidos de WooCommerce.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Punto de venta para tiendas físicas.
 */
class MSP_POS {

	const PAGE = 'msp-pos';

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'registrar_pagina' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_msp_pos_buscar', array( $this, 'ajax_buscar' ) );
		add_action( 'wp_ajax_msp_pos_cobrar', array( $this, 'ajax_cobrar' ) );

		// Reposición de stock si se cancela/reembolsa una venta de mostrador.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'reponer_stock' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'reponer_stock' ) );
	}

	/**
	 * Registra la página del POS.
	 */
	public function registrar_pagina() {
		add_menu_page(
			__( 'Punto de venta', 'multisede-pos' ),
			__( 'POS', 'multisede-pos' ),
			'msp_usar_pos',
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-cart',
			57
		);
	}

	/**
	 * Sedes de mostrador disponibles para el usuario actual.
	 *
	 * @return WP_Post[]
	 */
	private function sedes_disponibles() {
		$todas = MSP_Sedes::obtener_sedes_activas();

		// Solo sedes que venden en mostrador.
		$mostrador = array_filter(
			$todas,
			function ( $sede ) {
				return '1' === get_post_meta( $sede->ID, '_msp_vende_mostrador', true );
			}
		);

		// El admin ve todas; el resto, solo las suyas.
		if ( current_user_can( 'manage_options' ) ) {
			return array_values( $mostrador );
		}

		$mias = MSP_Roles::sedes_de_usuario( get_current_user_id() );
		return array_values(
			array_filter(
				$mostrador,
				function ( $sede ) use ( $mias ) {
					return in_array( $sede->ID, $mias, true );
				}
			)
		);
	}

	/**
	 * ¿Puede el usuario usar esta sede en el POS?
	 *
	 * @param int $sede_id ID de sede.
	 * @return bool
	 */
	private function puede_usar_sede( $sede_id ) {
		foreach ( $this->sedes_disponibles() as $sede ) {
			if ( (int) $sede->ID === (int) $sede_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Carga JS/CSS solo en la página del POS.
	 *
	 * @param string $hook Hook de la página actual.
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'msp-pos', MSP_PLUGIN_URL . 'admin/css/pos.css', array(), MSP_VERSION );
		wp_enqueue_script( 'msp-pos', MSP_PLUGIN_URL . 'admin/js/pos.js', array( 'jquery' ), MSP_VERSION, true );

		wp_localize_script(
			'msp-pos',
			'mspPOS',
			array(
				'ajaxurl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'msp_pos' ),
				'simbolo'  => get_woocommerce_currency_symbol(),
				'decimals' => wc_get_price_decimals(),
				'i18n'     => array(
					'sin_resultados' => __( 'Sin resultados', 'multisede-pos' ),
					'sin_stock'      => __( 'Sin stock', 'multisede-pos' ),
					'confirmar'      => __( '¿Cobrar esta venta?', 'multisede-pos' ),
					'vacio'          => __( 'Agrega productos al ticket.', 'multisede-pos' ),
					'error'          => __( 'Ocurrió un error. Inténtalo de nuevo.', 'multisede-pos' ),
					'vuelto'         => __( 'Vuelto', 'multisede-pos' ),
				),
			)
		);
	}

	/**
	 * Renderiza la pantalla del POS.
	 */
	public function render() {
		$sedes = $this->sedes_disponibles();
		?>
		<div class="wrap msp-pos">
			<h1><?php esc_html_e( 'Punto de venta', 'multisede-pos' ); ?></h1>

			<?php if ( empty( $sedes ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'No tienes ninguna sede de mostrador asignada. Pide a un administrador que te asigne una sede.', 'multisede-pos' ); ?>
				</p></div>
				</div>
				<?php
				return;
			endif;
			?>

			<div class="msp-pos-top">
				<label for="msp-pos-sede"><strong><?php esc_html_e( 'Sede:', 'multisede-pos' ); ?></strong></label>
				<select id="msp-pos-sede">
					<?php foreach ( $sedes as $sede ) : ?>
						<option value="<?php echo esc_attr( $sede->ID ); ?>"><?php echo esc_html( $sede->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="msp-pos-grid">
				<div class="msp-pos-col">
					<input type="text" id="msp-pos-buscar" placeholder="<?php esc_attr_e( 'Buscar producto por nombre o SKU…', 'multisede-pos' ); ?>" autocomplete="off" />
					<ul id="msp-pos-resultados"></ul>
				</div>

				<div class="msp-pos-col">
					<table class="msp-pos-ticket">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Producto', 'multisede-pos' ); ?></th>
								<th><?php esc_html_e( 'Cant.', 'multisede-pos' ); ?></th>
								<th><?php esc_html_e( 'Importe', 'multisede-pos' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody id="msp-pos-items">
							<tr class="msp-pos-vacio"><td colspan="4"><?php esc_html_e( 'Ticket vacío', 'multisede-pos' ); ?></td></tr>
						</tbody>
						<tfoot>
							<tr>
								<th colspan="2"><?php esc_html_e( 'Total', 'multisede-pos' ); ?></th>
								<th colspan="2" id="msp-pos-total">—</th>
							</tr>
						</tfoot>
					</table>

					<div class="msp-pos-pago">
						<label for="msp-pos-metodo"><?php esc_html_e( 'Método de pago', 'multisede-pos' ); ?></label>
						<select id="msp-pos-metodo">
							<option value="efectivo"><?php esc_html_e( 'Efectivo', 'multisede-pos' ); ?></option>
							<option value="tarjeta"><?php esc_html_e( 'Tarjeta', 'multisede-pos' ); ?></option>
							<option value="yape_plin"><?php esc_html_e( 'Yape / Plin', 'multisede-pos' ); ?></option>
							<option value="otro"><?php esc_html_e( 'Otro', 'multisede-pos' ); ?></option>
						</select>

						<div id="msp-pos-efectivo-wrap">
							<label for="msp-pos-recibido"><?php esc_html_e( 'Efectivo recibido', 'multisede-pos' ); ?></label>
							<input type="number" id="msp-pos-recibido" step="0.01" min="0" />
							<p id="msp-pos-vuelto"></p>
						</div>

						<button type="button" class="button button-primary button-hero" id="msp-pos-cobrar">
							<?php esc_html_e( 'Cobrar', 'multisede-pos' ); ?>
						</button>
					</div>

					<div id="msp-pos-mensaje"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * Busca productos para el POS.
	 */
	public function ajax_buscar() {
		check_ajax_referer( 'msp_pos', 'nonce' );

		if ( ! current_user_can( 'msp_usar_pos' ) ) {
			wp_send_json_error( array( 'msg' => __( 'Sin permiso.', 'multisede-pos' ) ), 403 );
		}

		$term    = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$sede_id = isset( $_GET['sede'] ) ? absint( wp_unslash( $_GET['sede'] ) ) : 0;

		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

		$ids = array();

		// Búsqueda por nombre.
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				's'              => $term,
				'fields'         => 'ids',
			)
		);
		$ids = $query->posts;

		// Búsqueda por SKU exacto.
		$por_sku = wc_get_product_id_by_sku( $term );
		if ( $por_sku && ! in_array( $por_sku, $ids, true ) ) {
			array_unshift( $ids, $por_sku );
		}

		$salida = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! $product->is_purchasable() ) {
				continue;
			}
			// Por simplicidad, el POS maneja productos simples.
			if ( $product->is_type( 'variable' ) ) {
				continue;
			}

			$salida[] = array(
				'id'     => $id,
				'nombre' => $product->get_name(),
				'sku'    => $product->get_sku(),
				'precio' => (float) wc_get_price_to_display( $product ),
				'stock'  => $sede_id ? MSP_Stock::get( $id, $sede_id ) : null,
			);
		}

		wp_send_json_success( $salida );
	}

	/**
	 * Procesa el cobro y crea el pedido.
	 */
	public function ajax_cobrar() {
		check_ajax_referer( 'msp_pos', 'nonce' );

		if ( ! current_user_can( 'msp_usar_pos' ) ) {
			wp_send_json_error( array( 'msg' => __( 'Sin permiso.', 'multisede-pos' ) ), 403 );
		}

		$sede_id = isset( $_POST['sede'] ) ? absint( wp_unslash( $_POST['sede'] ) ) : 0;
		$metodo  = isset( $_POST['metodo'] ) ? sanitize_key( wp_unslash( $_POST['metodo'] ) ) : 'efectivo';
		$items   = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : array();

		if ( ! $sede_id || ! $this->puede_usar_sede( $sede_id ) ) {
			wp_send_json_error( array( 'msg' => __( 'Sede no válida.', 'multisede-pos' ) ), 400 );
		}
		if ( empty( $items ) || ! is_array( $items ) ) {
			wp_send_json_error( array( 'msg' => __( 'El ticket está vacío.', 'multisede-pos' ) ), 400 );
		}

		// Validar stock disponible en la sede antes de crear el pedido.
		$normalizados = array();
		foreach ( $items as $item ) {
			$pid = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$qty = isset( $item['qty'] ) ? absint( $item['qty'] ) : 0;
			if ( ! $pid || $qty < 1 ) {
				continue;
			}
			$disponible = MSP_Stock::get( $pid, $sede_id );
			if ( $qty > $disponible ) {
				$product = wc_get_product( $pid );
				wp_send_json_error(
					array(
						/* translators: 1: producto, 2: stock disponible. */
						'msg' => sprintf(
							__( 'Stock insuficiente de "%1$s" en esta sede (disponible: %2$d).', 'multisede-pos' ),
							$product ? $product->get_name() : $pid,
							$disponible
						),
					),
					409
				);
			}
			$normalizados[ $pid ] = $qty;
		}

		if ( empty( $normalizados ) ) {
			wp_send_json_error( array( 'msg' => __( 'No hay productos válidos en el ticket.', 'multisede-pos' ) ), 400 );
		}

		// Crear el pedido.
		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			wp_send_json_error( array( 'msg' => __( 'No se pudo crear el pedido.', 'multisede-pos' ) ), 500 );
		}

		foreach ( $normalizados as $pid => $qty ) {
			$product = wc_get_product( $pid );
			if ( $product ) {
				$order->add_product( $product, $qty );
			}
		}

		$titulos = array(
			'efectivo'  => __( 'Efectivo (POS)', 'multisede-pos' ),
			'tarjeta'   => __( 'Tarjeta (POS)', 'multisede-pos' ),
			'yape_plin' => __( 'Yape/Plin (POS)', 'multisede-pos' ),
			'otro'      => __( 'Otro (POS)', 'multisede-pos' ),
		);

		$order->set_created_via( 'msp-pos' );
		$order->set_payment_method( 'msp_pos' );
		$order->set_payment_method_title( isset( $titulos[ $metodo ] ) ? $titulos[ $metodo ] : __( 'POS', 'multisede-pos' ) );
		$order->update_meta_data( '_msp_sede_id', $sede_id );
		$order->update_meta_data( '_msp_origen', 'pos' );
		$order->update_meta_data( '_msp_recogido', '1' );
		$order->update_meta_data( '_msp_reserva_estado', 'recogido' );
		$order->update_meta_data( '_msp_pos_metodo', $metodo );
		$order->update_meta_data( '_msp_cajero_id', get_current_user_id() );
		$order->update_meta_data( '_msp_stock_aplicado', '1' );
		$order->calculate_totals();
		$order->update_status( 'completed', __( 'Venta en mostrador (POS).', 'multisede-pos' ) );

		// Descontar stock físico de la sede.
		foreach ( $normalizados as $pid => $qty ) {
			MSP_Stock::ajustar( $pid, $sede_id, -$qty );
			MSP_Stock::sincronizar_woo( $pid );
		}

		$order->save();

		/**
		 * Permite a otros módulos (ej. caja chica, Fase 5) registrar la venta.
		 *
		 * @param WC_Order $order   Pedido creado.
		 * @param string   $metodo  Método de pago.
		 * @param int      $sede_id Sede.
		 */
		do_action( 'msp_pos_venta_creada', $order, $metodo, $sede_id );

		wp_send_json_success(
			array(
				'pedido' => $order->get_order_number(),
				'total'  => (float) $order->get_total(),
				'msg'    => sprintf(
					/* translators: %s: número de pedido. */
					__( 'Venta registrada. Pedido #%s.', 'multisede-pos' ),
					$order->get_order_number()
				),
			)
		);
	}

	/**
	 * Repone el stock de la sede si una venta POS se cancela/reembolsa.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function reponer_stock( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'pos' !== $order->get_meta( '_msp_origen' ) ) {
			return;
		}
		if ( '1' !== $order->get_meta( '_msp_stock_aplicado' ) ) {
			return;
		}

		$sede_id = (int) $order->get_meta( '_msp_sede_id' );
		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			MSP_Stock::ajustar( $product->get_id(), $sede_id, (int) $item->get_quantity() );
			MSP_Stock::sincronizar_woo( $product->get_id() );
		}

		$order->update_meta_data( '_msp_stock_aplicado', '0' );
		$order->add_order_note( __( 'Venta POS anulada: stock devuelto a la sede.', 'multisede-pos' ) );
		$order->save();
	}
}
