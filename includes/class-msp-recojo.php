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
		//
		// Va DENTRO del formulario de facturación, encabezándolo. Antes colgaba
		// de `woocommerce_checkout_before_customer_details`, que renderiza fuera
		// del contenedor `#customer_details`: los temas que maquetan el checkout
		// a dos columnas lo tomaban como una celda más y el bloque acababa al
		// lado de "Detalles de facturación" en vez de encima. Aquí dentro, el
		// tema lo coloca solo, en la columna que le toca.
		//
		// El hook es filtrable por si un constructor ordena sus secciones de otra
		// forma. Ojo al cambiarlo: cada hook del checkout tiene su propia firma y
		// unos pasan el objeto WC_Checkout y otros no (ver `campo_checkout`).
		add_action(
			apply_filters( 'msp_recojo_hook_checkout', 'woocommerce_before_checkout_billing_form' ),
			array( $this, 'campo_checkout' )
		);

		// El DNI es un campo NATIVO del checkout, no uno suelto: así lo coloca
		// WooCommerce dentro de "Detalles de facturación", con la maquetación del
		// tema, y viaja al pedido como cualquier otro campo de facturación.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'registrar_campo_dni' ), 20 );
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

		// …y también si el pedido se BORRA. Sin esto la reserva queda viva sin
		// dueño: el stock se inmoviliza para siempre, subir existencias no ayuda
		// (disponible = stock − reservado) y desde la interfaz no había salida.
		add_action( 'wp_trash_post', array( $this, 'liberar_pedido' ) );
		add_action( 'before_delete_post', array( $this, 'liberar_pedido' ) );
		// HPOS: con la tabla de pedidos propia, los hooks de post no se disparan.
		add_action( 'woocommerce_before_trash_order', array( $this, 'liberar_pedido' ) );
		add_action( 'woocommerce_before_delete_order', array( $this, 'liberar_pedido' ) );

		// El otro camino por el que se perdía una reserva: marcar el pedido como
		// completado a mano, sin usar la acción "Marcar como recogido". La
		// mercadería sale de la tienda igual, así que el stock debe descontarse.
		add_action( 'woocommerce_order_status_completed', array( $this, 'al_completar' ) );
	}

	/**
	 * Renderiza el selector de sede de recojo en el checkout.
	 *
	 * @param WC_Checkout $checkout Checkout.
	 */
	public function campo_checkout( $checkout = null ) {
		$sedes = MSP_Sedes::obtener_sedes_recojo();
		if ( empty( $sedes ) ) {
			return;
		}

		// El objeto del checkout se resuelve aquí y NO se confía en el argumento:
		// cada hook del checkout tiene su propia firma y unos lo pasan y otros
		// no. `woocommerce_after_order_notes` lo pasa;
		// `woocommerce_checkout_before_customer_details` no pasa nada, y como
		// este hook es filtrable (msp_recojo_hook_checkout), el sitio donde se
		// enganche puede cambiar sin que nadie lo revise. Confiar en el
		// argumento tumbó la página de pago entera en la v1.9.4.
		if ( ! $checkout instanceof WC_Checkout ) {
			$checkout = function_exists( 'WC' ) ? WC()->checkout() : null;
		}
		if ( ! $checkout ) {
			return;
		}

		echo '<div id="msp_recojo_field"><h3>' . esc_html__( 'Recojo en tienda', 'multisede-pos' ) . '</h3>';

		// Si el cliente ya eligió tienda (compra por sede), se fija esa.
		$sede_activa = class_exists( 'MSP_Frontend' ) ? MSP_Frontend::sede_activa() : 0;

		if ( $sede_activa ) {
			echo '<p>' . esc_html__( 'Recogerás tu pedido en:', 'multisede-pos' ) . ' <strong>' .
				esc_html( get_the_title( $sede_activa ) ) . '</strong>';
			$dir = get_post_meta( $sede_activa, '_msp_direccion', true );
			if ( $dir ) {
				echo ' <span class="msp-dir">(' . esc_html( $dir ) . ')</span>';
			}
			echo '</p>';
			echo '<input type="hidden" name="msp_sede_recojo" value="' . esc_attr( $sede_activa ) . '" />';
			echo '</div>';
			return;
		}

		// Si no hay tienda elegida, se ofrece el selector.
		$opciones = array( '' => __( 'Elige una tienda…', 'multisede-pos' ) );
		foreach ( $sedes as $sede ) {
			$direccion             = get_post_meta( $sede->ID, '_msp_direccion', true );
			$etiqueta              = $direccion ? $sede->post_title . ' — ' . $direccion : $sede->post_title;
			$opciones[ $sede->ID ] = $etiqueta;
		}

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
	 * Añade el DNI a los campos de facturación del checkout.
	 *
	 * Se pide **siempre**, no solo por encima del límite de SUNAT: los couriers
	 * lo exigen para la entrega, y pedirlo unas veces sí y otras no según el
	 * importe del carrito confunde más de lo que ahorra.
	 *
	 * Va como campo nativo y no como input suelto para que WooCommerce lo
	 * coloque dentro de "Detalles de facturación", con la maquetación del tema.
	 *
	 * @param array $campos Campos del checkout.
	 * @return array
	 */
	public function registrar_campo_dni( $campos ) {
		// Si otro plugin ya puso su campo de DNI, no se añade nada: sería el
		// mismo dato pedido dos veces, y el cliente no sabría cuál llenar.
		if ( isset( $campos['billing']['billing_dni'] ) ) {
			return $campos;
		}

		// Prioridad 120: **después del correo**. Las prioridades nativas de
		// WooCommerce son nombre 10, apellidos 20, empresa 30, país 40,
		// dirección 50/60, distrito 70, departamento 80, código postal 90,
		// teléfono 100 y correo 110. El documento cierra el bloque de datos del
		// cliente en vez de interrumpirlo por la mitad.
		$campos['billing']['billing_dni'] = array(
			'type'              => 'text',
			'label'             => __( 'DNI', 'multisede-pos' ),
			'placeholder'       => __( '8 dígitos', 'multisede-pos' ),
			'required'          => true,
			'class'             => array( 'form-row-wide' ),
			'priority'          => 120,
			'custom_attributes' => array(
				'inputmode' => 'numeric',
				'maxlength' => '8',
			),
		);

		return $campos;
	}

	/**
	 * Nombre del campo de DNI que ya exista en el checkout, si lo hay.
	 *
	 * Se busca `billing_dni`, que es el que usan los plugins de checkout
	 * peruanos (entre ellos el nuestro de Vaporis). Así los dos pueden convivir
	 * en el mismo sitio sin duplicar el campo.
	 *
	 * @return string Nombre del campo, o cadena vacía.
	 */
	public static function campo_dni_ajeno() {
		// El plugin de checkout peruano de Lucuma (el de Vaporis) trae su propio
		// `billing_dni` con su validación. Si está, valida él y nosotros solo
		// leemos el dato.
		return defined( 'VAPORIS_CHECKOUT_PE_DIR' );
	}

	/**
	 * DNI enviado en el checkout, venga del campo que venga.
	 *
	 * @return string Solo dígitos.
	 */
	private static function dni_enviado() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Woo valida el nonce del checkout.
		foreach ( array( 'msp_dni', 'billing_dni' ) as $campo ) {
			if ( isset( $_POST[ $campo ] ) && '' !== trim( (string) wp_unslash( $_POST[ $campo ] ) ) ) {
				return preg_replace( '/[^0-9]/', '', wp_unslash( $_POST[ $campo ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return '';
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

		// Revalidar el stock AQUÍ, contra la sede que se está enviando. Hasta
		// ahora solo se comprobaba al pintar el carrito y el checkout
		// (`woocommerce_check_cart_items`), y entre eso y pulsar "pagar" puede
		// pasar un rato largo: el cliente rellena sus datos mientras otra
		// persona compra la última unidad.
		if ( $sede && class_exists( 'MSP_Frontend' ) ) {
			foreach ( MSP_Frontend::problemas_de_stock( $sede ) as $mensaje ) {
				wc_add_notice( $mensaje, 'error' );
			}
		}

		// El DNI se pide SIEMPRE, no solo por encima del límite de SUNAT: los
		// couriers lo exigen para la entrega, y pedirlo unas veces sí y otras no
		// según el importe del carrito confunde más de lo que ahorra.
		//
		// Si otro plugin ya puso su propio campo de DNI, es él quien valida: no
		// vamos a exigir dos veces el mismo dato.
		if ( self::campo_dni_ajeno() ) {
			return;
		}

		$dni = self::dni_enviado();

		if ( '' === $dni ) {
			wc_add_notice( __( 'Necesitamos tu DNI para emitir la boleta y para la entrega.', 'multisede-pos' ), 'error' );
		} elseif ( 8 !== strlen( $dni ) ) {
			wc_add_notice( __( 'El DNI debe tener 8 dígitos.', 'multisede-pos' ), 'error' );
		}

		// Identificar al comprador es documento Y nombre. En la web el nombre lo
		// pide WooCommerce en la facturación, así que esto es solo una red por si
		// esos campos se hubieran ocultado en el tema o en el constructor.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Woo valida el nonce del checkout.
		$nombre   = isset( $_POST['billing_first_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) ) : '';
		$apellido = isset( $_POST['billing_last_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $nombre && '' === $apellido ) {
			wc_add_notice( __( 'Necesitamos tu nombre completo para la boleta.', 'multisede-pos' ), 'error' );
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

		$dni = self::dni_enviado();
		if ( 8 === strlen( $dni ) ) {
			$order->update_meta_data( '_msp_cliente_tipo_doc', '1' );
			$order->update_meta_data( '_msp_cliente_num_doc', $dni );
		}
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

		$reservados = array();
		$faltantes  = array();

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

			// Reserva condicional: si entre que el cliente vio el checkout y
			// pulsó pagar alguien se llevó la última unidad, aquí falla en vez
			// de comprometer stock que no existe.
			if ( MSP_Stock::reservar_si_hay( $producto_id, $sede_id, $cantidad ) ) {
				$reservados[ $producto_id ] = $cantidad;
			} else {
				$faltantes[] = $product->get_name();
			}
			MSP_Stock::sincronizar_woo( $producto_id );
		}

		if ( $faltantes ) {
			// El pedido ya existe y probablemente ya está pagado, así que no se
			// revierte: se devuelve lo reservado, se deja el pedido EN ESPERA y
			// se avisa. Un pedido en espera lo ve un humano; una reserva
			// imposible no la ve nadie hasta que el cliente viene a recoger y no
			// hay mercadería.
			foreach ( $reservados as $pid => $qty ) {
				MSP_Stock::liberar_reserva( $pid, $sede_id, $qty );
				MSP_Stock::sincronizar_woo( $pid );
			}

			$order->update_meta_data( '_msp_reserva_estado', 'sin_stock' );
			$order->add_order_note(
				sprintf(
					/* translators: %s: lista de productos. */
					__( 'ATENCIÓN: no se pudo reservar stock en la tienda elegida para: %s. Alguien compró esas unidades mientras se completaba este pedido. Contacta al cliente antes de cobrar o entregar.', 'multisede-pos' ),
					implode( ', ', $faltantes )
				)
			);
			$order->update_status( 'on-hold' );
			$order->save();
			return;
		}

		$order->update_meta_data( '_msp_reserva_estado', 'reservado' );
		$order->save();
	}

	/**
	 * Un pedido con reserva que pasa a completado se da por entregado.
	 *
	 * La forma correcta de entregar es la pantalla de Entregas o la acción del
	 * pedido, que llaman a `procesar_recogido()`. Pero alguien puede marcar el
	 * pedido como completado a mano desde WooCommerce, y entonces la mercadería
	 * sale de la tienda con la reserva todavía puesta: el stock queda
	 * inmovilizado y el físico nunca baja.
	 *
	 * Se trata igual que un recojo, que es lo que de hecho ocurrió.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function al_completar( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Solo pedidos web con reserva viva. Los del POS ya descontaron al
		// cobrar, y `procesar_recogido()` es idempotente por su propia guarda.
		if ( 'reservado' !== $order->get_meta( '_msp_reserva_estado' ) ) {
			return;
		}

		$this->procesar_recogido( $order );
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

		// La boleta del pedido web se emite AQUÍ, al entregarlo, no al hacerlo.
		//
		// Es el momento defendible mientras no se responda si se paga en
		// efectivo al recoger: si se emitiera al crear el pedido, un pedido que
		// nadie recoge dejaría una boleta emitida por una venta que no ocurrió,
		// y eso se arregla con una anulación ante SUNAT. Al revés no hay daño:
		// entre el pedido y la entrega no hay obligación de comprobante.
		//
		// Si la respuesta es "se paga por la web y siempre se recoge", esto se
		// puede adelantar al pago sin tocar nada más que el hook.
		if ( class_exists( 'MSP_Cola' ) && MSP_Cola::activa() ) {
			MSP_Cola::encolar_pedido( $order, $sede_id );
		}
	}

	/**
	 * Libera la reserva si el pedido se cancela/reembolsa antes del recojo.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function liberar_pedido( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		// Los hooks de borrado de WordPress se disparan para CUALQUIER tipo de
		// contenido, no solo pedidos: una entrada o un producto pasan por aquí.
		if ( ! $order instanceof WC_Order ) {
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
