<?php
/**
 * Caja chica: apertura, movimientos y arqueo por sede y cajero.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestiona las cajas (sesiones) y sus movimientos.
 */
class MSP_Caja {

	const PAGE = 'msp-caja';

	/**
	 * Tope de ventas que se listan en el turno. Si se alcanza, se avisa: los
	 * totales del pie serían parciales y no queremos que mientan en silencio.
	 */
	const MAX_VENTAS_TURNO = 300;

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'registrar_pagina' ) );
		add_action( 'admin_init', array( $this, 'procesar' ) );
		// Permite filtrar pedidos por meta propia con el almacenamiento clásico.
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'filtrar_pedidos_por_meta' ), 10, 2 );
		// Registrar las ventas POS en efectivo como movimiento de caja.
		add_action( 'msp_pos_venta_creada', array( $this, 'registrar_venta_pos' ), 10, 3 );
		// Devolver el efectivo si esa venta se anula.
		add_action( 'msp_pos_venta_anulada', array( $this, 'revertir_venta_pos' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Tablas
	 * ------------------------------------------------------------------- */

	/** @return string */
	public static function tabla_sesiones() {
		global $wpdb;
		return $wpdb->prefix . 'msp_caja_sesiones';
	}

	/** @return string */
	public static function tabla_movimientos() {
		global $wpdb;
		return $wpdb->prefix . 'msp_caja_movimientos';
	}

	/* ---------------------------------------------------------------------
	 * API de datos
	 * ------------------------------------------------------------------- */

	/**
	 * Devuelve la sesión de caja REAL abierta de un cajero en una sede.
	 *
	 * Excluye a propósito las sesiones de práctica del asistente: si no, una
	 * caja de práctica olvidada abierta se tragaría el efectivo de las ventas
	 * reales del POS y bloquearía la apertura de la caja del turno.
	 *
	 * @param int $sede_id   ID de sede.
	 * @param int $cajero_id ID de usuario.
	 * @return object|null
	 */
	public static function sesion_abierta( $sede_id, $cajero_id ) {
		return self::buscar_sesion_abierta( $sede_id, $cajero_id, 0 );
	}

	/**
	 * Devuelve la sesión de PRÁCTICA abierta de un usuario en una sede.
	 *
	 * @param int $sede_id   ID de sede.
	 * @param int $cajero_id ID de usuario.
	 * @return object|null
	 */
	public static function sesion_practica_abierta( $sede_id, $cajero_id ) {
		return self::buscar_sesion_abierta( $sede_id, $cajero_id, 1 );
	}

	/**
	 * Busca una sesión abierta, real o de práctica.
	 *
	 * @param int $sede_id     Sede.
	 * @param int $cajero_id   Usuario.
	 * @param int $es_practica 0 = real, 1 = práctica.
	 * @return object|null
	 */
	private static function buscar_sesion_abierta( $sede_id, $cajero_id, $es_practica ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tabla_sesiones() . "
				 WHERE sede_id = %d AND cajero_id = %d AND estado = 'abierta' AND es_practica = %d
				 ORDER BY id DESC LIMIT 1",
				$sede_id,
				$cajero_id,
				$es_practica
			)
		);
	}

	/**
	 * Abre una caja.
	 *
	 * @param int   $sede_id     Sede.
	 * @param int   $cajero_id   Cajero.
	 * @param float $apertura    Monto de apertura.
	 * @param bool  $es_practica Si es una caja de práctica del asistente.
	 * @return int|false ID de la sesión o false si ya hay una abierta del mismo tipo.
	 */
	public static function abrir( $sede_id, $cajero_id, $apertura, $es_practica = false ) {
		global $wpdb;

		$es_practica = $es_practica ? 1 : 0;

		if ( self::buscar_sesion_abierta( $sede_id, $cajero_id, $es_practica ) ) {
			return false;
		}

		$wpdb->insert(
			self::tabla_sesiones(),
			array(
				'sede_id'        => $sede_id,
				'cajero_id'      => $cajero_id,
				'monto_apertura' => round( (float) $apertura, 2 ),
				'estado'         => 'abierta',
				'es_practica'    => $es_practica,
				'abierta_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%f', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Ventas del POS hechas durante un turno de caja.
	 *
	 * Se buscan por sede + cajero + rango de fechas del turno, en lugar de por
	 * los movimientos de caja: los movimientos solo existen para las ventas en
	 * efectivo, y aquí queremos ver TODAS las del turno (también tarjeta y
	 * Yape/Plin), que es justo lo que explica por qué el cajón no tiene tanto
	 * dinero como se vendió.
	 *
	 * @param object $sesion Sesión de caja.
	 * @return WC_Order[]
	 */
	public static function ventas_de_sesion( $sesion ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		// Los rangos de fecha de wc_get_orders se comparan contra date_created_gmt
		// (UTC), pero abierta_at está en la hora local del sitio. Hay que
		// convertir: si no, en Lima (UTC−5) la ventana se desplaza 5 horas y las
		// ventas recientes no aparecen justo cuando el cajero está cuadrando.
		$desde = (int) get_gmt_from_date( $sesion->abierta_at, 'U' );
		$hasta = (int) get_gmt_from_date(
			$sesion->cerrada_at ? $sesion->cerrada_at : current_time( 'mysql' ),
			'U'
		);

		if ( ! $desde || ! $hasta ) {
			return array();
		}

		$args = array(
			'limit'        => self::MAX_VENTAS_TURNO,
			'orderby'      => 'date',
			'order'        => 'ASC',
			'date_created' => $desde . '...' . $hasta,
		);

		$filtros = array(
			array(
				'key'   => '_msp_sede_id',
				'value' => (int) $sesion->sede_id,
			),
			array(
				'key'   => '_msp_origen',
				'value' => 'pos',
			),
			array(
				'key'   => '_msp_cajero_id',
				'value' => (int) $sesion->cajero_id,
			),
		);

		// HPOS entiende meta_query; el almacenamiento clásico lo DESCARTA en
		// silencio (WC_Data_Store_WP::get_wp_query_args lo salta), y sin filtros
		// la consulta devolvería los pedidos de todas las sedes y cajeros. Por
		// eso ahí se pasa por una clave propia que traduce nuestro filtro.
		if ( self::hpos_activo() ) {
			$args['meta_query'] = $filtros; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		} else {
			$args['msp_meta_query'] = $filtros;
		}

		$pedidos = wc_get_orders( $args );

		return is_array( $pedidos ) ? $pedidos : array();
	}

	/**
	 * ¿WooCommerce está guardando los pedidos en sus propias tablas (HPOS)?
	 *
	 * @return bool
	 */
	public static function hpos_activo() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
			\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Traduce nuestro filtro de meta al almacenamiento clásico de pedidos.
	 *
	 * Es la vía documentada por WooCommerce para filtrar por meta propia cuando
	 * los pedidos viven en la tabla de posts.
	 *
	 * @param array $wp_query_args Argumentos de WP_Query.
	 * @param array $query_vars    Argumentos originales de wc_get_orders.
	 * @return array
	 */
	public function filtrar_pedidos_por_meta( $wp_query_args, $query_vars ) {
		if ( empty( $query_vars['msp_meta_query'] ) || ! is_array( $query_vars['msp_meta_query'] ) ) {
			return $wp_query_args;
		}

		$actual                       = isset( $wp_query_args['meta_query'] ) ? (array) $wp_query_args['meta_query'] : array();
		$wp_query_args['meta_query']  = array_merge( $actual, $query_vars['msp_meta_query'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		return $wp_query_args;
	}

	/**
	 * ¿Ese método de pago mete efectivo en el cajón?
	 *
	 * @param string $metodo Método de pago del POS.
	 * @return bool
	 */
	public static function es_efectivo( $metodo ) {
		return 'efectivo' === $metodo;
	}

	/**
	 * Traduce la diferencia del cierre a lenguaje de tienda.
	 *
	 * En vez de un número con signo ("−S/ 2.00"), que hay que interpretar, se
	 * dice si la caja cuadró, si faltó plata o si sobró.
	 *
	 * @param float $diferencia Contado − esperado.
	 * @return array{texto:string,color:string,cuadra:bool}
	 */
	public static function resultado_cuadre( $diferencia ) {
		$diferencia = (float) $diferencia;

		// Se compara en céntimos para no depender de la precisión de los float.
		if ( 0 === (int) round( $diferencia * 100 ) ) {
			return array(
				'texto'  => esc_html__( 'Cuadró', 'multisede-pos' ),
				'color'  => '#1C8E80',
				'cuadra' => true,
			);
		}

		if ( $diferencia < 0 ) {
			return array(
				'texto'  => sprintf(
					/* translators: %s: importe que falta. */
					esc_html__( 'Faltaron %s', 'multisede-pos' ),
					wc_price( abs( $diferencia ) )
				),
				'color'  => '#b32d2e',
				'cuadra' => false,
			);
		}

		return array(
			'texto'  => sprintf(
				/* translators: %s: importe que sobra. */
				esc_html__( 'Sobraron %s', 'multisede-pos' ),
				wc_price( $diferencia )
			),
			'color'  => '#996800',
			'cuadra' => false,
		);
	}

	/**
	 * Última sesión de práctica de un usuario en una sede, abierta o cerrada.
	 *
	 * @param int $sede_id   Sede.
	 * @param int $cajero_id Usuario.
	 * @return object|null
	 */
	public static function ultima_practica( $sede_id, $cajero_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tabla_sesiones() . '
				 WHERE sede_id = %d AND cajero_id = %d AND es_practica = 1
				 ORDER BY id DESC LIMIT 1',
				$sede_id,
				$cajero_id
			)
		);
	}

	/**
	 * Borra una sesión de práctica y sus movimientos.
	 *
	 * Solo borra sesiones marcadas como práctica y del propio usuario: nunca
	 * puede tocar un arqueo real.
	 *
	 * @param int $sesion_id Sesión.
	 * @param int $cajero_id Usuario que la creó.
	 * @return bool
	 */
	public static function descartar_practica( $sesion_id, $cajero_id ) {
		global $wpdb;

		$sesion = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tabla_sesiones() . '
				 WHERE id = %d AND cajero_id = %d AND es_practica = 1',
				$sesion_id,
				$cajero_id
			)
		);

		if ( ! $sesion ) {
			return false;
		}

		$wpdb->delete( self::tabla_movimientos(), array( 'sesion_id' => $sesion->id ), array( '%d' ) );
		$wpdb->delete( self::tabla_sesiones(), array( 'id' => $sesion->id ), array( '%d' ) );

		return true;
	}

	/**
	 * Registra un movimiento en una sesión.
	 *
	 * @param int      $sesion_id Sesión.
	 * @param string   $tipo      ingreso | egreso | venta.
	 * @param string   $concepto  Descripción.
	 * @param float    $monto     Importe (positivo).
	 * @param int|null $pedido_id Pedido asociado (opcional).
	 * @return void
	 */
	public static function agregar_movimiento( $sesion_id, $tipo, $concepto, $monto, $pedido_id = null ) {
		global $wpdb;
		$wpdb->insert(
			self::tabla_movimientos(),
			array(
				'sesion_id' => $sesion_id,
				'tipo'      => $tipo,
				'concepto'  => $concepto,
				'monto'     => round( (float) $monto, 2 ),
				'pedido_id' => $pedido_id ? (int) $pedido_id : null,
				'creado_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%f', '%d', '%s' )
		);
	}

	/**
	 * Movimientos de una sesión.
	 *
	 * @param int $sesion_id Sesión.
	 * @return array
	 */
	public static function movimientos( $sesion_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tabla_movimientos() . ' WHERE sesion_id = %d ORDER BY id ASC',
				$sesion_id
			)
		);
	}

	/**
	 * Sumas por tipo de una sesión.
	 *
	 * @param int $sesion_id Sesión.
	 * @return array{ingresos:float,egresos:float,ventas:float}
	 */
	public static function totales( $sesion_id ) {
		global $wpdb;
		$filas = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT tipo, COALESCE(SUM(monto),0) AS total
				 FROM ' . self::tabla_movimientos() . '
				 WHERE sesion_id = %d GROUP BY tipo',
				$sesion_id
			),
			OBJECT_K
		);

		return array(
			'ingresos' => isset( $filas['ingreso'] ) ? (float) $filas['ingreso']->total : 0,
			'egresos'  => isset( $filas['egreso'] ) ? (float) $filas['egreso']->total : 0,
			'ventas'   => isset( $filas['venta'] ) ? (float) $filas['venta']->total : 0,
		);
	}

	/**
	 * Efectivo esperado en caja para una sesión.
	 *
	 * Esperado = apertura + ingresos + ventas en efectivo − egresos.
	 *
	 * @param object $sesion Sesión.
	 * @return float
	 */
	public static function esperado( $sesion ) {
		$t = self::totales( $sesion->id );
		return (float) $sesion->monto_apertura + $t['ingresos'] + $t['ventas'] - $t['egresos'];
	}

	/**
	 * Cierra una caja con arqueo.
	 *
	 * @param object $sesion   Sesión.
	 * @param float  $contado  Efectivo contado.
	 * @return void
	 */
	public static function cerrar( $sesion, $contado ) {
		global $wpdb;
		$esperado   = self::esperado( $sesion );
		$contado    = round( (float) $contado, 2 );
		$diferencia = round( $contado - $esperado, 2 );

		$wpdb->update(
			self::tabla_sesiones(),
			array(
				'monto_cierre_esperado' => round( $esperado, 2 ),
				'monto_cierre_contado'  => $contado,
				'diferencia'            => $diferencia,
				'estado'                => 'cerrada',
				'cerrada_at'            => current_time( 'mysql' ),
			),
			array( 'id' => $sesion->id ),
			array( '%f', '%f', '%f', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Registra una venta POS en efectivo como movimiento de caja.
	 *
	 * @param WC_Order $order   Pedido.
	 * @param string   $metodo  Método de pago.
	 * @param int      $sede_id Sede.
	 */
	public function registrar_venta_pos( $order, $metodo, $sede_id ) {
		if ( 'efectivo' !== $metodo ) {
			return;
		}

		$cajero_id = (int) $order->get_meta( '_msp_cajero_id' );
		$sesion    = self::sesion_abierta( $sede_id, $cajero_id );
		if ( ! $sesion ) {
			// No hay caja abierta; la venta queda registrada solo como pedido.
			return;
		}

		self::agregar_movimiento(
			$sesion->id,
			'venta',
			sprintf(
				/* translators: %s: número de pedido. */
				__( 'Venta POS #%s', 'multisede-pos' ),
				$order->get_order_number()
			),
			$order->get_total(),
			$order->get_id()
		);
	}

	/**
	 * Busca un movimiento de un pedido por tipo.
	 *
	 * @param int    $pedido_id Pedido.
	 * @param string $tipo      ingreso | egreso | venta.
	 * @return object|null
	 */
	public static function movimiento_de_pedido( $pedido_id, $tipo ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tabla_movimientos() . '
				 WHERE pedido_id = %d AND tipo = %s ORDER BY id ASC LIMIT 1',
				$pedido_id,
				$tipo
			)
		);
	}

	/**
	 * Devuelve una sesión por su ID.
	 *
	 * @param int $sesion_id Sesión.
	 * @return object|null
	 */
	public static function sesion( $sesion_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::tabla_sesiones() . ' WHERE id = %d', $sesion_id )
		);
	}

	/**
	 * Revierte el efectivo de una venta POS anulada (cancelada o reembolsada).
	 *
	 * El egreso entra en la caja donde se cobró si sigue abierta. Si ese turno
	 * ya se cerró, entra en la caja abierta del mismo cajero en esa sede; si no
	 * hay ninguna, se avisa en el pedido para que se ajuste a mano (no tocamos
	 * un arqueo ya cerrado).
	 *
	 * @param WC_Order $order   Pedido anulado.
	 * @param int      $sede_id Sede.
	 */
	public function revertir_venta_pos( $order, $sede_id ) {
		if ( 'efectivo' !== $order->get_meta( '_msp_pos_metodo' ) ) {
			return;
		}

		$pedido_id = $order->get_id();

		// El efectivo nunca entró a una caja: nada que devolver.
		$venta = self::movimiento_de_pedido( $pedido_id, 'venta' );
		if ( ! $venta ) {
			return;
		}

		// Ya se revirtió antes.
		if ( self::movimiento_de_pedido( $pedido_id, 'egreso' ) ) {
			return;
		}

		$concepto = sprintf(
			/* translators: %s: número de pedido. */
			__( 'Anulación de venta POS #%s', 'multisede-pos' ),
			$order->get_order_number()
		);

		$sesion_venta = self::sesion( $venta->sesion_id );
		$destino      = ( $sesion_venta && 'abierta' === $sesion_venta->estado ) ? $sesion_venta : null;

		if ( ! $destino ) {
			// El turno del cobro ya está cerrado: lo llevamos al turno abierto.
			$destino  = self::sesion_abierta( $sede_id, (int) $order->get_meta( '_msp_cajero_id' ) );
			$concepto = sprintf(
				/* translators: %s: número de pedido. */
				__( 'Anulación de venta POS #%s (cobrada en un turno anterior)', 'multisede-pos' ),
				$order->get_order_number()
			);
		}

		if ( ! $destino ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: importe. */
					__( 'Venta POS anulada, pero no hay caja abierta donde devolver el efectivo (%s). Regístralo como egreso al abrir la próxima caja.', 'multisede-pos' ),
					wp_strip_all_tags( wc_price( $venta->monto ) )
				)
			);
			$order->save();
			return;
		}

		self::agregar_movimiento( $destino->id, 'egreso', $concepto, $venta->monto, $pedido_id );

		$order->add_order_note(
			sprintf(
				/* translators: %s: importe. */
				__( 'Venta POS anulada: %s devueltos como egreso de caja.', 'multisede-pos' ),
				wp_strip_all_tags( wc_price( $venta->monto ) )
			)
		);
		$order->save();
	}

	/* ---------------------------------------------------------------------
	 * Sedes disponibles para el usuario
	 * ------------------------------------------------------------------- */

	/**
	 * Sedes de mostrador donde el usuario puede gestionar caja.
	 *
	 * @return WP_Post[]
	 */
	private function sedes_disponibles() {
		$mostrador = array_filter(
			MSP_Sedes::obtener_sedes_activas(),
			function ( $sede ) {
				return '1' === get_post_meta( $sede->ID, '_msp_vende_mostrador', true );
			}
		);

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
	 * ¿Puede el usuario operar esa sede?
	 *
	 * @param int $sede_id Sede.
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

	/* ---------------------------------------------------------------------
	 * Página de administración
	 * ------------------------------------------------------------------- */

	/**
	 * Registra la página de Caja.
	 */
	public function registrar_pagina() {
		add_menu_page(
			__( 'Caja chica', 'multisede-pos' ),
			__( 'Caja', 'multisede-pos' ),
			'msp_gestionar_caja',
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-money-alt',
			58
		);
	}

	/**
	 * Procesa los formularios de la caja.
	 */
	public function procesar() {
		if ( ! isset( $_POST['msp_caja_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'msp_gestionar_caja' ) ) {
			return;
		}
		check_admin_referer( 'msp_caja', 'msp_caja_nonce' );

		$accion  = sanitize_key( wp_unslash( $_POST['msp_caja_action'] ) );
		$sede_id = isset( $_POST['sede'] ) ? absint( wp_unslash( $_POST['sede'] ) ) : 0;

		if ( ! $sede_id || ! $this->puede_usar_sede( $sede_id ) ) {
			return;
		}

		$cajero_id = get_current_user_id();
		$aviso     = '';

		if ( 'abrir_caja' === $accion ) {
			$apertura = isset( $_POST['monto_apertura'] ) ? (float) wp_unslash( $_POST['monto_apertura'] ) : 0;
			$ok       = self::abrir( $sede_id, $cajero_id, $apertura );
			$aviso    = $ok ? 'abierta' : 'ya_abierta';

		} elseif ( 'mov_caja' === $accion ) {
			$sesion = self::sesion_abierta( $sede_id, $cajero_id );
			if ( $sesion ) {
				$tipo     = isset( $_POST['tipo'] ) && 'egreso' === $_POST['tipo'] ? 'egreso' : 'ingreso';
				$concepto = isset( $_POST['concepto'] ) ? sanitize_text_field( wp_unslash( $_POST['concepto'] ) ) : '';
				$monto    = isset( $_POST['monto'] ) ? (float) wp_unslash( $_POST['monto'] ) : 0;
				if ( $monto > 0 ) {
					self::agregar_movimiento( $sesion->id, $tipo, $concepto, $monto );
					$aviso = 'movimiento';
				}
			}
		} elseif ( 'cerrar_caja' === $accion ) {
			$sesion = self::sesion_abierta( $sede_id, $cajero_id );
			if ( $sesion ) {
				$contado = isset( $_POST['monto_contado'] ) ? (float) wp_unslash( $_POST['monto_contado'] ) : 0;
				self::cerrar( $sesion, $contado );
				$aviso = 'cerrada';
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::PAGE,
					'sede'  => $sede_id,
					'aviso' => $aviso,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renderiza la página de Caja.
	 */
	public function render() {
		$sedes = $this->sedes_disponibles();
		echo '<div class="wrap"><h1>' . esc_html__( 'Caja chica', 'multisede-pos' ) . '</h1>';

		if ( empty( $sedes ) ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'No tienes ninguna sede de mostrador asignada para gestionar caja.', 'multisede-pos' ) .
				'</p></div></div>';
			return;
		}

		$this->avisos();

		// Sede seleccionada.
		$sede_id = isset( $_GET['sede'] ) ? absint( wp_unslash( $_GET['sede'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $sede_id || ! $this->puede_usar_sede( $sede_id ) ) {
			$sede_id = (int) $sedes[0]->ID;
		}

		// Selector de sede.
		echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		echo '<label><strong>' . esc_html__( 'Sede:', 'multisede-pos' ) . '</strong> <select name="sede" onchange="this.form.submit()">';
		foreach ( $sedes as $sede ) {
			echo '<option value="' . esc_attr( $sede->ID ) . '" ' . selected( $sede->ID, $sede_id, false ) . '>' .
				esc_html( $sede->post_title ) . '</option>';
		}
		echo '</select></label></form>';

		$cajero_id = get_current_user_id();
		$sesion    = self::sesion_abierta( $sede_id, $cajero_id );

		if ( $sesion ) {
			$this->vista_caja_abierta( $sesion, $sede_id );
		} else {
			$this->vista_abrir_caja( $sede_id );
		}

		$this->tabla_reportes( $sede_id );
		echo '</div>';
	}

	/**
	 * Avisos según el parámetro de la URL.
	 */
	private function avisos() {
		if ( ! isset( $_GET['aviso'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$aviso   = sanitize_key( wp_unslash( $_GET['aviso'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mensajes = array(
			'abierta'    => array( 'success', __( 'Caja abierta.', 'multisede-pos' ) ),
			'ya_abierta' => array( 'warning', __( 'Ya tienes una caja abierta en esta sede.', 'multisede-pos' ) ),
			'movimiento' => array( 'success', __( 'Movimiento registrado.', 'multisede-pos' ) ),
			'cerrada'    => array( 'success', __( 'Caja cerrada. Revisa el cuadre abajo.', 'multisede-pos' ) ),
		);
		if ( isset( $mensajes[ $aviso ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $mensajes[ $aviso ][0] ),
				esc_html( $mensajes[ $aviso ][1] )
			);
		}
	}

	/**
	 * Formulario para abrir caja.
	 *
	 * @param int $sede_id Sede.
	 */
	private function vista_abrir_caja( $sede_id ) {
		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;max-width:480px">';
		echo '<h2>' . esc_html__( 'Abrir caja', 'multisede-pos' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'msp_caja', 'msp_caja_nonce' );
		echo '<input type="hidden" name="msp_caja_action" value="abrir_caja" />';
		echo '<input type="hidden" name="sede" value="' . esc_attr( $sede_id ) . '" />';
		echo '<p><label>' . esc_html__( 'Monto de apertura', 'multisede-pos' ) . '<br>';
		echo '<input type="number" step="0.01" min="0" name="monto_apertura" value="0" required class="regular-text" /></label></p>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Abrir caja', 'multisede-pos' ) . '</button>';
		echo '</form></div>';
	}

	/**
	 * Vista de una caja abierta: resumen, movimientos y cierre.
	 *
	 * @param object $sesion  Sesión.
	 * @param int    $sede_id Sede.
	 */
	private function vista_caja_abierta( $sesion, $sede_id ) {
		$t        = self::totales( $sesion->id );
		$esperado = self::esperado( $sesion );

		echo '<div style="display:flex;gap:20px;flex-wrap:wrap">';

		// Resumen.
		echo '<div style="flex:1;min-width:300px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px">';
		echo '<h2>' . esc_html__( 'Caja abierta', 'multisede-pos' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		$this->fila_resumen( __( 'Apertura', 'multisede-pos' ), $sesion->monto_apertura );
		$this->fila_resumen( __( 'Ventas en efectivo', 'multisede-pos' ), $t['ventas'] );
		$this->fila_resumen( __( 'Otros ingresos', 'multisede-pos' ), $t['ingresos'] );
		$this->fila_resumen( __( 'Egresos', 'multisede-pos' ), -$t['egresos'] );
		echo '<tr style="font-weight:700;font-size:15px"><td>' . esc_html__( 'Efectivo esperado', 'multisede-pos' ) .
			'</td><td>' . wp_kses_post( wc_price( $esperado ) ) . '</td></tr>';
		echo '</tbody></table>';

		// Cerrar caja y cuadrarla.
		echo '<h3 style="margin-top:20px">' . esc_html__( 'Cerrar caja (cuadre)', 'multisede-pos' ) . '</h3>';
		echo '<form method="post">';
		wp_nonce_field( 'msp_caja', 'msp_caja_nonce' );
		echo '<input type="hidden" name="msp_caja_action" value="cerrar_caja" />';
		echo '<input type="hidden" name="sede" value="' . esc_attr( $sede_id ) . '" />';
		echo '<p><label>' . esc_html__( 'Efectivo contado', 'multisede-pos' ) . '<br>';
		echo '<input type="number" step="0.01" min="0" name="monto_contado" required class="regular-text" /></label></p>';
		echo '<button type="submit" class="button" onclick="return confirm(\'' .
			esc_js( __( '¿Cerrar la caja con el monto indicado?', 'multisede-pos' ) ) . '\')">' .
			esc_html__( 'Cerrar caja', 'multisede-pos' ) . '</button>';
		echo '</form></div>';

		// Movimientos.
		echo '<div style="flex:1;min-width:300px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px">';
		echo '<h2>' . esc_html__( 'Registrar movimiento', 'multisede-pos' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'msp_caja', 'msp_caja_nonce' );
		echo '<input type="hidden" name="msp_caja_action" value="mov_caja" />';
		echo '<input type="hidden" name="sede" value="' . esc_attr( $sede_id ) . '" />';
		echo '<p><select name="tipo"><option value="ingreso">' . esc_html__( 'Ingreso', 'multisede-pos' ) .
			'</option><option value="egreso">' . esc_html__( 'Egreso (gasto)', 'multisede-pos' ) . '</option></select></p>';
		echo '<p><input type="text" name="concepto" placeholder="' . esc_attr__( 'Concepto', 'multisede-pos' ) . '" class="regular-text" /></p>';
		echo '<p><input type="number" step="0.01" min="0" name="monto" placeholder="' . esc_attr__( 'Monto', 'multisede-pos' ) . '" required /></p>';
		echo '<button type="submit" class="button">' . esc_html__( 'Agregar', 'multisede-pos' ) . '</button>';
		echo '</form>';

		// Lista de movimientos.
		$movs = self::movimientos( $sesion->id );
		echo '<h3 style="margin-top:20px">' . esc_html__( 'Movimientos', 'multisede-pos' ) . '</h3>';
		if ( $movs ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Tipo', 'multisede-pos' ) .
				'</th><th>' . esc_html__( 'Concepto', 'multisede-pos' ) . '</th><th>' . esc_html__( 'Monto', 'multisede-pos' ) . '</th></tr></thead><tbody>';
			$etiquetas = array(
				'ingreso' => __( 'Ingreso', 'multisede-pos' ),
				'egreso'  => __( 'Egreso', 'multisede-pos' ),
				'venta'   => __( 'Venta', 'multisede-pos' ),
			);
			foreach ( $movs as $m ) {
				$signo = 'egreso' === $m->tipo ? '-' : '+';
				echo '<tr><td>' . esc_html( isset( $etiquetas[ $m->tipo ] ) ? $etiquetas[ $m->tipo ] : $m->tipo ) .
					'</td><td>' . esc_html( $m->concepto ) . '</td><td>' . esc_html( $signo ) . wp_kses_post( wc_price( $m->monto ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Sin movimientos todavía.', 'multisede-pos' ) . '</p>';
		}
		echo '</div></div>';

		$this->tabla_ventas_turno( $sesion );
	}

	/**
	 * Ventas hechas en este turno: qué se vendió y cómo se pagó.
	 *
	 * No cambia ningún importe de la caja: es un reporte de lectura. Está para
	 * responder la pregunta que todo cajero se hace al cuadrar ("¿y todo lo que
	 * vendí, dónde está?"): lo cobrado con tarjeta o Yape no está en el cajón.
	 *
	 * @param object $sesion Sesión de caja.
	 */
	private function tabla_ventas_turno( $sesion ) {
		$pedidos = self::ventas_de_sesion( $sesion );

		echo '<h2 style="margin-top:30px">' . esc_html__( 'Ventas de este turno', 'multisede-pos' ) . '</h2>';

		if ( empty( $pedidos ) ) {
			echo '<p>' . esc_html__( 'Todavía no has vendido nada en este turno.', 'multisede-pos' ) . '</p>';
			return;
		}

		$etiquetas = array(
			'efectivo'  => __( 'Efectivo', 'multisede-pos' ),
			'tarjeta'   => __( 'Tarjeta', 'multisede-pos' ),
			'yape_plin' => __( 'Yape / Plin', 'multisede-pos' ),
			'otro'      => __( 'Otro', 'multisede-pos' ),
		);

		$total_vendido  = 0.0;
		$total_efectivo = 0.0;
		$truncado       = count( $pedidos ) >= self::MAX_VENTAS_TURNO;
		$puede_ver_pedido = current_user_can( 'edit_shop_orders' );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:70px">' . esc_html__( 'Hora', 'multisede-pos' ) . '</th>';
		echo '<th style="width:90px">' . esc_html__( 'Pedido', 'multisede-pos' ) . '</th>';
		echo '<th>' . esc_html__( 'Qué se vendió', 'multisede-pos' ) . '</th>';
		echo '<th style="width:130px">' . esc_html__( 'Pago', 'multisede-pos' ) . '</th>';
		echo '<th style="width:110px">' . esc_html__( 'Total', 'multisede-pos' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $pedidos as $pedido ) {
			$metodo   = (string) $pedido->get_meta( '_msp_pos_metodo' );
			$efectivo = self::es_efectivo( $metodo );
			$total    = (float) $pedido->get_total();
			$anulado  = in_array( $pedido->get_status(), array( 'cancelled', 'refunded' ), true );

			if ( ! $anulado ) {
				$total_vendido += $total;
				if ( $efectivo ) {
					$total_efectivo += $total;
				}
			}

			// Qué se vendió, en cristiano.
			$lineas = array();
			foreach ( $pedido->get_items() as $item ) {
				$lineas[] = $item->get_quantity() . ' × ' . $item->get_name();
			}

			$fecha = $pedido->get_date_created();

			$reembolsado = (float) $pedido->get_total_refunded();

			echo '<tr' . ( $anulado ? ' style="opacity:.55"' : '' ) . '>';
			echo '<td>' . esc_html( $fecha ? $fecha->date_i18n( 'H:i' ) : '—' ) . '</td>';

			// El cajero no tiene permiso para abrir pedidos: no le damos un
			// enlace que solo le va a dar "acceso denegado".
			echo '<td>';
			if ( $puede_ver_pedido ) {
				echo '<a href="' . esc_url( $pedido->get_edit_order_url() ) . '">#' .
					esc_html( $pedido->get_order_number() ) . '</a>';
			} else {
				echo '#' . esc_html( $pedido->get_order_number() );
			}
			echo '</td>';

			echo '<td>' . esc_html( implode( ', ', $lineas ) );
			if ( $anulado ) {
				echo ' <strong style="color:#b32d2e">(' . esc_html__( 'anulada', 'multisede-pos' ) . ')</strong>';
			} elseif ( $reembolsado > 0 ) {
				echo ' <strong style="color:#996800">(' . sprintf(
					/* translators: %s: importe devuelto. */
					esc_html__( 'devuelto en parte: %s', 'multisede-pos' ),
					wp_kses_post( wc_price( $reembolsado ) )
				) . ')</strong>';
			}
			echo '</td>';

			echo '<td>' . esc_html( isset( $etiquetas[ $metodo ] ) ? $etiquetas[ $metodo ] : $metodo );
			if ( ! $efectivo && ! $anulado ) {
				echo '<br><span style="color:#787c82;font-size:11px">' .
					esc_html__( 'no entra al cajón', 'multisede-pos' ) . '</span>';
			}
			echo '</td>';

			echo '<td>' . wp_kses_post( wc_price( $total ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody><tfoot>';
		echo '<tr><th colspan="4">' . esc_html__( 'Total vendido en el turno', 'multisede-pos' ) .
			'</th><th>' . wp_kses_post( wc_price( $total_vendido ) ) . '</th></tr>';
		echo '<tr><th colspan="4">' . esc_html__( 'De eso, en efectivo (lo que sí está en el cajón)', 'multisede-pos' ) .
			'</th><th>' . wp_kses_post( wc_price( $total_efectivo ) ) . '</th></tr>';
		echo '</tfoot></table>';

		if ( $truncado ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: número máximo de ventas listadas. */
						__( 'Este turno tiene más de %d ventas. Se muestran las primeras, así que los totales de aquí abajo son parciales. El efectivo esperado de la caja sí está completo: no te fíes de esta tabla para cuadrar.', 'multisede-pos' ),
						self::MAX_VENTAS_TURNO
					)
				)
			);
		}

		if ( $total_efectivo < $total_vendido ) {
			echo '<p class="description">' .
				esc_html__( 'La diferencia entre ambos totales se cobró con tarjeta, Yape/Plin u otro medio: ese dinero no está en el cajón y por eso no cuenta en el efectivo esperado.', 'multisede-pos' ) .
				'</p>';
		}
	}

	/**
	 * Fila del resumen.
	 *
	 * @param string $label Etiqueta.
	 * @param float  $monto Monto.
	 */
	private function fila_resumen( $label, $monto ) {
		echo '<tr><td>' . esc_html( $label ) . '</td><td>' . wp_kses_post( wc_price( $monto ) ) . '</td></tr>';
	}

	/**
	 * Tabla de cierres recientes (reporte).
	 *
	 * @param int $sede_id Sede.
	 */
	private function tabla_reportes( $sede_id ) {
		global $wpdb;

		// El historial de cierres es cosa de gerencia, no del cajero.
		if ( ! current_user_can( 'msp_ver_reportes' ) ) {
			return;
		}

		// Las cajas de práctica del asistente no son contabilidad: fuera del reporte.
		$sesiones = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tabla_sesiones() . "
				 WHERE sede_id = %d AND estado = 'cerrada' AND es_practica = 0
				 ORDER BY cerrada_at DESC LIMIT 15",
				$sede_id
			)
		);

		echo '<h2 style="margin-top:30px">' . esc_html__( 'Cierres de caja', 'multisede-pos' ) . '</h2>';
		if ( ! $sesiones ) {
			echo '<p>' . esc_html__( 'Aún no hay cierres de caja en esta sede.', 'multisede-pos' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Cajero', 'multisede-pos' ) . '</th>';
		echo '<th>' . esc_html__( 'Cierre', 'multisede-pos' ) . '</th>';
		echo '<th>' . esc_html__( 'Esperado', 'multisede-pos' ) . '</th>';
		echo '<th>' . esc_html__( 'Contado', 'multisede-pos' ) . '</th>';
		echo '<th>' . esc_html__( 'Resultado', 'multisede-pos' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $sesiones as $s ) {
			$user      = get_userdata( $s->cajero_id );
			$resultado = self::resultado_cuadre( (float) $s->diferencia );
			echo '<tr>';
			echo '<td>' . esc_html( $user ? $user->display_name : '#' . $s->cajero_id ) . '</td>';
			echo '<td>' . esc_html( $s->cerrada_at ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( $s->monto_cierre_esperado ) ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( $s->monto_cierre_contado ) ) . '</td>';
			echo '<td style="color:' . esc_attr( $resultado['color'] ) . ';font-weight:600">' .
				wp_kses_post( $resultado['texto'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
