<?php
/**
 * Recojo en tienda: sede de recojo en el checkout y reserva de stock.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Añade la selección de sede de recojo y gestiona la reserva de stock.
 *
 * Flujo:
 * 1. El cliente elige la sede de recojo en el checkout.
 * 2. Al confirmar el pedido se reservan las unidades en esa sede.
 * 3. Al marcar "recogido" se descuenta el stock físico y se libera la reserva.
 * 4. Si el pedido se cancela/reembolsa antes del recojo, se libera la reserva.
 */
class MSP_Recojo {

	/**
	 * Engancha los hooks.
	 */
	public function init() {
		// Campo de sede en el checkout clásico.
		add_action( 'woocommerce_after_order_notes', array( $this, 'campo_checkout' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validar_checkout' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'guardar_sede_pedido' ), 10, 2 );

		// Reserva de stock al procesarse el pedido.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'reservar_pedido' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'reservar_pedido_obj' ), 20 );

		// Mostrar la sede en el detalle del pedido (admin y cliente).
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'mostrar_sede_admin' ) );
		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'fila_sede_cliente' ), 10, 2 );

		// Acción de pedido: marcar como recogido.
		add_filter( 'woocommerce_order_actions', array( $this, 'accion_recogido' ) );
		add_action( 'woocommerce_order_action_msp_marcar_recogido', array( $this, 'procesar_recogido' ) );

		// Liberar reserva si se cancela o reembolsa antes del recojo.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'liberar_pedido' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'liberar_pedido' ) );
	}

	/**
	 * Renderiza el selector de sede de recojo en el checkout.
	 *
	 * @param WC_Checkout $checkout Checkout.
	 */
	public function campo_checkout( $checkout ) {
		$sedes = MSP_Sedes::obtener_sedes_recojo();
		if ( empty( $sedes ) ) {
			return;
		}

		$opciones = array( '' => __( 'Elige una tienda…', 'multisede-pos' ) );
		foreach ( $sedes as $sede ) {
			$direccion              = get_post_meta( $sede->ID, '_msp_direccion', true );
			$etiqueta               = $direccion ? $sede->post_title . ' — ' . $direccion : $sede->post_title;
			$opciones[ $sede->ID ]  = $etiqueta;
		}

		echo '<div id="msp_recojo_field"><h3>' . esc_html__( 'Recojo en tienda', 'multisede-pos' ) . '</h3>';
		echo '<p>' . esc_html__( 'Por ahora solo entregamos con recojo en tienda. Elige dónde recogerás tu pedido.', 'multisede-pos' ) . '</p>';

		woocommerce_form_field(
			'msp_sede_recojo',
			array(
				'type'     => 'select',
				'required' => true,
				'class'    => array( 'form-row-wide' ),
				'label'    => __( 'Sede de recojo', 'multisede-pos' ),
				'options'  => $opciones,
			),
			$checkout->get_value( 'msp_sede_recojo' )
		);
		echo '</div>';
	}

	/**
	 * Valida que se haya elegido una sede.
	 */
	public function validar_checkout() {
		$sedes = MSP_Sedes::obtener_sedes_recojo();
		if ( empty( $sedes ) ) {
			return; // No hay sedes de recojo configuradas; no bloqueamos.
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo valida el nonce del checkout.
		$sede = isset( $_POST['msp_sede_recojo'] ) ? absint( wp_unslash( $_POST['msp_sede_recojo'] ) ) : 0;

		if ( ! $sede ) {
			wc_add_notice( __( 'Por favor elige la sede donde recogerás tu pedido.', 'multisede-pos' ), 'error' );
		}
	}

	/**
	 * Guarda la sede de recojo en el pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @param array    $data  Datos enviados.
	 */
	public function guardar_sede_pedido( $order, $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo valida el nonce del checkout.
		$sede = isset( $_POST['msp_sede_recojo'] ) ? absint( wp_unslash( $_POST['msp_sede_recojo'] ) ) : 0;
		if ( ! $sede ) {
			return;
		}

		$order->update_meta_data( '_msp_sede_id', $sede );
		$order->update_meta_data( '_msp_origen', 'web' );
		$order->update_meta_data( '_msp_recogido', '0' );
	}

	/**
	 * Reserva el stock del pedido (checkout clásico).
	 *
	 * @param int      $order_id ID del pedido.
	 * @param array    $data     Datos.
	 * @param WC_Order $order    Pedido.
	 */
	public function reservar_pedido( $order_id, $data, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		$this->reservar_pedido_obj( $order );
	}

	/**
	 * Reserva el stock de un pedido (idempotente).
	 *
	 * @param WC_Order $order Pedido.
	 */
	public function reservar_pedido_obj( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$sede_id = (int) $order->get_meta( '_msp_sede_id' );
		if ( ! $sede_id ) {
			return;
		}

		// Evita reservar dos veces el mismo pedido.
		if ( 'reservado' === $order->get_meta( '_msp_reserva_estado' ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$producto_id = $product->get_id();
			$cantidad    = (int) $item->get_quantity();

			MSP_Stock::reservar( $producto_id, $sede_id, $cantidad );
			MSP_Stock::sincronizar_woo( $producto_id );
		}

		$order->update_meta_data( '_msp_reserva_estado', 'reservado' );
		$order->save();
	}

	/**
	 * Añade la acción "Marcar como recogido" al pedido.
	 *
	 * @param array $acciones Acciones existentes.
	 * @return array
	 */
	public function accion_recogido( $acciones ) {
		global $theorder;

		if ( $theorder instanceof WC_Order && $theorder->get_meta( '_msp_sede_id' ) &&
			'1' !== $theorder->get_meta( '_msp_recogido' ) ) {
			$acciones['msp_marcar_recogido'] = __( 'Marcar como recogido (Multisede)', 'multisede-pos' );
		}
		return $acciones;
	}

	/**
	 * Procesa el recojo: descuenta stock físico y cierra la reserva.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public function procesar_recogido( $order ) {
		$sede_id = (int) $order->get_meta( '_msp_sede_id' );
		if ( ! $sede_id || '1' === $order->get_meta( '_msp_recogido' ) ) {
			return;
		}

		$reservado = 'reservado' === $order->get_meta( '_msp_reserva_estado' );

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$producto_id = $product->get_id();
			$cantidad    = (int) $item->get_quantity();

			if ( $reservado ) {
				// Confirma la reserva: descuenta stock y libera la reserva.
				MSP_Stock::confirmar_reserva( $producto_id, $sede_id, $cantidad );
			} else {
				// Sin reserva previa (ej. pedido manual): descuenta directo.
				MSP_Stock::ajustar( $producto_id, $sede_id, -$cantidad );
			}
			MSP_Stock::sincronizar_woo( $producto_id );
		}

		$order->update_meta_data( '_msp_recogido', '1' );
		$order->update_meta_data( '_msp_reserva_estado', 'recogido' );
		$order->add_order_note( __( 'Pedido recogido en tienda. Stock descontado de la sede.', 'multisede-pos' ) );
		$order->save();
	}

	/**
	 * Libera la reserva si el pedido se cancela/reembolsa antes del recojo.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function liberar_pedido( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$sede_id = (int) $order->get_meta( '_msp_sede_id' );
		if ( ! $sede_id || 'reservado' !== $order->get_meta( '_msp_reserva_estado' ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$producto_id = $product->get_id();
			$cantidad    = (int) $item->get_quantity();

			MSP_Stock::liberar_reserva( $producto_id, $sede_id, $cantidad );
			MSP_Stock::sincronizar_woo( $producto_id );
		}

		$order->update_meta_data( '_msp_reserva_estado', 'liberado' );
		$order->save();
	}

	/**
	 * Muestra la sede de recojo en la ficha del pedido en el admin.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public function mostrar_sede_admin( $order ) {
		$sede_id = (int) $order->get_meta( '_msp_sede_id' );
		if ( ! $sede_id ) {
			return;
		}

		$recogido = '1' === $order->get_meta( '_msp_recogido' );
		echo '<p><strong>' . esc_html__( 'Sede de recojo:', 'multisede-pos' ) . '</strong> ' . esc_html( get_the_title( $sede_id ) );
		echo $recogido
			? ' <span style="color:#1C8E80">(' . esc_html__( 'recogido', 'multisede-pos' ) . ')</span>'
			: ' <span style="color:#b32d2e">(' . esc_html__( 'pendiente de recojo', 'multisede-pos' ) . ')</span>';
		echo '</p>';
	}

	/**
	 * Añade la sede de recojo a los totales que ve el cliente.
	 *
	 * @param array    $total_rows Filas de totales.
	 * @param WC_Order $order      Pedido.
	 * @return array
	 */
	public function fila_sede_cliente( $total_rows, $order ) {
		$sede_id = (int) $order->get_meta( '_msp_sede_id' );
		if ( ! $sede_id ) {
			return $total_rows;
		}

		$total_rows['msp_sede'] = array(
			'label' => __( 'Recojo en:', 'multisede-pos' ),
			'value' => esc_html( get_the_title( $sede_id ) ),
		);
		return $total_rows;
	}
}
