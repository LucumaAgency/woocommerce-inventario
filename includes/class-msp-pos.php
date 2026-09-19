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
		add_action( 'wp_ajax_msp_pos_abrir_caja', array( $this, 'ajax_abrir_caja' ) );

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
				'pruebaUrl' => MSP_Ticket::url_prueba(),
				'simbolo'  => get_woocommerce_currency_symbol(),
				'decimals' => wc_get_price_decimals(),
				'boletas'  => MSP_Cola::activa(),
				'limiteDni' => MSP_Comprobante::LIMITE_DNI,
				'ruc'      => array(
					'activa' => MSP_Ruc::activa(),
					'nonce'  => wp_create_nonce( 'msp_ruc' ),
				),
				'i18n'     => array(
					'sin_resultados' => __( 'Sin resultados', 'multisede-pos' ),
					'sin_stock'      => __( 'Sin stock', 'multisede-pos' ),
					'confirmar'      => __( '¿Cobrar esta venta?', 'multisede-pos' ),
					'vacio'          => __( 'Agrega productos al ticket.', 'multisede-pos' ),
					'error'          => __( 'Ocurrió un error. Inténtalo de nuevo.', 'multisede-pos' ),
					'imprimir'       => __( 'Imprimir ticket', 'multisede-pos' ),
					'vuelto'         => __( 'Vuelto', 'multisede-pos' ),
					'dni_requerido'  => sprintf(
						/* translators: %s: importe límite. */
						__( 'Esta venta pasa de S/ %s: la boleta tiene que llevar el DNI y el nombre del cliente.', 'multisede-pos' ),
						number_format( MSP_Comprobante::LIMITE_DNI, 2 )
					),
					'falta_nombre'   => __( 'Falta el nombre del cliente.', 'multisede-pos' ),
					'falta_dni'      => __( 'Falta el DNI del cliente.', 'multisede-pos' ),
					'dni_corto'      => __( 'El DNI tiene 8 dígitos.', 'multisede-pos' ),
					'falta_ruc'      => __( 'Para la factura hace falta el RUC del cliente.', 'multisede-pos' ),
					'ruc_corto'      => __( 'El RUC tiene 11 dígitos.', 'multisede-pos' ),
					'falta_razon'    => __( 'Falta la razón social del cliente.', 'multisede-pos' ),
					'sede_sin_serie' => __( 'Esta tienda no tiene serie de factura configurada: solo puede emitir boletas.', 'multisede-pos' ),
					'abrir_caja'     => __( '¿Con cuánto efectivo abres la caja? (0 si empiezas sin fondo)', 'multisede-pos' ),
					'abriendo'       => __( 'Abriendo caja…', 'multisede-pos' ),
					'ruc_buscando'   => __( 'Buscando la razón social…', 'multisede-pos' ),
					'ruc_sin_datos'  => __( 'No encontramos la razón social: escríbela como figura en SUNAT.', 'multisede-pos' ),
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
				<?php
				/* Para probar la impresora sin cobrar nada: abre un ticket
				   ficticio de la sede elegida. No toca SUNAT ni la numeración. */
				?>
				<button type="button" class="button" id="msp-pos-prueba"><?php esc_html_e( 'Imprimir boleta de prueba', 'multisede-pos' ); ?></button>
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
								<th><?php esc_html_e( 'Dscto.', 'multisede-pos' ); ?></th>
								<th><?php esc_html_e( 'Importe', 'multisede-pos' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody id="msp-pos-items">
							<tr class="msp-pos-vacio"><td colspan="5"><?php esc_html_e( 'Ticket vacío', 'multisede-pos' ); ?></td></tr>
						</tbody>
						<tfoot>
							<tr>
								<td colspan="3"><?php esc_html_e( 'Subtotal', 'multisede-pos' ); ?></td>
								<td colspan="2" id="msp-pos-subtotal">—</td>
							</tr>
							<tr>
								<td colspan="3"><?php esc_html_e( 'Descuento', 'multisede-pos' ); ?></td>
								<td colspan="2" id="msp-pos-descuento-total">—</td>
							</tr>
							<tr>
								<th colspan="3"><?php esc_html_e( 'Total', 'multisede-pos' ); ?></th>
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

						<?php if ( MSP_Cola::activa() ) : ?>
							<?php $sedes_factura = class_exists( 'MSP_Factura' ) ? MSP_Factura::sedes_con_factura() : array(); ?>

							<?php if ( $sedes_factura ) : ?>
								<?php
								/* El cliente que pide factura lo dice ANTES de pagar, y el
								   cajero tiene que poder cambiarlo con el ticket ya armado:
								   por eso es un selector aquí y no una pantalla aparte. */
								?>
								<div id="msp-pos-comprobante">
									<label for="msp-pos-tipo"><?php esc_html_e( 'Comprobante', 'multisede-pos' ); ?></label>
									<select id="msp-pos-tipo"
										data-sedes-factura="<?php echo esc_attr( wp_json_encode( array_keys( $sedes_factura ) ) ); ?>">
										<option value="boleta"><?php esc_html_e( 'Boleta', 'multisede-pos' ); ?></option>
										<option value="factura"><?php esc_html_e( 'Factura (con RUC)', 'multisede-pos' ); ?></option>
									</select>
									<p id="msp-pos-tipo-aviso" class="description"></p>
								</div>
							<?php endif; ?>

							<div id="msp-pos-cliente">
								<label for="msp-pos-dni"><?php esc_html_e( 'DNI del cliente', 'multisede-pos' ); ?></label>
								<input type="text" id="msp-pos-dni" inputmode="numeric" maxlength="8" autocomplete="off"
									placeholder="<?php esc_attr_e( 'opcional', 'multisede-pos' ); ?>" />
								<input type="text" id="msp-pos-cliente-nombre" autocomplete="off"
									placeholder="<?php esc_attr_e( 'Nombre del cliente', 'multisede-pos' ); ?>" />
								<p id="msp-pos-dni-aviso" class="description"></p>
							</div>

							<?php if ( $sedes_factura ) : ?>
								<div id="msp-pos-factura" style="display:none">
									<label for="msp-pos-ruc"><?php esc_html_e( 'RUC del cliente', 'multisede-pos' ); ?></label>
									<input type="text" id="msp-pos-ruc" inputmode="numeric" maxlength="11" autocomplete="off"
										placeholder="<?php esc_attr_e( '11 dígitos', 'multisede-pos' ); ?>" />
									<input type="text" id="msp-pos-razon-social" autocomplete="off"
										placeholder="<?php esc_attr_e( 'Razón social', 'multisede-pos' ); ?>" />
									<p id="msp-pos-ruc-aviso" class="description"></p>
								</div>
							<?php endif; ?>
						<?php endif; ?>

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

		// Solo se puede consultar el stock de una sede propia.
		if ( ! $sede_id || ! $this->puede_usar_sede( $sede_id ) ) {
			wp_send_json_error( array( 'msg' => __( 'Sede no válida.', 'multisede-pos' ) ), 400 );
		}

		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

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

		// Búsqueda por SKU exacto (puede ser el SKU de una variación).
		$por_sku = wc_get_product_id_by_sku( $term );
		if ( $por_sku && ! in_array( $por_sku, $ids, true ) ) {
			array_unshift( $ids, $por_sku );
		}

		$salida = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}

			// Un producto variable se despliega en sus variaciones vendibles.
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variacion_id ) {
					$variacion = wc_get_product( $variacion_id );
					if ( $variacion && $variacion->is_purchasable() ) {
						$salida[] = $this->fila_producto( $variacion, $sede_id );
					}
				}
				continue;
			}

			if ( $product->is_purchasable() ) {
				$salida[] = $this->fila_producto( $product, $sede_id );
			}
		}

		wp_send_json_success( $salida );
	}

	/**
	 * Formatea un producto (o variación) para la lista de resultados.
	 *
	 * @param WC_Product $product Producto o variación.
	 * @param int        $sede_id Sede.
	 * @return array
	 */
	private function fila_producto( $product, $sede_id ) {
		$nombre = $product->get_name();

		// En una variación, añade los atributos para distinguirla.
		if ( $product->is_type( 'variation' ) ) {
			$atributos = wc_get_formatted_variation( $product, true, false );
			if ( $atributos ) {
				$nombre .= ' (' . $atributos . ')';
			}
		}

		return array(
			'id'     => $product->get_id(),
			'nombre' => $nombre,
			'sku'    => $product->get_sku(),
			'precio' => (float) wc_get_price_to_display( $product ),
			'stock'  => MSP_Stock::disponible_sede( $product->get_id(), $sede_id ),
		);
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

		$dni            = isset( $_POST['dni'] ) ? preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['dni'] ) ) : '';
		$cliente_nombre = isset( $_POST['cliente_nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['cliente_nombre'] ) ) : '';

		if ( '' !== $dni && 8 !== strlen( $dni ) ) {
			wp_send_json_error( array( 'msg' => __( 'El DNI tiene 8 dígitos.', 'multisede-pos' ) ), 400 );
		}

		// Comprobante pedido por el cliente. Se valida entero ANTES de tocar
		// stock o crear el pedido: si falta un dato, la venta no llega a
		// existir y el cajero solo tiene que pedirlo, no anular nada.
		$tipo   = isset( $_POST['tipo_comprobante'] ) ? sanitize_key( wp_unslash( $_POST['tipo_comprobante'] ) ) : 'boleta';
		$tipo   = class_exists( 'MSP_Comprobante' ) ? MSP_Comprobante::tipo_valido( $tipo ) : 'boleta';
		$ruc    = isset( $_POST['ruc'] ) ? preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['ruc'] ) ) : '';
		$razon  = isset( $_POST['razon_social'] ) ? sanitize_text_field( wp_unslash( $_POST['razon_social'] ) ) : '';

		if ( 'factura' === $tipo ) {
			if ( ! MSP_Comprobante::serie_valida( MSP_Comprobante::serie_de_sede( $sede_id, 'factura' ), 'factura' ) ) {
				wp_send_json_error(
					array( 'msg' => __( 'Esta tienda no tiene serie de factura configurada: solo puede emitir boletas.', 'multisede-pos' ) ),
					400
				);
			}
			if ( '' === $ruc ) {
				wp_send_json_error( array( 'msg' => __( 'Para la factura hace falta el RUC del cliente.', 'multisede-pos' ), 'foco_ruc' => true ), 400 );
			}
			if ( ! class_exists( 'MSP_Factura' ) || ! MSP_Factura::ruc_valido( $ruc ) ) {
				wp_send_json_error(
					array(
						'msg'      => __( 'Ese RUC no es válido: revisa que no falte o sobre un dígito.', 'multisede-pos' ),
						'foco_ruc' => true,
					),
					400
				);
			}
			if ( '' === trim( $razon ) ) {
				wp_send_json_error( array( 'msg' => __( 'Falta la razón social del cliente.', 'multisede-pos' ), 'foco_ruc' => true ), 400 );
			}
		}

		// Validar stock disponible (físico − reservado) en la sede.
		$normalizados = array();
		$descuentos   = array();
		$total_previo = 0.0;
		$descontado   = 0.0;
		foreach ( $items as $item ) {
			$pid = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$qty = isset( $item['qty'] ) ? absint( $item['qty'] ) : 0;
			if ( ! $pid || $qty < 1 ) {
				continue;
			}
			$disponible = MSP_Stock::disponible_sede( $pid, $sede_id );
			if ( $qty > $disponible ) {
				wp_send_json_error( array( 'msg' => $this->msg_sin_stock( $pid, $disponible ) ), 409 );
			}

			$producto = wc_get_product( $pid );
			$bruto    = $producto ? round( (float) wc_get_price_including_tax( $producto ) * $qty, 2 ) : 0.0;

			// Descuento de ESTA línea. Se acota contra el precio que acaba de
			// leer el servidor, no contra el que mandó el navegador, y nunca
			// deja la línea en cero: una línea a cero no es una venta y SUNAT
			// no la admite.
			$desc = isset( $item['desc'] ) ? round( (float) $item['desc'], 2 ) : 0.0;
			$desc = ( $desc > 0 && $bruto > 0 ) ? min( $desc, round( $bruto - 0.01, 2 ) ) : 0.0;

			$total_previo        += $bruto - $desc;
			$descontado          += $desc;
			$normalizados[ $pid ] = $qty;
			if ( $desc > 0 ) {
				$descuentos[ $pid ] = $desc;
			}
		}

		if ( empty( $normalizados ) ) {
			wp_send_json_error( array( 'msg' => __( 'No hay productos válidos en el ticket.', 'multisede-pos' ) ), 400 );
		}

		// De aquí en adelante manda el total YA descontado: es el que decide si
		// la boleta tiene que identificar al comprador, y el que se cobra.
		$total_previo = round( $total_previo, 2 );
		$descontado   = round( $descontado, 2 );

		// Cobrar en efectivo exige caja abierta. Si no la hay, ese dinero entra
		// al cajón sin quedar registrado en ninguna parte: no suma al cuadre, no
		// aparece en el arqueo y nadie lo echa de menos hasta que las cuentas no
		// dan. Con otros medios de pago el riesgo es menor, porque no hay
		// efectivo que controlar.
		//
		// Se comprueba antes de descontar stock y crear el pedido, igual que el
		// DNI: si falta, la venta no llega a existir.
		if ( MSP_Caja::falta_caja_para( $metodo, $sede_id, get_current_user_id() ) ) {
			wp_send_json_error(
				array(
					'msg'       => __( 'No tienes la caja abierta en esta tienda. Ábrela para que el efectivo de esta venta quede registrado.', 'multisede-pos' ),
					'sin_caja'  => true,
				),
				409
			);
		}

		// SUNAT exige identificar al comprador cuando la boleta pasa de S/ 700, y
		// identificar es documento Y nombre: una boleta de S/ 900 con un DNI real
		// a nombre de "CLIENTE VARIOS" es contradictoria, y así saldría impresa.
		//
		// Se comprueba ANTES de descontar stock y crear el pedido: si falta el
		// dato, la venta no llega a existir y el cajero solo tiene que pedirlo,
		// no anular nada.
		if ( MSP_Cola::activa() && 'factura' !== $tipo && round( $total_previo, 2 ) > MSP_Comprobante::LIMITE_DNI ) {
			$faltan = array();
			if ( '' === $dni ) {
				$faltan[] = __( 'el DNI', 'multisede-pos' );
			}
			if ( '' === trim( $cliente_nombre ) ) {
				$faltan[] = __( 'el nombre', 'multisede-pos' );
			}

			if ( $faltan ) {
				wp_send_json_error(
					array(
						'msg'      => sprintf(
							/* translators: 1: datos que faltan, 2: importe límite. */
							__( 'Falta %1$s del cliente. Por encima de S/ %2$s la boleta tiene que identificar al comprador. Pídeselo antes de cobrar.', 'multisede-pos' ),
							implode( __( ' y ', 'multisede-pos' ), $faltan ),
							number_format( MSP_Comprobante::LIMITE_DNI, 2 )
						),
						'foco_dni' => true,
					),
					400
				);
			}
		}

		// Descontar el stock antes de crear el pedido. El descuento es
		// condicional y atómico: si otro cajero se adelantó, falla aquí y se
		// devuelve lo ya descontado en lugar de sobrevender.
		$descontados = array();
		foreach ( $normalizados as $pid => $qty ) {
			if ( ! MSP_Stock::descontar_si_hay( $pid, $sede_id, $qty ) ) {
				$this->revertir_descuentos( $descontados, $sede_id );
				wp_send_json_error(
					array( 'msg' => $this->msg_sin_stock( $pid, MSP_Stock::disponible_sede( $pid, $sede_id ) ) ),
					409
				);
			}
			$descontados[ $pid ] = $qty;
		}

		// Crear el pedido.
		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			$this->revertir_descuentos( $descontados, $sede_id );
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
		if ( 'factura' === $tipo ) {
			// En la factura el comprador es el RUC, no el DNI: si el cajero
			// había puesto los dos, manda el de la factura.
			$order->update_meta_data( '_msp_tipo_comprobante', 'factura' );
			$order->update_meta_data( '_msp_cliente_tipo_doc', MSP_Comprobante::DOC_RUC );
			$order->update_meta_data( '_msp_cliente_num_doc', $ruc );
			$order->update_meta_data( '_msp_cliente_nombre', $razon );
		} else {
			if ( $dni ) {
				$order->update_meta_data( '_msp_cliente_tipo_doc', '1' ); // 1 = DNI en el catálogo 06 de SUNAT.
				$order->update_meta_data( '_msp_cliente_num_doc', $dni );
			}
			if ( $cliente_nombre ) {
				$order->update_meta_data( '_msp_cliente_nombre', $cliente_nombre );
			}
		}
		$order->update_meta_data( '_msp_stock_aplicado', '1' );
		$order->calculate_totals();

		// Los descuentos se aplican DESPUÉS de calcular los totales, y el
		// pedido se vuelve a sumar SIN recalcular impuestos: con `true`, Woo
		// devolvería las líneas a su precio de catálogo y el descuento se
		// perdería. Va antes de completar el pedido, porque es al completarlo
		// cuando nace el comprobante: si se hiciera después, la boleta
		// declararía el precio de lista.
		if ( $descuentos ) {
			$this->aplicar_descuentos( $order, $descuentos );
			$order->calculate_totals( false );
			$order->update_meta_data( '_msp_pos_descuento', number_format( $descontado, 2, '.', '' ) );
			$order->add_order_note(
				sprintf(
					/* translators: 1: importe del descuento, 2: nombre del cajero. */
					__( 'Descuento de %1$s aplicado en el POS por %2$s.', 'multisede-pos' ),
					wc_price( $descontado ),
					wp_get_current_user()->display_name
				)
			);
		}

		$order->update_status( 'completed', __( 'Venta en mostrador (POS).', 'multisede-pos' ) );

		// El stock ya se descontó arriba; aquí solo refrescamos el espejo de Woo.
		foreach ( $normalizados as $pid => $qty ) {
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

		// Si la venta generó comprobante, se ofrece el ticket al cajero en el
		// acto: es el momento en que el cliente sigue delante del mostrador.
		$ticket_url = '';
		$comprobante = MSP_Comprobante::obtener_por_pedido( $order->get_id() );
		if ( $comprobante ) {
			// Con la impresión automática encendida, el ticket se abre listo
			// para mandarse a la impresora sin que el cajero pulse nada más.
			$ticket_url = MSP_Ticket::url( (int) $comprobante['id'], MSP_Ticket_EscPos::ajustes()['auto'] );
		}

		wp_send_json_success(
			array(
				'pedido' => $order->get_order_number(),
				'total'  => (float) $order->get_total(),
				'ticket' => $ticket_url,
				'boleta' => $comprobante ? MSP_Comprobante::numero( $comprobante ) : '',
				'msg'    => sprintf(
					/* translators: %s: número de pedido. */
					__( 'Venta registrada. Pedido #%s.', 'multisede-pos' ),
					$order->get_order_number()
				),
			)
		);
	}

	/**
	 * Abre la caja desde el propio POS.
	 *
	 * Existe para no mandar al cajero a otra pantalla con un cliente delante y
	 * el ticket a medias: se abre aquí, se cobra y se sigue.
	 */
	public function ajax_abrir_caja() {
		check_ajax_referer( 'msp_pos', 'nonce' );

		if ( ! current_user_can( 'msp_gestionar_caja' ) ) {
			wp_send_json_error( array( 'msg' => __( 'Sin permiso para abrir caja.', 'multisede-pos' ) ), 403 );
		}

		$sede_id  = isset( $_POST['sede'] ) ? absint( wp_unslash( $_POST['sede'] ) ) : 0;
		$apertura = isset( $_POST['apertura'] ) ? (float) wp_unslash( $_POST['apertura'] ) : 0;

		if ( ! $sede_id || ! $this->puede_usar_sede( $sede_id ) ) {
			wp_send_json_error( array( 'msg' => __( 'Sede no válida.', 'multisede-pos' ) ), 400 );
		}
		if ( $apertura < 0 ) {
			wp_send_json_error( array( 'msg' => __( 'El monto de apertura no puede ser negativo.', 'multisede-pos' ) ), 400 );
		}

		if ( ! MSP_Caja::abrir( $sede_id, get_current_user_id(), $apertura ) ) {
			wp_send_json_error( array( 'msg' => __( 'Ya tenías una caja abierta en esta tienda.', 'multisede-pos' ) ), 409 );
		}

		wp_send_json_success(
			array(
				'msg' => sprintf(
					/* translators: %s: monto de apertura. */
					__( 'Caja abierta con %s. Ya puedes cobrar.', 'multisede-pos' ),
					wp_strip_all_tags( wc_price( $apertura ) )
				),
			)
		);
	}

	/**
	 * Mensaje de stock insuficiente para un producto.
	 *
	 * @param int $producto_id ID de producto/variación.
	 * @param int $disponible  Unidades disponibles.
	 * @return string
	 */
	private function msg_sin_stock( $producto_id, $disponible ) {
		$product = wc_get_product( $producto_id );
		return sprintf(
			/* translators: 1: producto, 2: stock disponible. */
			__( 'Stock insuficiente de "%1$s" en esta sede (disponible: %2$d).', 'multisede-pos' ),
			$product ? $product->get_name() : $producto_id,
			(int) $disponible
		);
	}

	/**
	 * Aplica a cada línea del pedido el descuento que le puso el cajero.
	 *
	 * El descuento NO viaja como una línea negativa ni como un nodo de
	 * descuento global: se baja el importe de la propia línea. Así:
	 *
	 * 1. **El comprobante declara lo cobrado.** `MSP_Emisor::lineas()` arma los
	 *    totales del XML sumando las líneas del pedido; si el descuento viviera
	 *    fuera de ellas, el ticket y su QR dirían un importe y el XML otro, que
	 *    es de las cosas que el verificador de SUNAT sí mira.
	 * 2. **La nota de crédito devuelve lo correcto.** Una devolución parcial
	 *    toma el importe de la línea: con el descuento dentro se devuelve lo
	 *    que el cliente pagó, no el precio de catálogo.
	 * 3. **El ticket imprime el precio real**, porque sale de `get_line_total()`.
	 *
	 * El subtotal de la línea se deja en el precio de lista a propósito: es lo
	 * que permite ver en la ficha del pedido de dónde salió la rebaja.
	 *
	 * @param WC_Order        $order       Pedido recién creado.
	 * @param array<int,float> $descuentos Producto => descuento en soles.
	 */
	private function aplicar_descuentos( $order, $descuentos ) {
		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
			if ( empty( $descuentos[ $pid ] ) ) {
				continue;
			}

			$bruto = round( (float) $item->get_total() + (float) $item->get_total_tax(), 2 );
			if ( $bruto <= 0 ) {
				continue;
			}

			// Último acotado, ya sobre el importe que calculó WooCommerce: la
			// línea nunca puede quedar en cero.
			$desc = min( (float) $descuentos[ $pid ], round( $bruto - 0.01, 2 ) );
			if ( $desc <= 0 ) {
				continue;
			}

			$neto     = round( $bruto - $desc, 2 );
			$factor   = $neto / $bruto;
			$total    = round( (float) $item->get_total() * $factor, 2 );
			$impuesto = round( $neto - $total, 2 );

			$item->set_total( $total );

			// Con los impuestos de Woo apagados —la configuración de saraih: el
			// IGV lo calcula el emisor desde el importe con impuesto incluido—
			// no hay nada que repartir aquí.
			$impuestos = $item->get_taxes();
			if ( ! empty( $impuestos['total'] ) && array_sum( $impuestos['total'] ) > 0 ) {
				$suma = array_sum( $impuestos['total'] );
				foreach ( $impuestos['total'] as $rate_id => $monto ) {
					$impuestos['total'][ $rate_id ] = round( $impuesto * ( (float) $monto / $suma ), 2 );
				}
				$item->set_taxes( $impuestos );
			}

			$item->save();
		}
	}

	/**
	 * Devuelve a la sede el stock ya descontado de un cobro que no prosperó.
	 *
	 * @param array<int,int> $descontados Producto => cantidad.
	 * @param int            $sede_id     Sede.
	 */
	private function revertir_descuentos( $descontados, $sede_id ) {
		foreach ( $descontados as $pid => $qty ) {
			MSP_Stock::ajustar( $pid, $sede_id, $qty );
		}
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

		/**
		 * Venta de mostrador anulada (cancelada o reembolsada).
		 *
		 * La caja chica lo usa para revertir el efectivo de esa venta.
		 *
		 * @param WC_Order $order   Pedido anulado.
		 * @param int      $sede_id Sede.
		 */
		do_action( 'msp_pos_venta_anulada', $order, $sede_id );
	}
}
