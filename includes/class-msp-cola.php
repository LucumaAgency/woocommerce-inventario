<?php
/**
 * Cola de emisión: decide CUÁNDO se emite, y reintenta cuando SUNAT no está.
 *
 * Fase 3. Regla de oro del módulo: **la emisión nunca ocurre dentro del cobro**.
 * El cajero cierra la venta contra la caja registradora, no contra SUNAT. Si el
 * web service tarda quince segundos o está caído, eso es problema de la cola, no
 * de la persona que tiene una cola de clientes delante.
 *
 * Por eso el flujo es: el POS cobra → se reserva el correlativo (rápido, local)
 * → se encola → un proceso de fondo emite. Lo único que puede fallar delante del
 * cajero es la reserva, que es un INSERT.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encolado, reintentos y alarma de comprobantes electrónicos.
 */
class MSP_Cola {

	/** Hook de la acción en segundo plano que emite un comprobante. */
	const HOOK_EMITIR = 'msp_emitir_comprobante';

	/** Hook del barrido periódico (reintentos + alarma). */
	const HOOK_BARRIDO = 'msp_barrido_comprobantes';

	/** Grupo de Action Scheduler, para poder verlos filtrados en su pantalla. */
	const GRUPO = 'multisede-pos';

	/**
	 * Espera antes de cada reintento, en segundos. Creciente a propósito.
	 *
	 * Un fallo de SUNAT casi nunca dura un minuto y casi siempre dura menos de
	 * un día. Reintentar cada minuto durante horas solo consigue que nos
	 * bloqueen; esperar un día desde el principio retrasa una boleta que se
	 * habría emitido sola. La escala cubre las dos formas de fallo.
	 *
	 * Al agotarse la lista, el comprobante deja de reintentarse y queda a la
	 * vista en la pantalla de Comprobantes: a partir de ahí es trabajo humano.
	 */
	const ESPERAS = array(
		2 * MINUTE_IN_SECONDS,
		10 * MINUTE_IN_SECONDS,
		30 * MINUTE_IN_SECONDS,
		HOUR_IN_SECONDS,
		3 * HOUR_IN_SECONDS,
		6 * HOUR_IN_SECONDS,
		12 * HOUR_IN_SECONDS,
		DAY_IN_SECONDS,
	);

	/**
	 * Días sin resolverse tras los que se avisa por correo.
	 */
	const DIAS_ALARMA = 2;

	/**
	 * Engancha hooks.
	 */
	public function init() {
		// La venta de mostrador es el disparador principal.
		add_action( 'msp_pos_venta_creada', array( $this, 'al_vender_en_pos' ), 20, 3 );

		// Trabajo de fondo.
		add_action( self::HOOK_EMITIR, array( __CLASS__, 'procesar' ) );
		add_action( self::HOOK_BARRIDO, array( __CLASS__, 'barrido' ) );

		add_action( 'init', array( __CLASS__, 'programar_barrido' ) );
	}

	/**
	 * ¿Está la emisión automática activada?
	 *
	 * Apagada por defecto. Mientras saraih no tenga sus series definitivas y el
	 * entorno en producción, encolar cada venta solo gastaría numeración de
	 * prueba. Se enciende desde la pantalla de Facturación.
	 *
	 * @return bool
	 */
	public static function activa() {
		$a = MSP_Emisor::ajustes();
		return ! empty( $a['emision_automatica'] ) && msp_facturacion_disponible();
	}

	/**
	 * Reserva y encola el comprobante de una venta de mostrador.
	 *
	 * @param WC_Order $order   Pedido creado por el POS.
	 * @param string   $metodo  Método de pago.
	 * @param int      $sede_id Sede.
	 */
	public function al_vender_en_pos( $order, $metodo, $sede_id ) {
		unset( $metodo );

		if ( ! self::activa() || ! $order ) {
			return;
		}

		self::encolar_pedido( $order, (int) $sede_id );
	}

