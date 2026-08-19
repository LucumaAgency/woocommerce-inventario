<?php
/**
 * Bajas de boletas: qué se anula, cuándo se comunica y cómo se vigila el plazo.
 *
 * Fase 4. Es la contraparte de MSP_Cola: aquella decide cuándo se emite, esta
 * decide cuándo se da de baja.
 *
 * Sin este módulo, anular una venta en WooCommerce devuelve el stock y el
 * efectivo, pero **para SUNAT la boleta sigue siendo válida**: saraih quedaría
 * declarando una venta que no ocurrió y pagando su IGV. No es un caso raro —una
 * devolución el mismo día, un cobro equivocado— sino algo que pasa la primera
 * semana.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marca, agrupa y comunica las bajas de comprobantes.
 */
class MSP_Baja {

	/** Acción de fondo que envía un resumen. */
	const HOOK_ENVIAR = 'msp_enviar_resumen';

	/** Acción de fondo que consulta el ticket de un resumen. */
	const HOOK_TICKET = 'msp_consultar_resumen';

	/** Barrido diario que agrupa las bajas pendientes. */
	const HOOK_AGRUPAR = 'msp_agrupar_bajas';

	/** Grupo de Action Scheduler. */
	const GRUPO = 'multisede-pos';

	/**
	 * Espera entre consultas del ticket, en segundos.
	 *
	 * SUNAT suele tardar poco, pero responde 98 ("en proceso") mientras tanto.
	 * Se empieza corto y se alarga: preguntar cada minuto durante horas no
	 * acelera nada.
	 */
	const ESPERAS = array(
		2 * MINUTE_IN_SECONDS,
		5 * MINUTE_IN_SECONDS,
		15 * MINUTE_IN_SECONDS,
		HOUR_IN_SECONDS,
		3 * HOUR_IN_SECONDS,
		6 * HOUR_IN_SECONDS,
		12 * HOUR_IN_SECONDS,
	);

