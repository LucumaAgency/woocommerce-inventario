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
		// Los pedidos con sede los gestiona el plugin (reserva/recojo/POS),
		// así que desactivamos la reducción automática de stock de Woo para ellos.
		add_filter( 'woocommerce_can_reduce_order_stock', array( $this, 'evitar_reduccion_woo' ), 10, 2 );
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
		update_post_meta( $producto_id, '_manage_stock', 'yes' );
		wc_update_product_stock( $product, $disponible, 'set' );
	}

	/**
	 * Evita la reducción automática de stock de Woo en pedidos con sede:
	 * esos los gestiona el plugin (reserva en recojo, descuento en POS).
	 *
	 * @param bool     $reduce Si Woo debe reducir.
	 * @param WC_Order $order  Pedido.
	 * @return bool
	 */
	public function evitar_reduccion_woo( $reduce, $order ) {
		if ( $order && $order->get_meta( '_msp_sede_id' ) ) {
			return false;
		}
		return $reduce;
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

		echo '<div class="options_group msp-stock-sedes">';
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

		$por_sede = self::por_sede( $producto_id );
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
