<?php
/**
 * Inventario multi-sede: stock por sede y sincronización con WooCommerce.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestiona el stock por sede sobre la tabla wp_msp_stock.
 *
 * El stock global de WooCommerce (_stock) se mantiene como espejo =
 * suma del stock disponible de todas las sedes.
 */
class MSP_Stock {

	/**
	 * Nombre completo de la tabla de stock.
	 *
	 * @return string
	 */
	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'msp_stock';
	}

	/**
	 * Engancha los hooks del módulo.
	 */
	public function init() {
		// UI en la pestaña Inventario de la ficha de producto.
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'campos_producto' ) );
		// Guardado (prioridad alta para sobreescribir el _stock que pone Woo).
		add_action( 'woocommerce_process_product_meta', array( $this, 'guardar_producto' ), 99 );
		// UI y guardado del stock por sede de cada variación.
		add_action( 'woocommerce_variation_options_inventory', array( $this, 'campos_variacion' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'guardar_variacion' ), 99, 2 );
		// Los pedidos con sede los gestiona el plugin (reserva/recojo/POS),
		// así que desactivamos la reducción automática de stock de Woo para ellos.
		add_filter( 'woocommerce_can_reduce_order_stock', array( $this, 'evitar_reduccion_woo' ), 10, 2 );

		// Red de seguridad: suelta sola las reservas que perdieron su pedido.
		add_action( 'init', array( __CLASS__, 'programar_limpieza' ) );
		add_action( 'msp_limpiar_reservas', array( __CLASS__, 'limpiar_reservas_huerfanas' ) );
		// Columna de stock por sede en el listado de productos.
		add_filter( 'manage_edit-product_columns', array( $this, 'columna_listado' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'columna_contenido' ), 20, 2 );
	}

	/* ---------------------------------------------------------------------
	 * API de datos
	 * ------------------------------------------------------------------- */

	/**
	 * Devuelve el stock de un producto en una sede.
	 *
	 * @param int $producto_id ID de producto/variación.
	 * @param int $sede_id     ID de sede.
	 * @return int
	 */
	public static function get( $producto_id, $sede_id ) {
		global $wpdb;
		$valor = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT stock FROM ' . self::tabla() . ' WHERE producto_id = %d AND sede_id = %d',
				$producto_id,
				$sede_id
			)
		);
		return null === $valor ? 0 : (int) $valor;
	}

	/**
	 * Fija (upsert) el stock absoluto de un producto en una sede.
	 *
	 * @param int $producto_id ID de producto.
	 * @param int $sede_id     ID de sede.
	 * @param int $stock       Nuevo stock.
	 * @return void
	 */
	public static function set( $producto_id, $sede_id, $stock ) {
		global $wpdb;
		$stock = max( 0, (int) $stock );
		$ahora = current_time( 'mysql' );

		$existe = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::tabla() . ' WHERE producto_id = %d AND sede_id = %d',
				$producto_id,
				$sede_id
			)
		);

		if ( $existe ) {
			$wpdb->update(
				self::tabla(),
				array(
					'stock'      => $stock,
					'updated_at' => $ahora,
				),
				array(
					'producto_id' => $producto_id,
					'sede_id'     => $sede_id,
				),
				array( '%d', '%s' ),
				array( '%d', '%d' )
			);
		} else {
			$wpdb->insert(
				self::tabla(),
				array(
					'producto_id' => $producto_id,
					'sede_id'     => $sede_id,
					'stock'       => $stock,
					'updated_at'  => $ahora,
				),
				array( '%d', '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Ajusta el stock de una sede en un delta (positivo o negativo).
	 *
	 * Usa una operación atómica para evitar condiciones de carrera.
	 *
	 * @param int $producto_id ID de producto.
	 * @param int $sede_id     ID de sede.
	 * @param int $delta       Cantidad a sumar/restar.
	 * @return void
	 */
	public static function ajustar( $producto_id, $sede_id, $delta ) {
		global $wpdb;
		$delta = (int) $delta;
		$ahora = current_time( 'mysql' );

		// Asegura que exista la fila.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::tabla() . ' (producto_id, sede_id, stock, stock_reservado, updated_at)
				 VALUES (%d, %d, 0, 0, %s)
				 ON DUPLICATE KEY UPDATE
				 stock = GREATEST(0, stock + (%d)), updated_at = VALUES(updated_at)',
				$producto_id,
				$sede_id,
				$ahora,
				$delta
			)
		);
	}

	/**
	 * Devuelve el stock por sede de un producto.
	 *
	 * @param int $producto_id ID de producto.
	 * @return array<int,array{stock:int,reservado:int}> Indexado por sede_id.
	 */
	public static function por_sede( $producto_id ) {
		global $wpdb;
		$filas = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT sede_id, stock, stock_reservado FROM ' . self::tabla() . ' WHERE producto_id = %d',
				$producto_id
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $filas as $f ) {
			$out[ (int) $f['sede_id'] ] = array(
				'stock'     => (int) $f['stock'],
				'reservado' => (int) $f['stock_reservado'],
			);
		}
		return $out;
	}

	/**
	 * Stock disponible de un producto en una sede (físico − reservado).
	 *
	 * @param int $producto_id ID de producto.
	 * @param int $sede_id     ID de sede.
	 * @return int
	 */
	public static function disponible_sede( $producto_id, $sede_id ) {
		global $wpdb;
		$fila = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT stock, stock_reservado FROM ' . self::tabla() . ' WHERE producto_id = %d AND sede_id = %d',
				$producto_id,
				$sede_id
			)
		);
		if ( ! $fila ) {
			return 0;
		}
		return max( 0, (int) $fila->stock - (int) $fila->stock_reservado );
	}

	/**
	 * Disponible de un producto en una sede, contando variaciones.
	 *
	 * Un producto variable no tiene stock propio: su disponible es la suma
	 * del disponible de sus variaciones.
	 *
	 * @param WC_Product $product Producto.
	 * @param int        $sede_id ID de sede.
	 * @return int
	 */
	public static function disponible_producto( $product, $sede_id ) {
		if ( ! $product instanceof WC_Product ) {
			return 0;
		}

		if ( $product->is_type( 'variable' ) ) {
			$total = 0;
			foreach ( $product->get_children() as $variacion_id ) {
				$total += self::disponible_sede( $variacion_id, $sede_id );
			}
			return $total;
		}

		return self::disponible_sede( $product->get_id(), $sede_id );
	}

	/**
	 * Descuenta stock de una sede solo si hay disponible suficiente.
	 *
	 * La condición y el descuento ocurren en la misma sentencia SQL, así que
	 * dos cajeros vendiendo a la vez no pueden sobrevender: el segundo no
	 * afecta ninguna fila y recibe false.
	 *
	 * @param int $producto_id ID de producto/variación.
	 * @param int $sede_id     ID de sede.
	 * @param int $cantidad    Unidades a descontar (positivas).
	 * @return bool True si se descontó; false si no había disponible.
	 */
	public static function descontar_si_hay( $producto_id, $sede_id, $cantidad ) {
		global $wpdb;
		$cantidad = max( 0, (int) $cantidad );
		if ( ! $cantidad ) {
			return true;
		}

		$filas = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::tabla() . '
				 SET stock = stock - %d, updated_at = %s
				 WHERE producto_id = %d AND sede_id = %d AND (stock - stock_reservado) >= %d',
				$cantidad,
				current_time( 'mysql' ),
				$producto_id,
				$sede_id,
				$cantidad
			)
		);

		return $filas > 0;
	}

	/**
	 * Suma del stock físico de todas las sedes de un producto.
	 *
	 * @param int $producto_id ID de producto.
	 * @return int
	 */
	public static function total( $producto_id ) {
		global $wpdb;
		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(stock), 0) FROM ' . self::tabla() . ' WHERE producto_id = %d',
				$producto_id
			)
		);
		return (int) $total;
	}

	/**
	 * Suma de las unidades reservadas (pedidos pendientes de recojo).
	 *
	 * @param int $producto_id ID de producto.
	 * @return int
	 */
	public static function total_reservado( $producto_id ) {
		global $wpdb;
		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(stock_reservado), 0) FROM ' . self::tabla() . ' WHERE producto_id = %d',
				$producto_id
			)
		);
		return (int) $total;
	}

	/**
	 * Reserva unidades en una sede (pedido pagado, pendiente de recojo).
	 *
	 * @param int $producto_id ID de producto.
	 * @param int $sede_id     ID de sede.
	 * @param int $cantidad    Unidades a reservar.
	 * @return void
	 */
	public static function reservar( $producto_id, $sede_id, $cantidad ) {
		global $wpdb;
		$cantidad = max( 0, (int) $cantidad );
		$ahora    = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::tabla() . ' (producto_id, sede_id, stock, stock_reservado, updated_at)
				 VALUES (%d, %d, 0, %d, %s)
				 ON DUPLICATE KEY UPDATE
				 stock_reservado = stock_reservado + %d, updated_at = VALUES(updated_at)',
				$producto_id,
				$sede_id,
				$cantidad,
				$ahora,
				$cantidad
			)
		);
	}

	/**
	 * Reserva unidades SOLO si hay disponibles. Atómico.
	 *
	 * Es a la reserva web lo que `descontar_si_hay()` es a la venta de
	 * mostrador. La versión incondicional (`reservar()`) permitía que dos
	 * compras simultáneas de la última unidad reservaran las dos: el
	 * `stock_reservado` acababa por encima del stock físico y la tienda quedaba
	 * comprometida a entregar algo que no tiene.
	 *
	 * La condición viaja dentro del propio UPDATE, no en un SELECT previo: entre
	 * un SELECT y un UPDATE cabe otra compra.
	 *
	 * @param int $producto_id ID de producto o variación.
	 * @param int $sede_id     ID de sede.
	 * @param int $cantidad    Unidades.
	 * @return bool True si se reservó.
	 */
	public static function reservar_si_hay( $producto_id, $sede_id, $cantidad ) {
		global $wpdb;
		$cantidad = max( 0, (int) $cantidad );
		if ( ! $cantidad ) {
			return true;
		}

		$filas = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::tabla() . '
				 SET stock_reservado = stock_reservado + %d, updated_at = %s
				 WHERE producto_id = %d AND sede_id = %d AND (stock - stock_reservado) >= %d',
				$cantidad,
				current_time( 'mysql' ),
				$producto_id,
				$sede_id,
				$cantidad
			)
		);

		return $filas > 0;
	}

	/**
	 * Reservas de una sede que ya no tienen pedido vivo detrás.
	 *
	 * Una reserva se queda huérfana cuando su pedido desaparece sin pasar por el
	 * camino previsto: se borra, se vacía la papelera, o se toca la base de datos
	 * por fuera. El efecto es que el stock queda inmovilizado **para siempre**,
	 * porque `disponible = stock − reservado` y ese reservado ya no baja nunca.
	 * Subir existencias no ayuda.
	 *
	 * Se compara lo reservado en la tabla contra la suma de lo que reclaman los
	 * pedidos vivos de esa sede. La diferencia es lo que sobra.
	 *
	 * @param int $sede_id Sede.
	 * @return array Mapa producto_id => unidades huérfanas (solo los que sobran).
	 */
	public static function reservas_huerfanas( $sede_id, $antiguedad_minutos = 0 ) {
		global $wpdb;

		$sede_id = (int) $sede_id;

		// El filtro de antigüedad existe para la limpieza automática: una reserva
		// recién hecha puede parecer huérfana durante unos segundos si su pedido
		// todavía se está guardando. Para el aviso en pantalla no hace falta,
		// porque ahí lo mira una persona.
		$condicion = '';
		$params    = array( $sede_id );
		if ( $antiguedad_minutos > 0 ) {
			$condicion = ' AND updated_at <= %s';
			$params[]  = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( (int) $antiguedad_minutos * MINUTE_IN_SECONDS ) );
		}

		$filas = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT producto_id, stock_reservado FROM ' . self::tabla() . '
				 WHERE sede_id = %d AND stock_reservado > 0' . $condicion, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			),
			ARRAY_A
		);

		if ( ! $filas || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		// Lo que reclaman los pedidos que todavía están vivos y sin recoger.
		$legitimo = array();
		$pedidos  = wc_get_orders(
			array(
				'limit'  => 500,
				'status' => array( 'pending', 'processing', 'on-hold' ),
			)
		);

		foreach ( $pedidos as $order ) {
			if ( (int) $order->get_meta( '_msp_sede_id' ) !== $sede_id ) {
				continue;
			}
			if ( 'reservado' !== $order->get_meta( '_msp_reserva_estado' ) ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				$product = $item->get_product();
				if ( ! $product ) {
					continue;
				}
				$pid              = $product->get_id();
				$legitimo[ $pid ] = ( isset( $legitimo[ $pid ] ) ? $legitimo[ $pid ] : 0 ) + (int) $item->get_quantity();
			}
		}

		$huerfanas = array();
		foreach ( $filas as $fila ) {
			$pid    = (int) $fila['producto_id'];
			$sobra  = (int) $fila['stock_reservado'] - ( isset( $legitimo[ $pid ] ) ? $legitimo[ $pid ] : 0 );
			if ( $sobra > 0 ) {
				$huerfanas[ $pid ] = $sobra;
			}
		}

		return $huerfanas;
	}

	/**
	 * Productos cuyo stock de WooCommerce no cuadra con la suma por sede.
	 *
	 * El stock que ve la tienda web es un **espejo**: `sincronizar_woo()` lo
	 * reescribe con el disponible de la sede activa cada vez que el plugin toca
	 * algo. Pero si alguien cambia el stock desde la ficha del producto, o un
	 * pedido sin sede lo descuenta por su cuenta, el espejo deja de coincidir y
	 * **nada lo detecta**: el plugin dice que hay una unidad y Woo bloquea la
	 * compra diciendo que no hay ninguna, con un mensaje que no es del plugin y
	 * no explica nada.
	 *
	 * Esto lo detecta para poder avisar. Se compara contra el **total** de todas
	 * las sedes, que es lo que Woo debería reflejar cuando el cliente no ha
	 * elegido tienda.
	 *
	 * @param int $limite Máximo de productos a revisar.
	 * @return array Lista de {producto_id, nombre, woo, sedes}.
	 */
	public static function divergencias_con_woo( $limite = 200 ) {
		global $wpdb;

		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT producto_id FROM ' . self::tabla() . ' LIMIT %d',
				(int) $limite
			)
		);

		$fuera = array();

		foreach ( $ids as $producto_id ) {
			$product = wc_get_product( (int) $producto_id );
			if ( ! $product || ! $product->get_manage_stock() ) {
				continue;
			}

			$en_woo   = (int) $product->get_stock_quantity();
			$en_sedes = (int) self::total( (int) $producto_id ) - (int) self::total_reservado( (int) $producto_id );

			if ( $en_woo !== $en_sedes ) {
				$fuera[] = array(
					'producto_id' => (int) $producto_id,
					'nombre'      => $product->get_name(),
					'woo'         => $en_woo,
					'sedes'       => $en_sedes,
				);
			}
		}

		return $fuera;
	}

	/**
	 * Suelta sola las reservas que se quedaron sin pedido.
	 *
	 * Es la red de seguridad de los hooks de borrado. Esos hooks cubren el caso
	 * normal, pero dependen de acertar con el nombre del hook en cada
	 * almacenamiento de pedidos (clásico y HPOS usan hooks distintos), y de que
	 * WooCommerce no los cambie. Esto no depende de nada de eso: mira el estado
	 * real y corrige lo que sobra.
	 *
	 * Solo toca reservas de **más de 30 minutos**, para no pelearse con un
	 * checkout que se esté completando justo ahora.
	 *
	 * @return int Unidades liberadas.
	 */
	public static function limpiar_reservas_huerfanas() {
		if ( ! class_exists( 'MSP_Sedes' ) ) {
			return 0;
		}

		$liberadas = 0;

		foreach ( MSP_Sedes::obtener_sedes_activas() as $sede ) {
			foreach ( self::reservas_huerfanas( $sede->ID, 30 ) as $producto_id => $unidades ) {
				self::liberar_reserva( $producto_id, (int) $sede->ID, $unidades );
				self::sincronizar_woo( $producto_id );
				$liberadas += $unidades;
			}
		}

		if ( $liberadas ) {
			// Queda constancia: stock que se libera solo es algo que alguien
			// debería poder explicar después.
			update_option(
				'msp_ultima_limpieza_reservas',
				array(
					'fecha'     => current_time( 'mysql' ),
					'liberadas' => $liberadas,
				),
				false
			);
		}

		return $liberadas;
	}

	/**
	 * Programa la limpieza automática de reservas huérfanas.
	 */
	public static function programar_limpieza() {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( 'msp_limpiar_reservas', array(), 'multisede-pos' ) ) {
				// Cada hora, no cada seis: en HPOS un borrado forzado destruye los
				// datos del pedido antes de que ningún hook pueda leerlos, así que
				// para ese caso esta limpieza es la ÚNICA vía. Una hora de stock
				// bloqueado es asumible; seis, en temporada, no.
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'msp_limpiar_reservas', array(), 'multisede-pos' );
			}
			return;
		}
		if ( ! wp_next_scheduled( 'msp_limpiar_reservas' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'msp_limpiar_reservas' );
		}
	}

	/**
	 * Libera una reserva sin descontar stock (cancelación antes del recojo).
	 *
	 * @param int $producto_id ID de producto.
	 * @param int $sede_id     ID de sede.
	 * @param int $cantidad    Unidades a liberar.
	 * @return void
	 */
	public static function liberar_reserva( $producto_id, $sede_id, $cantidad ) {
		global $wpdb;
		$cantidad = max( 0, (int) $cantidad );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::tabla() . '
				 SET stock_reservado = GREATEST(0, stock_reservado - %d), updated_at = %s
				 WHERE producto_id = %d AND sede_id = %d',
				$cantidad,
				current_time( 'mysql' ),
				$producto_id,
				$sede_id
			)
		);
	}

	/**
	 * Confirma una reserva al recoger: descuenta stock físico y la reserva.
	 *
	 * @param int $producto_id ID de producto.
	 * @param int $sede_id     ID de sede.
	 * @param int $cantidad    Unidades recogidas.
	 * @return void
	 */
	public static function confirmar_reserva( $producto_id, $sede_id, $cantidad ) {
		global $wpdb;
		$cantidad = max( 0, (int) $cantidad );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::tabla() . '
				 SET stock = GREATEST(0, stock - %d),
				     stock_reservado = GREATEST(0, stock_reservado - %d),
				     updated_at = %s
				 WHERE producto_id = %d AND sede_id = %d',
				$cantidad,
				$cantidad,
				current_time( 'mysql' ),
				$producto_id,
				$sede_id
			)
		);
	}

	/**
	 * Sincroniza el stock disponible de WooCommerce.
	 *
	 * Disponible = suma de stock físico − unidades reservadas.
	 *
	 * @param int $producto_id ID de producto.
	 * @return void
	 */
	public static function sincronizar_woo( $producto_id ) {
		$disponible = max( 0, self::total( $producto_id ) - self::total_reservado( $producto_id ) );
		$product    = wc_get_product( $producto_id );

		if ( ! $product ) {
			return;
		}

		// Activamos la gestión de stock y reflejamos el disponible.
		//
		// Ojo: no vale escribir '_manage_stock' con update_post_meta y llamar
		// después a wc_update_product_stock(). Esa función empieza con
		// `if ( ! is_null( $qty ) && $product->managing_stock() )`, y el objeto
		// $product ya está cargado en memoria sin esa marca: en un producto
		// nuevo la escritura se descarta EN SILENCIO. El resultado era
		// `_manage_stock = yes` con `_stock = NULL`, y como WooCommerce compara
		// `get_stock_quantity() < requerido`, NULL siempre pierde: el producto
		// no se podía vender por la web nunca más.
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $disponible );
		$product->set_stock_status( $disponible > 0 ? 'instock' : 'outofstock' );
		$product->save();
	}

	/**
	 * Evita la reducción automática de stock de Woo cuando manda el plugin.
	 *
	 * Un pedido **con sede** lo gestiona el plugin: reserva al pagar y descuento
	 * al recoger. Woo no debe tocarlo.
	 *
	 * Un pedido **sin sede** es un caso que no debería existir mientras el sitio
	 * venda solo con recojo en tienda, y **es el que descuadraba los dos
	 * inventarios en silencio**: el plugin no lo reservaba —no sabe a qué sede—
	 * pero Woo sí le descontaba el stock global. La tabla por sede decía una cosa
	 * y WooCommerce otra, sin que nada lo detectara, y la divergencia sobrevivía
	 * hasta que alguien tocara el inventario a mano.
	 *
	 * Ahora Woo tampoco descuenta ahí, y en su lugar **queda constancia en el
	 * pedido**: un aviso visible es mejor que un descuadre callado. Si el pedido
	 * es legítimo (venta sin sede, futuro delivery), el stock se ajusta a mano;
	 * si es un error de configuración —como el checkout de bloques que no pintaba
	 * el campo de sede—, la nota dice exactamente qué mirar.
	 *
	 * @param bool     $reduce Si Woo debe reducir.
	 * @param WC_Order $order  Pedido.
	 * @return bool
	 */
	public function evitar_reduccion_woo( $reduce, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $reduce;
		}

		if ( $order->get_meta( '_msp_sede_id' ) ) {
			return false;
		}

		// Pedidos ajenos al plugin (suscripciones, pedidos manuales de otro
		// flujo) no deberían verse afectados: solo se interviene si el sitio
		// tiene sedes configuradas, que es cuando el modelo por sede aplica.
		if ( ! MSP_Sedes::obtener_sedes_activas() ) {
			return $reduce;
		}

		self::avisar_pedido_sin_sede( $order );

		return false;
	}

	/**
	 * Anota en el pedido que llegó sin sede, una sola vez.
	 *
	 * @param WC_Order $order Pedido.
	 */
	private static function avisar_pedido_sin_sede( $order ) {
		if ( $order->get_meta( '_msp_aviso_sin_sede' ) ) {
			return;
		}

		$order->add_order_note(
			__( 'ATENCIÓN: este pedido llegó sin tienda asignada, así que su stock no se descontó de ninguna sede. Ajusta el inventario a mano si la mercadería salió. Si se repite, revisa que la página de pago use el checkout clásico: el de bloques no pinta el campo de tienda.', 'multisede-pos' )
		);
		$order->update_meta_data( '_msp_aviso_sin_sede', '1' );
		$order->save();
	}

	/* ---------------------------------------------------------------------
	 * UI en la ficha de producto
	 * ------------------------------------------------------------------- */

	/**
	 * Pinta los campos de stock por sede en la pestaña Inventario.
	 */
	public function campos_producto() {
		global $post;

		$sedes = MSP_Sedes::obtener_sedes_activas();

		// En productos variables el stock vive en cada variación, no en el padre.
		echo '<div class="options_group msp-stock-sedes show_if_simple">';
		echo '<p class="form-field"><strong>' . esc_html__( 'Stock por sede (Multisede POS)', 'multisede-pos' ) . '</strong></p>';

		if ( empty( $sedes ) ) {
			echo '<p class="form-field" style="color:#b32d2e">' .
				esc_html__( 'Aún no hay sedes activas. Crea sedes en el menú "Sedes".', 'multisede-pos' ) .
				'</p>';
			echo '</div>';
			return;
		}

		$por_sede = self::por_sede( $post->ID );

		foreach ( $sedes as $sede ) {
			$stock_actual = isset( $por_sede[ $sede->ID ] ) ? $por_sede[ $sede->ID ]['stock'] : 0;
			$reservado    = isset( $por_sede[ $sede->ID ] ) ? $por_sede[ $sede->ID ]['reservado'] : 0;

			woocommerce_wp_text_input(
				array(
					'id'                => 'msp_stock_' . $sede->ID,
					'name'              => 'msp_stock[' . $sede->ID . ']',
					'label'             => $sede->post_title,
					'value'             => $stock_actual,
					'type'              => 'number',
					'desc_tip'          => true,
					'description'       => $reservado > 0
						/* translators: %d: unidades reservadas. */
						? sprintf( esc_html__( 'Reservado por pedidos pendientes de recojo: %d', 'multisede-pos' ), $reservado )
						: esc_html__( 'Existencias en esta sede.', 'multisede-pos' ),
					'custom_attributes' => array(
						'step' => '1',
						'min'  => '0',
					),
				)
			);
		}

		echo '<p class="form-field" style="color:#787c82">' .
			esc_html__( 'El stock total de WooCommerce se calcula como la suma de todas las sedes.', 'multisede-pos' ) .
			'</p>';
		echo '</div>';
	}

	/**
	 * Guarda el stock por sede al guardar el producto.
	 *
	 * @param int $producto_id ID de producto.
	 */
	public function guardar_producto( $producto_id ) {
		// Nonce de WooCommerce para el guardado de producto.
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['woocommerce_meta_nonce'] ), 'woocommerce_save_data' ) ) {
			return;
		}

		if ( ! isset( $_POST['msp_stock'] ) || ! is_array( $_POST['msp_stock'] ) ) {
			return;
		}

		$valores = wp_unslash( $_POST['msp_stock'] ); // phpcs:ignore WordPress.Security.ValidatedSanitized

		foreach ( $valores as $sede_id => $stock ) {
			$sede_id = absint( $sede_id );
			if ( ! $sede_id ) {
				continue;
			}
			self::set( $producto_id, $sede_id, absint( $stock ) );
		}

		// Sincroniza el stock global de Woo con la suma de sedes.
		self::sincronizar_woo( $producto_id );
	}

	/* ---------------------------------------------------------------------
	 * UI en cada variación
	 * ------------------------------------------------------------------- */

	/**
	 * Pinta los campos de stock por sede dentro de una variación.
	 *
	 * @param int     $loop      Índice de la variación en el formulario.
	 * @param array   $variation Datos de la variación.
	 * @param WP_Post $post      Post de la variación.
	 */
	public function campos_variacion( $loop, $variation, $post ) {
		$sedes = MSP_Sedes::obtener_sedes_activas();
		if ( empty( $sedes ) ) {
			return;
		}

		$variacion_id = (int) $post->ID;
		$por_sede     = self::por_sede( $variacion_id );

		echo '<div class="msp-stock-sedes-variacion" style="clear:both;padding-top:8px">';
		echo '<p class="form-row form-row-full"><strong>' .
			esc_html__( 'Stock por sede (Multisede POS)', 'multisede-pos' ) . '</strong></p>';

		foreach ( $sedes as $sede ) {
			$stock_actual = isset( $por_sede[ $sede->ID ] ) ? $por_sede[ $sede->ID ]['stock'] : 0;
			$reservado    = isset( $por_sede[ $sede->ID ] ) ? $por_sede[ $sede->ID ]['reservado'] : 0;

			woocommerce_wp_text_input(
				array(
					'id'                => 'msp_stock_var_' . $variacion_id . '_' . $sede->ID,
					'name'              => 'msp_stock_var[' . $variacion_id . '][' . $sede->ID . ']',
					'label'             => $sede->post_title,
					'value'             => $stock_actual,
					'type'              => 'number',
					'wrapper_class'     => 'form-row form-row-first',
					'desc_tip'          => true,
					'description'       => $reservado > 0
						/* translators: %d: unidades reservadas. */
						? sprintf( esc_html__( 'Reservado por pedidos pendientes de recojo: %d', 'multisede-pos' ), $reservado )
						: esc_html__( 'Existencias de esta variación en esta sede.', 'multisede-pos' ),
					'custom_attributes' => array(
						'step' => '1',
						'min'  => '0',
					),
				)
			);
		}
		echo '</div>';
	}

	/**
	 * Guarda el stock por sede de una variación.
	 *
	 * @param int $variacion_id ID de la variación.
	 * @param int $loop         Índice en el formulario.
	 */
	public function guardar_variacion( $variacion_id, $loop ) {
		// Nonce de WooCommerce (guardado del producto y guardado AJAX de variaciones).
		if ( ! isset( $_POST['security'] ) && ! isset( $_POST['woocommerce_meta_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $variacion_id ) ) {
			return;
		}

		if ( ! isset( $_POST['msp_stock_var'][ $variacion_id ] ) || ! is_array( $_POST['msp_stock_var'][ $variacion_id ] ) ) {
			return;
		}

		$valores = wp_unslash( $_POST['msp_stock_var'][ $variacion_id ] ); // phpcs:ignore WordPress.Security.ValidatedSanitized

		foreach ( $valores as $sede_id => $stock ) {
			$sede_id = absint( $sede_id );
			if ( ! $sede_id ) {
				continue;
			}
			self::set( $variacion_id, $sede_id, absint( $stock ) );
		}

		self::sincronizar_woo( $variacion_id );
	}

	/* ---------------------------------------------------------------------
	 * Columna en el listado de productos
	 * ------------------------------------------------------------------- */

	/**
	 * Añade la columna "Stock por sede".
	 *
	 * @param array $columns Columnas.
	 * @return array
	 */
	public function columna_listado( $columns ) {
		$nuevas = array();
		foreach ( $columns as $key => $label ) {
			$nuevas[ $key ] = $label;
			if ( 'is_in_stock' === $key ) {
				$nuevas['msp_stock_sedes'] = __( 'Stock por sede', 'multisede-pos' );
			}
		}
		// Si no existe la columna de stock de Woo, la añadimos al final.
		if ( ! isset( $nuevas['msp_stock_sedes'] ) ) {
			$nuevas['msp_stock_sedes'] = __( 'Stock por sede', 'multisede-pos' );
		}
		return $nuevas;
	}

	/**
	 * Contenido de la columna por producto.
	 *
	 * @param string $column      Columna.
	 * @param int    $producto_id ID de producto.
	 */
	public function columna_contenido( $column, $producto_id ) {
		if ( 'msp_stock_sedes' !== $column ) {
			return;
		}

		$product = wc_get_product( $producto_id );

		// En variables, el stock de la sede es la suma de sus variaciones.
		if ( $product && $product->is_type( 'variable' ) ) {
			$por_sede = array();
			foreach ( $product->get_children() as $variacion_id ) {
				foreach ( self::por_sede( $variacion_id ) as $sede_id => $datos ) {
					if ( ! isset( $por_sede[ $sede_id ] ) ) {
						$por_sede[ $sede_id ] = array(
							'stock'     => 0,
							'reservado' => 0,
						);
					}
					$por_sede[ $sede_id ]['stock']     += $datos['stock'];
					$por_sede[ $sede_id ]['reservado'] += $datos['reservado'];
				}
			}
		} else {
			$por_sede = self::por_sede( $producto_id );
		}

		if ( empty( $por_sede ) ) {
			echo '<span style="color:#999">—</span>';
			return;
		}

		$lineas = array();
		foreach ( $por_sede as $sede_id => $datos ) {
			$nombre   = get_the_title( $sede_id );
			$lineas[] = esc_html( $nombre ) . ': <strong>' . (int) $datos['stock'] . '</strong>';
		}
		echo wp_kses_post( implode( '<br>', $lineas ) );
	}
}