	/**
	 * Reserva el correlativo de un pedido y programa su emisión.
	 *
	 * Idempotente: si el pedido ya tiene comprobante, no reserva otro. Un
	 * segundo comprobante para la misma venta sería un duplicado ante SUNAT.
	 *
	 * @param WC_Order $order   Pedido.
	 * @param int      $sede_id Sede emisora.
	 * @return array|WP_Error Fila del comprobante o error.
	 */
	public static function encolar_pedido( $order, $sede_id = 0 ) {
		$pedido_id = $order->get_id();

		$existente = MSP_Comprobante::obtener_por_pedido( $pedido_id );
		if ( $existente ) {
			return $existente;
		}

		if ( ! $sede_id ) {
			$sede_id = (int) $order->get_meta( '_msp_sede_id' );
		}

		$total = round( (float) $order->get_total(), 2 );
		if ( $total <= 0 ) {
			return new WP_Error( 'msp_total_cero', __( 'No se emite comprobante de un pedido sin importe.', 'multisede-pos' ) );
		}

		$comprobante = MSP_Comprobante::reservar(
			array(
				'sede_id'          => $sede_id,
				'pedido_id'        => $pedido_id,
				'tipo'             => 'boleta',
				'cliente_tipo_doc' => $order->get_meta( '_msp_cliente_tipo_doc' ) ? $order->get_meta( '_msp_cliente_tipo_doc' ) : '0',
				'cliente_num_doc'  => (string) $order->get_meta( '_msp_cliente_num_doc' ),
				'cliente_nombre'   => self::nombre_cliente( $order ),
				'total'            => $total,
				'igv'              => self::igv_de_pedido( $order, $total ),
			)
		);

		if ( is_wp_error( $comprobante ) ) {
			// El cobro ya ocurrió: no se revierte una venta porque falle la
			// boleta. Se deja constancia en el pedido y se avisa en pantalla.
			$order->add_order_note(
				sprintf(
					/* translators: %s: mensaje de error. */
					__( 'No se pudo reservar el comprobante electrónico: %s', 'multisede-pos' ),
					$comprobante->get_error_message()
				)
			);
			$order->save();
			return $comprobante;
		}

		$order->update_meta_data( '_msp_comprobante_id', (int) $comprobante['id'] );
		$order->add_order_note(
			sprintf(
				/* translators: %s: número del comprobante. */
				__( 'Comprobante %s reservado y encolado para envío a SUNAT.', 'multisede-pos' ),
				MSP_Comprobante::numero( $comprobante )
			)
		);
		$order->save();

		self::programar( (int) $comprobante['id'], 0 );

		return $comprobante;
	}

	/**
	 * IGV del pedido, tomado de Woo y con red de seguridad.
	 *
	 * Se usa el impuesto que Woo calculó, no `total × 0.18`: si un producto
	 * estuviera exonerado el cálculo a mano mentiría. Si Woo no tiene impuestos
	 * configurados devuelve 0, y ahí sí se desglosa asumiendo 18 % incluido.
	 *
	 * El desglose se hace como `total − base`, nunca `base × 0.18`: con los
	 * precios de saraih hay 9 de 44 en los que el redondeo difiere un céntimo y
	 * SUNAT rechaza el comprobante por descuadre.
	 *
	 * @param WC_Order $order Pedido.
	 * @param float    $total Total con IGV.
	 * @return float
	 */
	public static function igv_de_pedido( $order, $total ) {
		$igv = round( (float) $order->get_total_tax(), 2 );
		if ( $igv > 0 ) {
			return $igv;
		}

		$base = round( $total / 1.18, 2 );
		return round( $total - $base, 2 );
	}

