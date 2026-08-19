<?php
/**
 * Entregas: los pedidos web que esperan a ser recogidos en la tienda.
 *
 * Hallazgo F del recorrido de verificación, y el que más pesaba en el día a día:
 * **el cajero no podía entregar un pedido web**. Marcar un pedido como recogido
 * se hacía desde la pantalla de Pedidos de WooCommerce, que exige
 * `edit_shop_orders`; el rol Cajero no la tiene, así que ni siquiera podía abrir
 * el listado. Y la Ayuda del plugin le explicaba ese flujo paso a paso: se le
 * documentaba algo que no podía hacer.
 *
 * La solución no es darle `edit_shop_orders`: con esa capacidad vería los
 * pedidos de todas las tiendas y podría editar precios, estados y datos que no
 * le tocan. Esta pantalla hace exactamente una cosa, solo con los pedidos de
 * **su** sede.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pendientes de recojo, por sede, con una sola acción.
 */
class MSP_Entregas {

	const PAGE = 'msp-entregas';

	/** Máximo de pedidos listados. */
	const LIMITE = 100;

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'registrar_pagina' ) );
		add_action( 'admin_init', array( $this, 'procesar' ) );
	}

	/**
	 * Registra la pantalla.
	 */
	public function registrar_pagina() {
		add_menu_page(
			__( 'Entregas', 'multisede-pos' ),
			__( 'Entregas', 'multisede-pos' ),
			'msp_usar_pos',
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-archive',
			58
		);
	}

	/**
	 * Sedes que puede atender el usuario actual.
	 *
	 * @return int[]
	 */
	private function mis_sedes() {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'msp_ver_reportes' ) ) {
			return array_map(
				function ( $s ) {
					return (int) $s->ID;
				},
				MSP_Sedes::obtener_sedes_activas()
			);
		}

		return array_map( 'intval', MSP_Roles::sedes_de_usuario( get_current_user_id() ) );
	}

	/**
	 * Pedidos pendientes de recojo en las sedes del usuario.
	 *
	 * @return WC_Order[]
	 */
	private function pendientes() {
		$sedes = $this->mis_sedes();
		if ( ! $sedes || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$pedidos = wc_get_orders(
			array(
				'limit'   => self::LIMITE,
				'orderby' => 'date',
				'order'   => 'ASC',
				'status'  => array( 'processing', 'on-hold', 'completed' ),
			)
		);

		// El filtro por meta se hace en PHP y no en la consulta a propósito: con
		// almacenamiento clásico WooCommerce descarta `meta_query` en silencio, y
		// una consulta que "funciona" pero ignora el filtro devolvería los
		// pedidos de todas las tiendas. Con el límite de arriba, el coste es
		// asumible y el resultado es correcto en los dos almacenamientos.
		$salida = array();
		foreach ( $pedidos as $order ) {
			$sede = (int) $order->get_meta( '_msp_sede_id' );

			if ( ! $sede || ! in_array( $sede, $sedes, true ) ) {
				continue;
			}
			if ( 'web' !== $order->get_meta( '_msp_origen' ) ) {
				continue;
			}
			if ( '1' === $order->get_meta( '_msp_recogido' ) ) {
				continue;
			}

			$salida[] = $order;
		}

		return $salida;
	}

	/**
	 * Procesa la entrega.
	 */
	public function procesar() {
		if ( ! isset( $_GET['msp_entregar'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$order_id = absint( wp_unslash( $_GET['msp_entregar'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'msp_entregar_' . $order_id );

		if ( ! current_user_can( 'msp_usar_pos' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'multisede-pos' ) );
		}

		$order = wc_get_order( $order_id );
		$aviso = '';

		if ( ! $order ) {
			$aviso = __( 'Ese pedido no existe.', 'multisede-pos' );
		} elseif ( ! in_array( (int) $order->get_meta( '_msp_sede_id' ), $this->mis_sedes(), true ) ) {
			// Sin esta comprobación, un cajero podría entregar el pedido de otra
			// tienda cambiando el número en la URL.
			$aviso = __( 'Ese pedido es de otra tienda.', 'multisede-pos' );
		} elseif ( '1' === $order->get_meta( '_msp_recogido' ) ) {
			$aviso = __( 'Ese pedido ya estaba entregado.', 'multisede-pos' );
		} else {
			$recojo = new MSP_Recojo();
			$recojo->procesar_recogido( $order );

			// Un pedido entregado está completado: es lo que espera ver el
			// cliente en su cuenta y lo que cierra el ciclo en WooCommerce.
			if ( ! $order->has_status( 'completed' ) ) {
				$order->update_status( 'completed', __( 'Entregado en tienda.', 'multisede-pos' ) );
			}

			$aviso = sprintf(
				/* translators: %s: número de pedido. */
				__( 'Pedido #%s entregado. Stock descontado de la tienda.', 'multisede-pos' ),
				$order->get_order_number()
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::PAGE,
					'aviso' => rawurlencode( $aviso ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Pinta la pantalla.
	 */
	public function render() {
		$pedidos = $this->pendientes();
		$aviso   = isset( $_GET['aviso'] ) ? sanitize_text_field( wp_unslash( $_GET['aviso'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Entregas pendientes', 'multisede-pos' ); ?></h1>
			<p class="description" style="max-width:70ch">
				<?php esc_html_e( 'Pedidos comprados por la web que esperan a que el cliente venga a recogerlos en tu tienda. Al entregarlos, el stock se descuenta y se cierra la reserva.', 'multisede-pos' ); ?>
			</p>

			<?php if ( $aviso ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $aviso ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $pedidos ) : ?>
				<div class="notice notice-info inline" style="margin-top:16px"><p>
					<?php esc_html_e( 'No hay pedidos pendientes de recojo en tu tienda.', 'multisede-pos' ); ?>
				</p></div>
				</div>
				<?php
				return;
			endif;
			?>

			<table class="widefat striped" style="margin-top:16px">
				<thead>
					<tr>
						<th style="width:90px"><?php esc_html_e( 'Pedido', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Cliente', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Qué se lleva', 'multisede-pos' ); ?></th>
						<th style="width:110px"><?php esc_html_e( 'Total', 'multisede-pos' ); ?></th>
						<th style="width:130px"><?php esc_html_e( 'Estado', 'multisede-pos' ); ?></th>
						<th style="width:150px"><?php esc_html_e( 'Tienda', 'multisede-pos' ); ?></th>
						<th style="width:150px"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pedidos as $order ) : ?>
						<?php
						$sin_stock = 'sin_stock' === $order->get_meta( '_msp_reserva_estado' );
						$url       = wp_nonce_url(
							add_query_arg(
								array(
									'page'         => self::PAGE,
									'msp_entregar' => $order->get_id(),
								),
								admin_url( 'admin.php' )
							),
							'msp_entregar_' . $order->get_id()
						);
						?>
						<tr>
							<td><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong><br>
								<small><?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ); ?></small>
							</td>
							<td>
								<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?><br>
								<small><?php echo esc_html( $order->get_billing_phone() ? $order->get_billing_phone() : $order->get_billing_email() ); ?></small>
							</td>
							<td>
								<?php
								$partes = array();
								foreach ( $order->get_items() as $item ) {
									$partes[] = $item->get_quantity() . ' × ' . $item->get_name();
								}
								echo esc_html( implode( ' · ', $partes ) );
								?>
							</td>
							<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
							<td>
								<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
								<?php if ( $sin_stock ) : ?>
									<br><span style="color:#b32d2e;font-weight:600">
										<?php esc_html_e( 'Sin stock reservado', 'multisede-pos' ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( get_the_title( (int) $order->get_meta( '_msp_sede_id' ) ) ); ?></td>
							<td>
								<a class="button button-primary" href="<?php echo esc_url( $url ); ?>"
									onclick="return confirm('<?php echo esc_js( __( '¿Entregar este pedido al cliente?', 'multisede-pos' ) ); ?>');">
									<?php esc_html_e( 'Entregar', 'multisede-pos' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( count( $pedidos ) >= self::LIMITE ) : ?>
				<p class="description"><?php esc_html_e( 'Se muestran los pedidos más antiguos; hay más pendientes.', 'multisede-pos' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