	/**
	 * Engancha hooks.
	 */
	public function init() {
		// Anulación de una venta de mostrador.
		add_action( 'msp_pos_venta_anulada', array( $this, 'al_anular_venta_pos' ), 20, 2 );

		// Anulación de un pedido web ya entregado (y por tanto ya boletado).
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'al_anular_pedido' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'al_anular_pedido' ) );

		// Trabajo de fondo.
		add_action( self::HOOK_ENVIAR, array( __CLASS__, 'enviar' ) );
		add_action( self::HOOK_TICKET, array( __CLASS__, 'consultar' ) );
		add_action( self::HOOK_AGRUPAR, array( __CLASS__, 'agrupar_y_enviar' ) );

		add_action( 'init', array( __CLASS__, 'programar_agrupacion' ) );
	}

	/**
	 * Marca la baja al anularse una venta del POS.
	 *
	 * @param WC_Order $order   Pedido.
	 * @param int      $sede_id Sede.
	 */
	public function al_anular_venta_pos( $order, $sede_id ) {
		unset( $sede_id );
		self::marcar( $order );
	}

	/**
	 * Marca la baja al cancelarse o reembolsarse un pedido.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function al_anular_pedido( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::marcar( $order );
		}
	}

	/**
	 * Marca el comprobante de un pedido como pendiente de baja.
	 *
	 * Tres decisiones viven aquí:
	 *
	 * - **Si la boleta no llegó a ser aceptada, no hay nada que comunicar.**
	 *   Basta con sacarla de la cola: SUNAT nunca supo de ella. Comunicar la baja
	 *   de algo que no existe para SUNAT sería pedirle que anule un documento que
	 *   no tiene.
	 * - **Si ya está de baja, no se repite.** Anular dos veces el mismo pedido
	 *   (cancelado y luego reembolsado, por ejemplo) no debe generar dos bajas.
	 * - **Si venció el plazo de 7 días, se marca aparte.** Ya no se puede
	 *   comunicar la baja, y hace falta una nota de crédito, que es otro
	 *   documento. Se avisa en vez de intentarlo y fallar.
	 *
	 * @param WC_Order $order Pedido anulado.
	 */
	public static function marcar( $order ) {
		$c = MSP_Comprobante::obtener_por_pedido( $order->get_id() );
		if ( ! $c || '' !== $c['baja_estado'] ) {
			return;
		}

		// Todavía no aceptada: se saca de la cola y se acabó.
		if ( 'aceptado' !== $c['estado'] ) {
			MSP_Comprobante::actualizar(
				(int) $c['id'],
				array(
					'baja_estado'     => 'no_aplica',
					'proximo_intento' => null,
					'anulado_at'      => current_time( 'mysql' ),
				)
			);
			$order->add_order_note(
				sprintf(
					/* translators: %s: número del comprobante. */
					__( 'Venta anulada antes de que SUNAT aceptara el comprobante %s: no hay baja que comunicar.', 'multisede-pos' ),
					MSP_Comprobante::numero( $c )
				)
			);
			$order->save();
			return;
		}

		$dias = MSP_Resumen::dias_de_plazo( $c );

		if ( $dias < 0 ) {
			MSP_Comprobante::actualizar(
				(int) $c['id'],
				array(
					'baja_estado' => 'fuera_de_plazo',
					'anulado_at'  => current_time( 'mysql' ),
				)
			);
			$order->add_order_note(
				sprintf(
					/* translators: 1: número, 2: días de plazo. */
					__( 'ATENCIÓN: el comprobante %1$s se emitió hace más de %2$d días, así que su baja ya no se puede comunicar a SUNAT. Hay que emitir una nota de crédito.', 'multisede-pos' ),
					MSP_Comprobante::numero( $c ),
					MSP_Resumen::DIAS_PLAZO
				)
			);
			$order->save();
			return;
		}

		MSP_Comprobante::actualizar(
			(int) $c['id'],
			array(
				'baja_estado' => 'pendiente',
				'anulado_at'  => current_time( 'mysql' ),
			)
		);

		$order->add_order_note(
			sprintf(
				/* translators: 1: número del comprobante, 2: días restantes. */
				__( 'Comprobante %1$s marcado para baja ante SUNAT. Quedan %2$d días de plazo.', 'multisede-pos' ),
				MSP_Comprobante::numero( $c ),
				$dias
			)
		);
		$order->save();

		// No se espera al barrido diario: si el plazo aprieta, cuanto antes.
		self::programar( self::HOOK_AGRUPAR, array(), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Agrupa las bajas pendientes por fecha de emisión y las envía.
	 *
	 * Se agrupan por la fecha de emisión de los comprobantes, no por la fecha en
	 * que se anularon: el resumen diario informa de los comprobantes de un día
	 * concreto, así que dos boletas de días distintos no caben en el mismo.
	 */
	public static function agrupar_y_enviar() {
		global $wpdb;

		if ( ! MSP_Cola::activa() ) {
			return;
		}

		$tabla = MSP_Comprobante::tabla();

		$pendientes = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, emitido_at FROM {$tabla}
				 WHERE baja_estado = 'pendiente'
				   AND entorno = %s
				 ORDER BY emitido_at ASC
				 LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				MSP_Comprobante::entorno_actual()
			),
			ARRAY_A
		);

		if ( ! $pendientes ) {
			return;
		}

		// Agrupar por día de emisión.
		$por_fecha = array();
		foreach ( $pendientes as $p ) {
			$fecha                 = gmdate( 'Y-m-d', strtotime( $p['emitido_at'] ) );
			$por_fecha[ $fecha ][] = (int) $p['id'];
		}

		foreach ( $por_fecha as $fecha => $ids ) {
			$resumen = MSP_Resumen::crear( $fecha );
			if ( is_wp_error( $resumen ) ) {
				continue;
			}

			// Se enganchan los comprobantes al resumen y se marcan como enviados
			// ANTES del envío: si el proceso muere a mitad, el barrido siguiente
			// no vuelve a meterlos en otro resumen distinto.
			foreach ( $ids as $id ) {
				MSP_Comprobante::actualizar(
					$id,
					array(
						'baja_estado' => 'enviada',
						'resumen_id'  => (int) $resumen['id'],
					)
				);
			}

			self::programar( self::HOOK_ENVIAR, array( (int) $resumen['id'] ), 0 );
		}
	}

	/**
	 * Envía un resumen y programa la consulta de su ticket.
	 *
	 * @param int $resumen_id ID del resumen.
	 */
	public static function enviar( $resumen_id ) {
		$resumen_id = (int) $resumen_id;
		$r          = MSP_Resumen::obtener( $resumen_id );

		if ( ! $r || $r['entorno'] !== MSP_Comprobante::entorno_actual() ) {
			return;
		}
		if ( in_array( $r['estado'], array( 'aceptado', 'rechazado' ), true ) ) {
			return;
		}

		$intentos = (int) $r['intentos'] + 1;
		MSP_Resumen::actualizar( $resumen_id, array( 'intentos' => $intentos ) );

		$res = MSP_Emisor::enviar_resumen( $resumen_id );

		if ( is_wp_error( $res ) ) {
			if ( isset( self::ESPERAS[ $intentos - 1 ] ) ) {
				self::programar_con_fecha( self::HOOK_ENVIAR, $resumen_id, self::ESPERAS[ $intentos - 1 ] );
			} else {
				MSP_Resumen::actualizar( $resumen_id, array( 'proximo_intento' => null ) );
			}
			return;
		}

		// Enviado: ahora hay que preguntar por el ticket.
		self::programar_con_fecha( self::HOOK_TICKET, $resumen_id, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Consulta el ticket de un resumen ya enviado.
	 *
	 * @param int $resumen_id ID del resumen.
	 */
	public static function consultar( $resumen_id ) {
		$resumen_id = (int) $resumen_id;
		$r          = MSP_Resumen::obtener( $resumen_id );

		if ( ! $r || $r['entorno'] !== MSP_Comprobante::entorno_actual() ) {
			return;
		}
		if ( 'aceptado' === $r['estado'] || 'rechazado' === $r['estado'] ) {
			return;
		}

		$intentos = (int) $r['intentos'] + 1;
		MSP_Resumen::actualizar( $resumen_id, array( 'intentos' => $intentos ) );

		$res = MSP_Emisor::consultar_ticket( $resumen_id );

		if ( is_wp_error( $res ) ) {
			// "En proceso" y los fallos de red se reintentan; el rechazo no.
			if ( 'msp_resumen_rechazado' === $res->get_error_code() ) {
				self::cerrar_comprobantes( $resumen_id, 'rechazada' );
				return;
			}
			if ( isset( self::ESPERAS[ $intentos - 1 ] ) ) {
				self::programar_con_fecha( self::HOOK_TICKET, $resumen_id, self::ESPERAS[ $intentos - 1 ] );
			} else {
				MSP_Resumen::actualizar( $resumen_id, array( 'proximo_intento' => null ) );
			}
			return;
		}

		self::cerrar_comprobantes( $resumen_id, 'anulado' );
	}

	/**
	 * Marca los comprobantes de un resumen con el resultado, y lo anota.
	 *
	 * @param int    $resumen_id ID del resumen.
	 * @param string $estado     'anulado' o 'rechazada'.
	 */
	private static function cerrar_comprobantes( $resumen_id, $estado ) {
		$r = MSP_Resumen::obtener( $resumen_id );

		foreach ( MSP_Resumen::comprobantes( $resumen_id ) as $c ) {
			MSP_Comprobante::actualizar( (int) $c['id'], array( 'baja_estado' => $estado ) );

			if ( empty( $c['pedido_id'] ) || ! function_exists( 'wc_get_order' ) ) {
				continue;
			}
			$order = wc_get_order( (int) $c['pedido_id'] );
			if ( ! $order ) {
				continue;
			}

			$order->add_order_note(
				'anulado' === $estado
					? sprintf(
						/* translators: 1: número del comprobante, 2: identificador del resumen. */
						__( 'SUNAT aceptó la baja del comprobante %1$s (resumen %2$s).', 'multisede-pos' ),
						MSP_Comprobante::numero( $c ),
						$r['identificador']
					)
					: sprintf(
						/* translators: 1: número, 2: resumen, 3: motivo. */
						__( 'SUNAT RECHAZÓ la baja del comprobante %1$s (resumen %2$s): %3$s. Hay que revisarlo a mano.', 'multisede-pos' ),
						MSP_Comprobante::numero( $c ),
						$r['identificador'],
						$r['ultimo_error']
					)
			);
			$order->save();
		}
	}

	/**
	 * Programa una acción de fondo.
	 *
	 * @param string $hook   Hook.
	 * @param array  $args   Argumentos.
	 * @param int    $espera Segundos.
	 */
	private static function programar( $hook, $args, $espera ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::GRUPO ) ) {
				return;
			}
			as_schedule_single_action( time() + $espera, $hook, $args, self::GRUPO );
			return;
		}
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + $espera, $hook, $args );
		}
	}

	/**
	 * Programa una acción sobre un resumen y deja la fecha a la vista.
	 *
	 * @param string $hook       Hook.
	 * @param int    $resumen_id ID del resumen.
	 * @param int    $espera     Segundos.
	 */
	private static function programar_con_fecha( $hook, $resumen_id, $espera ) {
		$local = time() + $espera + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		MSP_Resumen::actualizar( $resumen_id, array( 'proximo_intento' => gmdate( 'Y-m-d H:i:s', $local ) ) );
		self::programar( $hook, array( (int) $resumen_id ), $espera );
	}

	/**
	 * Programa el barrido diario de agrupación.
	 */
	public static function programar_agrupacion() {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK_AGRUPAR, array(), self::GRUPO ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, self::HOOK_AGRUPAR, array(), self::GRUPO );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_AGRUPAR ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::HOOK_AGRUPAR );
		}
	}

	/**
	 * Cancela el trabajo programado.
	 */
	public static function limpiar() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_AGRUPAR, array(), self::GRUPO );
			as_unschedule_all_actions( self::HOOK_ENVIAR, null, self::GRUPO );
			as_unschedule_all_actions( self::HOOK_TICKET, null, self::GRUPO );
		}
		$t = wp_next_scheduled( self::HOOK_AGRUPAR );
		if ( $t ) {
			wp_unschedule_event( $t, self::HOOK_AGRUPAR );
		}
	}
}