	/**
	 * Nombre del cliente para la boleta.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	public static function nombre_cliente( $order ) {
		$nombre = trim( (string) $order->get_meta( '_msp_cliente_nombre' ) );
		if ( '' === $nombre ) {
			$nombre = trim( $order->get_formatted_billing_full_name() );
		}
		return '' !== $nombre ? $nombre : 'CLIENTE VARIOS';
	}

	/**
	 * Programa la emisión de un comprobante dentro de N segundos.
	 *
	 * Usa Action Scheduler si está (viene con WooCommerce, así que en la
	 * práctica siempre); si no, cae a WP-Cron. La diferencia importa: WP-Cron
	 * depende de que alguien visite el sitio, y una tienda cerrada de noche no
	 * recibe visitas.
	 *
	 * @param int $comprobante_id ID del comprobante.
	 * @param int $espera         Segundos de espera.
	 */
	public static function programar( $comprobante_id, $espera = 0 ) {
		$comprobante_id = (int) $comprobante_id;
		$cuando         = time() + max( 0, (int) $espera );

		// La columna guarda hora local del sitio, igual que emitido_at: así las
		// dos fechas de la pantalla se comparan sin traducir zonas.
		$local = $cuando + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		MSP_Comprobante::actualizar(
			$comprobante_id,
			array( 'proximo_intento' => gmdate( 'Y-m-d H:i:s', $local ) )
		);

		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( self::HOOK_EMITIR, array( $comprobante_id ), self::GRUPO ) ) {
				return;
			}
			as_schedule_single_action( $cuando, self::HOOK_EMITIR, array( $comprobante_id ), self::GRUPO );
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK_EMITIR, array( $comprobante_id ) ) ) {
			wp_schedule_single_event( $cuando, self::HOOK_EMITIR, array( $comprobante_id ) );
		}
	}

	/**
	 * Emite un comprobante y decide qué hacer con el resultado.
	 *
	 * @param int $comprobante_id ID del comprobante.
	 */
	public static function procesar( $comprobante_id ) {
		$comprobante_id = (int) $comprobante_id;
		$c              = MSP_Comprobante::obtener( $comprobante_id );

		if ( ! $c || 'aceptado' === $c['estado'] ) {
			return;
		}

		// Guarda dura: un comprobante reservado en beta NO se envía desde un
		// sitio que ya apunta a producción. Sería emitir de verdad una boleta
		// que era una prueba, y eso solo se deshace con una anulación ante
		// SUNAT. La cola lo deja quieto, sin reprogramar.
		if ( isset( $c['entorno'] ) && $c['entorno'] !== MSP_Comprobante::entorno_actual() ) {
			MSP_Comprobante::actualizar( $comprobante_id, array( 'proximo_intento' => null ) );
			return;
		}

		// Un rechazo es un dato mal puesto, no un fallo pasajero: reintentarlo
		// sin tocar nada da exactamente el mismo rechazo. Solo se reintenta a
		// mano, desde la pantalla de Comprobantes.
		if ( 'rechazado' === $c['estado'] ) {
			MSP_Comprobante::actualizar( $comprobante_id, array( 'proximo_intento' => null ) );
			return;
		}

		$intentos = (int) $c['intentos'] + 1;
		MSP_Comprobante::actualizar(
			$comprobante_id,
			array(
				'intentos'   => $intentos,
				'enviado_at' => current_time( 'mysql' ),
			)
		);

		$resultado = MSP_Emisor::emitir( $comprobante_id );

		if ( ! is_wp_error( $resultado ) && isset( $resultado['estado'] ) && 'aceptado' === $resultado['estado'] ) {
			MSP_Comprobante::actualizar( $comprobante_id, array( 'proximo_intento' => null ) );
			self::anotar_en_pedido( $c, sprintf(
				/* translators: %s: número del comprobante. */
				__( 'Comprobante %s ACEPTADO por SUNAT.', 'multisede-pos' ),
				MSP_Comprobante::numero( $c )
			) );
			return;
		}

		// Rechazo formal: se detiene la cola, queda visible para revisión.
		$estado_actual = MSP_Comprobante::obtener( $comprobante_id );
		if ( $estado_actual && 'rechazado' === $estado_actual['estado'] ) {
			MSP_Comprobante::actualizar( $comprobante_id, array( 'proximo_intento' => null ) );
			self::anotar_en_pedido( $c, sprintf(
				/* translators: 1: número, 2: motivo. */
				__( 'Comprobante %1$s RECHAZADO por SUNAT: %2$s', 'multisede-pos' ),
				MSP_Comprobante::numero( $c ),
				$estado_actual['ultimo_error']
			) );
			return;
		}

		// Fallo pasajero (red, SOAP, mantenimiento de SUNAT): se reintenta.
		if ( isset( self::ESPERAS[ $intentos - 1 ] ) ) {
			self::programar( $comprobante_id, self::ESPERAS[ $intentos - 1 ] );
			return;
		}

		// Agotados los reintentos: deja de intentarlo solo.
		MSP_Comprobante::actualizar( $comprobante_id, array( 'proximo_intento' => null ) );
	}

	/**
	 * Barrido periódico: rescata lo que se quedó sin acción programada y avisa.
	 *
	 * Es la red de seguridad de la cola. Si Action Scheduler pierde una acción
	 * (pasa: un deploy a mitad de ejecución, una tabla vaciada), el comprobante
	 * se quedaría esperando para siempre y nadie se enteraría hasta que SUNAT
	 * preguntara.
	 */
	public static function barrido() {
		foreach ( MSP_Comprobante::pendientes_de_reintento( 20 ) as $c ) {
			self::procesar( (int) $c['id'] );
		}

		self::alarma();
	}

	/**
	 * Avisa por correo de los comprobantes atascados más de dos días.
	 *
	 * Se avisa una sola vez por comprobante (`alertado_at`): un recordatorio
	 * diario acaba en la carpeta de spam y deja de leerse, que es peor que no
	 * mandarlo.
	 */
	public static function alarma() {
		$atascados = MSP_Comprobante::atascados( self::DIAS_ALARMA );
		if ( ! $atascados ) {
			return;
		}

		$lineas = array();
		foreach ( $atascados as $c ) {
			$lineas[] = sprintf(
				'- %s (%s) · S/ %s · %s · %s',
				MSP_Comprobante::numero( $c ),
				strtoupper( $c['estado'] ),
				number_format( (float) $c['total'], 2 ),
				$c['emitido_at'],
				$c['ultimo_error'] ? $c['ultimo_error'] : __( 'sin detalle', 'multisede-pos' )
			);
			MSP_Comprobante::actualizar( (int) $c['id'], array( 'alertado_at' => current_time( 'mysql' ) ) );
		}

		$destino = apply_filters( 'msp_alarma_destinatario', get_option( 'admin_email' ) );

		$cuerpo = sprintf(
			/* translators: 1: cantidad, 2: días, 3: lista, 4: URL del panel. */
			__(
				"Hay %1\$d comprobante(s) sin aceptar desde hace más de %2\$d días:\n\n%3\$s\n\nRevísalos en: %4\$s\n\nMientras un comprobante no esté aceptado, esa venta no le consta a SUNAT.",
				'multisede-pos'
			),
			count( $atascados ),
			self::DIAS_ALARMA,
			implode( "\n", $lineas ),
			admin_url( 'admin.php?page=' . MSP_Comprobantes::PAGE )
		);

		wp_mail(
			$destino,
			sprintf(
				/* translators: %s: nombre del sitio. */
				__( '[%s] Comprobantes electrónicos sin aceptar', 'multisede-pos' ),
				get_bloginfo( 'name' )
			),
			$cuerpo
		);
	}

	/**
	 * Anota el resultado en el pedido, si lo hay.
	 *
	 * @param array  $c    Fila del comprobante.
	 * @param string $nota Nota a añadir.
	 */
	private static function anotar_en_pedido( $c, $nota ) {
		if ( empty( $c['pedido_id'] ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( (int) $c['pedido_id'] );
		if ( $order ) {
			$order->add_order_note( $nota );
			$order->save();
		}
	}

	/**
	 * Programa el barrido cada hora.
	 */
	public static function programar_barrido() {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK_BARRIDO, array(), self::GRUPO ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK_BARRIDO, array(), self::GRUPO );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK_BARRIDO ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_BARRIDO );
		}
	}

	/**
	 * Cancela el trabajo programado (al desactivar el plugin).
	 */
	public static function limpiar() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_BARRIDO, array(), self::GRUPO );
			as_unschedule_all_actions( self::HOOK_EMITIR, null, self::GRUPO );
		}
		$timestamp = wp_next_scheduled( self::HOOK_BARRIDO );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK_BARRIDO );
		}
	}
}
