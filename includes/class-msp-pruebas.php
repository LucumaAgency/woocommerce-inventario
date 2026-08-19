<?php
/**
 * Banco de pruebas: monta escenarios y empuja la cola sin esperar.
 *
 * Existe porque probar el módulo de boletas a mano es lento por dos motivos que
 * no tienen nada que ver con el sistema: hay que ir a **Acciones programadas** a
 * ejecutar la cola cada vez, y hay que **montar la situación previa** de cada
 * caso (una venta con boleta aceptada, otra atascada, otra de ayer). Entre las
 * dos cosas se va la mitad del tiempo de cada sesión de pruebas.
 *
 * **Solo existe fuera de producción.** En producción ni se registra el menú: no
 * quiero un botón que fabrique ventas falsas al alcance de nadie en la tienda
 * real, y menos uno que consuma numeración de verdad.
 *
 * Lo que NO hace: comprobar por ti. Los fallos de estas semanas salieron todos
 * de mirar pantallas, no de leer código, así que esto acelera los preparativos y
 * deja la comprobación donde debe estar.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Herramientas de prueba del módulo de facturación.
 */
class MSP_Pruebas {

	const PAGE = 'msp-pruebas';

	/** Meta que marca los pedidos fabricados aquí. */
	const META = '_msp_pedido_de_prueba';

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'registrar_pagina' ) );
		add_action( 'admin_init', array( $this, 'procesar' ) );
	}

	/**
	 * ¿Está disponible el banco de pruebas?
	 *
	 * @return bool
	 */
	public static function disponible() {
		return ! MSP_Emisor::es_produccion() && current_user_can( 'manage_options' );
	}

	/**
	 * Registra la pantalla, solo fuera de producción.
	 */
	public function registrar_pagina() {
		if ( MSP_Emisor::es_produccion() ) {
			return;
		}

		add_submenu_page(
			'msp-caja',
			__( 'Pruebas', 'multisede-pos' ),
			__( 'Pruebas', 'multisede-pos' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Ejecuta la acción pedida.
	 */
	public function procesar() {
		if ( ! isset( $_POST['msp_prueba_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! self::disponible() ) {
			return;
		}
		check_admin_referer( 'msp_pruebas', 'msp_pruebas_nonce' );

		$accion  = sanitize_key( wp_unslash( $_POST['msp_prueba_action'] ) );
		$sede_id = isset( $_POST['sede'] ) ? absint( wp_unslash( $_POST['sede'] ) ) : 0;
		$aviso   = '';

		switch ( $accion ) {
			case 'procesar':
				$aviso = $this->procesar_todo();
				break;
			case 'venta_aceptada':
				$aviso = $this->escenario_venta( $sede_id, false, 0 );
				break;
			case 'venta_atascada':
				$aviso = $this->escenario_venta( $sede_id, true, 0 );
				break;
			case 'venta_ayer':
				$aviso = $this->escenario_venta( $sede_id, false, 1 );
				break;
			case 'comprobar_fase4':
				$aviso = $this->comprobar_fase4( $sede_id );
				break;
			case 'stock_uno':
				$aviso = $this->escenario_stock_uno( $sede_id );
				break;
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
	 * Empuja toda la cola en el acto: emisiones, bajas y tickets.
	 *
	 * Es lo que el trabajo de fondo haría solo, pero sin esperar. Además de las
	 * pruebas, sirve en producción cuando algo se atasca y no se quiere entrar a
	 * la pantalla de acciones programadas de WooCommerce.
	 *
	 * @return string Resumen de lo hecho.
	 */
	private function procesar_todo() {
		global $wpdb;

		$hechos  = array();
		$entorno = MSP_Comprobante::entorno_actual();
		$tabla   = MSP_Comprobante::tabla();

		// 1. Comprobantes sin aceptar, tengan o no fecha de próximo intento.
		$pendientes = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$tabla}
				 WHERE entorno = %s AND estado IN ('pendiente','error')
				 ORDER BY id ASC LIMIT 30", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entorno
			)
		);
		foreach ( $pendientes as $id ) {
			MSP_Cola::procesar( (int) $id );
		}
		if ( $pendientes ) {
			$hechos[] = sprintf(
				/* translators: %d: cantidad. */
				_n( '%d comprobante enviado', '%d comprobantes enviados', count( $pendientes ), 'multisede-pos' ),
				count( $pendientes )
			);
		}

		// 2. Agrupar las bajas marcadas.
		MSP_Baja::agrupar_y_enviar();

		// 3. Empujar los resúmenes: los que falta enviar y los que esperan ticket.
		$tabla_r   = MSP_Resumen::tabla();
		$resumenes = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, estado FROM {$tabla_r}
				 WHERE entorno = %s AND estado IN ('pendiente','enviado','error')
				 ORDER BY id ASC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entorno
			),
			ARRAY_A
		);
		foreach ( $resumenes as $r ) {
			if ( 'enviado' === $r['estado'] ) {
				MSP_Baja::consultar( (int) $r['id'] );
			} else {
				MSP_Baja::enviar( (int) $r['id'] );
				// Recién enviado: se pregunta ya, por si SUNAT contesta rápido.
				MSP_Baja::consultar( (int) $r['id'] );
			}
		}
		if ( $resumenes ) {
			$hechos[] = sprintf(
				/* translators: %d: cantidad. */
				_n( '%d resumen de bajas empujado', '%d resúmenes de bajas empujados', count( $resumenes ), 'multisede-pos' ),
				count( $resumenes )
			);
		}

		return $hechos
			? implode( ' · ', $hechos )
			: __( 'No había nada pendiente.', 'multisede-pos' );
	}

	/**
	 * Producto con stock en una sede, para fabricar ventas.
	 *
	 * @param int $sede_id Sede.
	 * @return int ID de producto, o 0.
	 */
	private function producto_con_stock( $sede_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT producto_id FROM ' . MSP_Stock::tabla() . '
				 WHERE sede_id = %d AND (stock - stock_reservado) > 0
				 ORDER BY (stock - stock_reservado) DESC LIMIT 1',
				$sede_id
			)
		);
	}

	/**
	 * Fabrica una venta de mostrador y la lleva hasta donde toque.
	 *
	 * @param int  $sede_id     Sede.
	 * @param bool $atascada    Si true, la boleta se queda sin emitir.
	 * @param int  $dias_atras  Días a restar de la fecha de emisión.
	 * @return string Aviso.
	 */
	private function escenario_venta( $sede_id, $atascada, $dias_atras ) {
		$r = $this->fabricar_venta( $sede_id, $atascada, $dias_atras );

		if ( is_wp_error( $r ) ) {
			return $r->get_error_message();
		}

		return sprintf(
			/* translators: 1: número de pedido, 2: número de comprobante, 3: estado. */
			__( 'Pedido #%1$s con comprobante %2$s → %3$s', 'multisede-pos' ),
			$r['order']->get_order_number(),
			MSP_Comprobante::numero( $r['comprobante'] ),
			strtoupper( $r['comprobante']['estado'] )
		);
	}

	/**
	 * Fabrica la venta y devuelve el pedido y su comprobante.
	 *
	 * @param int  $sede_id    Sede.
	 * @param bool $atascada   Si true, la boleta se queda sin emitir.
	 * @param int  $dias_atras Días a restar de la fecha de emisión.
	 * @return array|WP_Error {order, comprobante}
	 */
	private function fabricar_venta( $sede_id, $atascada, $dias_atras ) {
		if ( ! $sede_id ) {
			return new WP_Error( 'msp_sin_sede', __( 'Elige una tienda.', 'multisede-pos' ) );
		}
		if ( ! MSP_Cola::activa() ) {
			return new WP_Error( 'msp_sin_cola', __( 'Enciende la emisión automática en Facturación: sin ella no se genera ningún comprobante.', 'multisede-pos' ) );
		}

		$producto_id = $this->producto_con_stock( $sede_id );
		if ( ! $producto_id ) {
			return new WP_Error( 'msp_sin_stock', __( 'Esa tienda no tiene ningún producto con stock disponible.', 'multisede-pos' ) );
		}

		$producto = wc_get_product( $producto_id );
		if ( ! $producto ) {
			return new WP_Error( 'msp_sin_producto', __( 'El producto con stock ya no existe.', 'multisede-pos' ) );
		}

		$ajustes = MSP_Emisor::ajustes();
		$previo  = ! empty( $ajustes['simular_fallo'] );

		// Para la venta atascada se enciende el simulador solo durante la
		// emisión, y se deja como estaba después: si se quedara encendido, las
		// pruebas siguientes fallarían sin motivo aparente.
		if ( $atascada ) {
			$ajustes['simular_fallo'] = 1;
			update_option( MSP_Emisor::OPCION, $ajustes );
		}

		if ( ! MSP_Stock::descontar_si_hay( $producto_id, $sede_id, 1 ) ) {
			return new WP_Error( 'msp_stock', __( 'No se pudo descontar el stock.', 'multisede-pos' ) );
		}

		$order = wc_create_order();
		$order->add_product( $producto, 1 );
		$order->set_created_via( 'msp-pos' );
		$order->set_payment_method( 'msp_pos' );
		$order->set_payment_method_title( __( 'Efectivo (POS)', 'multisede-pos' ) );
		$order->update_meta_data( '_msp_sede_id', $sede_id );
		$order->update_meta_data( '_msp_origen', 'pos' );
		$order->update_meta_data( '_msp_recogido', '1' );
		$order->update_meta_data( '_msp_reserva_estado', 'recogido' );
		$order->update_meta_data( '_msp_pos_metodo', 'efectivo' );
		$order->update_meta_data( '_msp_cajero_id', get_current_user_id() );
		$order->update_meta_data( '_msp_stock_aplicado', '1' );
		$order->update_meta_data( self::META, '1' );
		$order->calculate_totals();
		$order->update_status( 'completed', __( 'Venta fabricada desde el banco de pruebas.', 'multisede-pos' ) );
		$order->save();

		MSP_Stock::sincronizar_woo( $producto_id );

		do_action( 'msp_pos_venta_creada', $order, 'efectivo', $sede_id );

		$comprobante = MSP_Comprobante::obtener_por_pedido( $order->get_id() );
		if ( ! $comprobante ) {
			if ( $atascada && ! $previo ) {
				$ajustes['simular_fallo'] = 0;
				update_option( MSP_Emisor::OPCION, $ajustes );
			}
			return new WP_Error( 'msp_sin_comprobante', __( 'La venta se creó pero no generó comprobante. Revisa que la sede tenga serie de boleta.', 'multisede-pos' ) );
		}

		// Fecha hacia atrás: lo que agrupa las bajas es la fecha de EMISIÓN, así
		// que hay que moverla en el comprobante, no en el pedido.
		if ( $dias_atras > 0 ) {
			global $wpdb;
			$fecha = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $dias_atras * DAY_IN_SECONDS ) );
			$wpdb->update(
				MSP_Comprobante::tabla(),
				array( 'emitido_at' => $fecha ),
				array( 'id' => (int) $comprobante['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}

		MSP_Cola::procesar( (int) $comprobante['id'] );

		if ( $atascada && ! $previo ) {
			$ajustes['simular_fallo'] = 0;
			update_option( MSP_Emisor::OPCION, $ajustes );
		}

		return array(
			'order'       => $order,
			'comprobante' => MSP_Comprobante::obtener( (int) $comprobante['id'] ),
		);
	}

	/**
	 * Comprueba sola los tres casos de la Fase 4 (36, 36 bis y 37).
	 *
	 * Estos tres se pueden automatizar y los demás no, y la diferencia importa:
	 * aquí no hay nada visual que juzgar. Todo es estado en la base de datos y
	 * respuestas de SUNAT, así que una comprobación automática vale tanto como
	 * mirarlo a mano y tarda segundos en vez de diez minutos.
	 *
	 * Los casos que siguen siendo manuales lo son porque dependen de algo que un
	 * script no ve: entrar como cajero (permisos), la maquetación del checkout,
	 * o escanear un QR con el móvil.
	 *
	 * **Emite de verdad contra el sandbox**, así que consume numeración de la
	 * serie de beta. Es lo esperado.
	 *
	 * @param int $sede_id Sede.
	 * @return string Resultado en texto.
	 */
	private function comprobar_fase4( $sede_id ) {
		if ( ! MSP_Cola::activa() ) {
			return __( 'Enciende la emisión automática antes de comprobar.', 'multisede-pos' );
		}

		$lineas = array();
		$fallos = 0;

		// ── Caso 36: dos boletas de días distintos, dos resúmenes ────────────
		$hoy   = $this->fabricar_venta( $sede_id, false, 0 );
		$ayer  = $this->fabricar_venta( $sede_id, false, 1 );

		if ( is_wp_error( $hoy ) || is_wp_error( $ayer ) ) {
			return __( 'No se pudieron fabricar las ventas de prueba: ', 'multisede-pos' ) .
				( is_wp_error( $hoy ) ? $hoy->get_error_message() : $ayer->get_error_message() );
		}

		$hoy['order']->update_status( 'cancelled' );
		$ayer['order']->update_status( 'cancelled' );

		$this->procesar_todo();

		$c_hoy  = MSP_Comprobante::obtener( (int) $hoy['comprobante']['id'] );
		$c_ayer = MSP_Comprobante::obtener( (int) $ayer['comprobante']['id'] );

		$r_hoy  = $c_hoy['resumen_id'] ? MSP_Resumen::obtener( (int) $c_hoy['resumen_id'] ) : null;
		$r_ayer = $c_ayer['resumen_id'] ? MSP_Resumen::obtener( (int) $c_ayer['resumen_id'] ) : null;

		if ( ! $r_hoy || ! $r_ayer ) {
			$lineas[] = '❌ 36 · ' . __( 'alguna boleta no entró en ningún resumen.', 'multisede-pos' );
			$fallos++;
		} elseif ( (int) $r_hoy['id'] === (int) $r_ayer['id'] ) {
			$lineas[] = '❌ 36 · ' . __( 'las dos boletas cayeron en el MISMO resumen; deberían ir en uno por día de emisión.', 'multisede-pos' );
			$fallos++;
		} elseif ( 'aceptado' !== $r_hoy['estado'] || 'aceptado' !== $r_ayer['estado'] ) {
			$lineas[] = sprintf(
				/* translators: 1: resumen de hoy, 2: estado, 3: resumen de ayer, 4: estado, 5: error. */
				'❌ 36 · %1$s → %2$s · %3$s → %4$s · %5$s',
				$r_hoy['identificador'],
				strtoupper( $r_hoy['estado'] ),
				$r_ayer['identificador'],
				strtoupper( $r_ayer['estado'] ),
				$r_ayer['ultimo_error'] ? $r_ayer['ultimo_error'] : $r_hoy['ultimo_error']
			);
			$fallos++;
		} else {
			$lineas[] = sprintf(
				/* translators: 1: resumen de hoy, 2: resumen de ayer. */
				'✅ 36 · ' . __( 'dos resúmenes aceptados: %1$s (hoy) y %2$s (la boleta de ayer).', 'multisede-pos' ),
				$r_hoy['identificador'],
				$r_ayer['identificador']
			);
		}

		// ── Caso 36 bis: rehacer una baja rechazada ──────────────────────────
		// El rechazo se fuerza a mano: SUNAT no lo produce a voluntad, y lo que
		// se comprueba aquí es NUESTRA máquina de estados, no la suya.
		$victima = MSP_Comprobante::obtener( (int) $hoy['comprobante']['id'] );
		if ( $victima && $victima['resumen_id'] ) {
			$resumen_viejo = (int) $victima['resumen_id'];
			MSP_Comprobante::actualizar( (int) $victima['id'], array( 'baja_estado' => 'rechazada' ) );
			MSP_Comprobante::actualizar(
				(int) $victima['id'],
				array(
					'baja_estado' => 'pendiente',
					'resumen_id'  => 0,
				)
			);
			$this->procesar_todo();

			$tras = MSP_Comprobante::obtener( (int) $victima['id'] );
			if ( 'anulado' === $tras['baja_estado'] && (int) $tras['resumen_id'] !== $resumen_viejo ) {
				$lineas[] = '✅ 36 bis · ' . __( 'la baja rehecha entró en un resumen NUEVO y quedó aceptada.', 'multisede-pos' );
			} else {
				$lineas[] = sprintf(
					/* translators: 1: estado de baja, 2: id de resumen. */
					'❌ 36 bis · ' . __( 'quedó en "%1$s" con resumen %2$d (se esperaba anulado en uno nuevo).', 'multisede-pos' ),
					$tras['baja_estado'],
					(int) $tras['resumen_id']
				);
				$fallos++;
			}
		}

		// ── Caso 37: el resumen falla y se recupera ──────────────────────────
		$tercera = $this->fabricar_venta( $sede_id, false, 0 );
		if ( ! is_wp_error( $tercera ) ) {
			$tercera['order']->update_status( 'cancelled' );

			$ajustes                  = MSP_Emisor::ajustes();
			$previo                   = ! empty( $ajustes['simular_fallo'] );
			$ajustes['simular_fallo'] = 1;
			update_option( MSP_Emisor::OPCION, $ajustes );

			$this->procesar_todo();

			$c   = MSP_Comprobante::obtener( (int) $tercera['comprobante']['id'] );
			$res = $c['resumen_id'] ? MSP_Resumen::obtener( (int) $c['resumen_id'] ) : null;
			$fallo_ok = $res && 'error' === $res['estado'];

			$ajustes['simular_fallo'] = $previo ? 1 : 0;
			update_option( MSP_Emisor::OPCION, $ajustes );

			$this->procesar_todo();

			$res_final = $res ? MSP_Resumen::obtener( (int) $res['id'] ) : null;

			if ( $fallo_ok && $res_final && 'aceptado' === $res_final['estado'] ) {
				$lineas[] = '✅ 37 · ' . __( 'el resumen falló, quedó en reintento y se recuperó al quitar el fallo.', 'multisede-pos' );
			} else {
				$lineas[] = sprintf(
					/* translators: 1: si falló como debía, 2: estado final. */
					'❌ 37 · ' . __( 'falló como debía: %1$s · estado final: %2$s', 'multisede-pos' ),
					$fallo_ok ? 'sí' : 'no',
					$res_final ? strtoupper( $res_final['estado'] ) : '—'
				);
				$fallos++;
			}
		}

		$cabecera = 0 === $fallos
			? __( 'Fase 4: los tres casos pasan.', 'multisede-pos' )
			: sprintf(
				/* translators: %d: número de fallos. */
				_n( 'Fase 4: %d caso falla.', 'Fase 4: %d casos fallan.', $fallos, 'multisede-pos' ),
				$fallos
			);

		return $cabecera . ' — ' . implode( ' | ', $lineas );
	}

	/**
	 * Deja un producto con exactamente una unidad disponible en la sede.
	 *
	 * @param int $sede_id Sede.
	 * @return string Aviso.
	 */
	private function escenario_stock_uno( $sede_id ) {
		if ( ! $sede_id ) {
			return __( 'Elige una tienda.', 'multisede-pos' );
		}

		$producto_id = $this->producto_con_stock( $sede_id );
		if ( ! $producto_id ) {
			return __( 'Esa tienda no tiene ningún producto con stock.', 'multisede-pos' );
		}

		MSP_Stock::set( $producto_id, $sede_id, 1 );
		MSP_Stock::sincronizar_woo( $producto_id );

		$producto = wc_get_product( $producto_id );

		return sprintf(
			/* translators: %s: nombre del producto. */
			__( '"%s" queda con 1 unidad en esa tienda. Añádelo al carrito en la web y véndelo en el POS antes de pagar.', 'multisede-pos' ),
			$producto ? $producto->get_name() : $producto_id
		);
	}

	/**
	 * Pinta la pantalla.
	 */
	public function render() {
		$aviso = isset( $_GET['aviso'] ) ? sanitize_text_field( wp_unslash( $_GET['aviso'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sedes = MSP_Sedes::obtener_sedes_activas();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Banco de pruebas', 'multisede-pos' ); ?></h1>
			<p class="description" style="max-width:70ch">
				<?php esc_html_e( 'Atajos para no perder tiempo montando cada caso a mano. Esta pantalla no existe en producción.', 'multisede-pos' ); ?>
			</p>

			<?php if ( $aviso ) : ?>
				<div class="notice notice-info"><p style="line-height:1.7"><?php echo esc_html( $aviso ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Empujar la cola', 'multisede-pos' ); ?></h2>
			<p style="max-width:70ch">
				<?php esc_html_e( 'Hace ahora lo que el trabajo de fondo haría solo: envía los comprobantes pendientes, agrupa las bajas marcadas y consulta los tickets de los resúmenes. Evita el paseo a Acciones programadas.', 'multisede-pos' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'msp_pruebas', 'msp_pruebas_nonce' ); ?>
				<input type="hidden" name="msp_prueba_action" value="procesar" />
				<?php submit_button( __( 'Procesar todo ahora', 'multisede-pos' ), 'primary', 'submit', false ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Comprobar sola la Fase 4', 'multisede-pos' ); ?></h2>
			<p style="max-width:70ch">
				<?php esc_html_e( 'Ejecuta los casos 36, 36 bis y 37 y dice si pasan: que dos boletas de días distintos generen dos resúmenes, que una baja rechazada se pueda rehacer en un resumen nuevo, y que un resumen fallido se recupere.', 'multisede-pos' ); ?>
			</p>
			<p style="max-width:70ch">
				<strong><?php esc_html_e( 'Estos tres se pueden automatizar y los demás no', 'multisede-pos' ); ?></strong>
				<?php esc_html_e( 'porque aquí no hay nada visual que juzgar: es estado en la base de datos y respuestas de SUNAT. Entrar como cajero, la maquetación del checkout o escanear el QR no los ve un script.', 'multisede-pos' ); ?>
			</p>
			<p style="max-width:70ch">
				<em><?php esc_html_e( 'Emite de verdad contra el sandbox: fabrica tres ventas y consume numeración de la serie de beta.', 'multisede-pos' ); ?></em>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'msp_pruebas', 'msp_pruebas_nonce' ); ?>
				<input type="hidden" name="msp_prueba_action" value="comprobar_fase4" />
				<p>
					<label><?php esc_html_e( 'Tienda:', 'multisede-pos' ); ?>
						<select name="sede">
							<?php foreach ( $sedes as $sede ) : ?>
								<?php $serie = MSP_Comprobante::serie_de_sede( $sede->ID ); ?>
								<option value="<?php echo esc_attr( $sede->ID ); ?>">
									<?php echo esc_html( $sede->post_title . ( $serie ? " ({$serie})" : '' ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
				</p>
				<?php submit_button( __( 'Comprobar Fase 4', 'multisede-pos' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Montar un escenario', 'multisede-pos' ); ?></h2>
			<p style="max-width:70ch">
				<?php esc_html_e( 'Fabrica una venta de mostrador con el primer producto que tenga stock en la tienda elegida. Los pedidos quedan marcados como de prueba.', 'multisede-pos' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'msp_pruebas', 'msp_pruebas_nonce' ); ?>
				<p>
					<label><?php esc_html_e( 'Tienda:', 'multisede-pos' ); ?>
						<select name="sede">
							<?php foreach ( $sedes as $sede ) : ?>
								<?php $serie = MSP_Comprobante::serie_de_sede( $sede->ID ); ?>
								<option value="<?php echo esc_attr( $sede->ID ); ?>">
									<?php echo esc_html( $sede->post_title . ( $serie ? " ({$serie})" : '' ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
				</p>

				<table class="widefat striped" style="max-width:860px">
					<tbody>
						<tr>
							<td style="width:280px">
								<button type="submit" class="button" name="msp_prueba_action" value="venta_aceptada">
									<?php esc_html_e( 'Venta con boleta aceptada', 'multisede-pos' ); ?>
								</button>
							</td>
							<td><?php esc_html_e( 'Para los casos de anulación: cobra y emite de una vez, sin esperar a la cola.', 'multisede-pos' ); ?></td>
						</tr>
						<tr>
							<td>
								<button type="submit" class="button" name="msp_prueba_action" value="venta_atascada">
									<?php esc_html_e( 'Venta con boleta atascada', 'multisede-pos' ); ?>
								</button>
							</td>
							<td><?php esc_html_e( 'La deja en "Reintentando", sin llegar a SUNAT. Enciende el simulador de fallo solo durante el envío y lo devuelve a como estaba.', 'multisede-pos' ); ?></td>
						</tr>
						<tr>
							<td>
								<button type="submit" class="button" name="msp_prueba_action" value="venta_ayer">
									<?php esc_html_e( 'Venta fechada ayer', 'multisede-pos' ); ?>
								</button>
							</td>
							<td><?php esc_html_e( 'Para el caso de la agrupación por día: anulándola junto a una de hoy deben salir dos resúmenes.', 'multisede-pos' ); ?></td>
						</tr>
						<tr>
							<td>
								<button type="submit" class="button" name="msp_prueba_action" value="stock_uno">
									<?php esc_html_e( 'Dejar un producto con 1 unidad', 'multisede-pos' ); ?>
								</button>
							</td>
							<td><?php esc_html_e( 'Para el caso de la reserva atómica: añádelo al carrito y véndelo en el POS antes de pagar.', 'multisede-pos' ); ?></td>
						</tr>
					</tbody>
				</table>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Lo que esto no hace', 'multisede-pos' ); ?></h2>
			<p style="max-width:70ch">
				<?php esc_html_e( 'Comprobar por ti. Y hay cuatro cosas que seguirán siendo manuales porque son justo las que más importan: entrar como cajero para probar permisos, la maquetación del checkout, imprimir y escanear el QR, y la comprobación de que el POS y la web siguen vendiendo.', 'multisede-pos' ); ?>
			</p>
		</div>
		<?php
	}
}
