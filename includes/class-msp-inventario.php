<?php
/**
 * Pantalla de inventario por sede.
 *
 * Permite al gerente ver y ajustar el stock de su sede sin darle acceso al
 * catálogo de WooCommerce (precios, descripciones, publicación de productos).
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inventario por sede: consulta y ajuste de stock.
 */
class MSP_Inventario {

	const PAGE     = 'msp-inventario';
	const POR_PAGE = 20;

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'registrar_pagina' ) );
		add_action( 'admin_init', array( $this, 'procesar' ) );
	}

	/**
	 * Registra la página.
	 */
	public function registrar_pagina() {
		add_menu_page(
			__( 'Inventario por sede', 'multisede-pos' ),
			__( 'Inventario', 'multisede-pos' ),
			'msp_ver_stock',
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-clipboard',
			56
		);
	}

	/**
	 * Sedes que el usuario puede ver.
	 *
	 * A diferencia del POS y la Caja, aquí entran también las sedes que solo
	 * surten pedidos web: su stock también hay que gestionarlo.
	 *
	 * @return WP_Post[]
	 */
	private function sedes_disponibles() {
		$todas = MSP_Sedes::obtener_sedes_activas();

		if ( current_user_can( 'manage_options' ) ) {
			return $todas;
		}

		$mias = MSP_Roles::sedes_de_usuario( get_current_user_id() );
		return array_values(
			array_filter(
				$todas,
				function ( $sede ) use ( $mias ) {
					return in_array( (int) $sede->ID, $mias, true );
				}
			)
		);
	}

	/**
	 * ¿Puede el usuario ver esta sede?
	 *
	 * @param int $sede_id Sede.
	 * @return bool
	 */
	private function puede_ver_sede( $sede_id ) {
		foreach ( $this->sedes_disponibles() as $sede ) {
			if ( (int) $sede->ID === (int) $sede_id ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Guardado
	 * ------------------------------------------------------------------- */

	/**
	 * Procesa el ajuste de stock.
	 */
	public function procesar() {
		if ( ! isset( $_POST['msp_inventario_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'msp_gestionar_stock' ) ) {
			return;
		}
		check_admin_referer( 'msp_inventario', 'msp_inventario_nonce' );

		$sede_id = isset( $_POST['sede'] ) ? absint( wp_unslash( $_POST['sede'] ) ) : 0;
		if ( ! $sede_id || ! $this->puede_ver_sede( $sede_id ) ) {
			return;
		}

		$cambios = 0;

		if ( isset( $_POST['msp_stock'] ) && is_array( $_POST['msp_stock'] ) ) {
			$valores = wp_unslash( $_POST['msp_stock'] ); // phpcs:ignore WordPress.Security.ValidatedSanitized

			foreach ( $valores as $producto_id => $stock ) {
				$producto_id = absint( $producto_id );
				if ( ! $producto_id || '' === $stock ) {
					continue;
				}

				$nuevo  = absint( $stock );
				$actual = MSP_Stock::get( $producto_id, $sede_id );
				if ( $nuevo === $actual ) {
					continue;
				}

				MSP_Stock::set( $producto_id, $sede_id, $nuevo );
				MSP_Stock::sincronizar_woo( $producto_id );
				++$cambios;
			}
		}

		// Sin array_filter: 'guardado' => 0 es un valor válido (no había cambios)
		// y array_filter lo descartaría por ser falsy.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::PAGE,
					'sede'     => $sede_id,
					'busqueda' => isset( $_POST['busqueda'] ) ? sanitize_text_field( wp_unslash( $_POST['busqueda'] ) ) : '',
					'paged'    => isset( $_POST['paged'] ) ? absint( wp_unslash( $_POST['paged'] ) ) : 1,
					'guardado' => $cambios,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Vista
	 * ------------------------------------------------------------------- */

	/**
	 * Renderiza la pantalla.
	 */
	public function render() {
		$sedes = $this->sedes_disponibles();

		echo '<div class="wrap"><h1>' . esc_html__( 'Inventario por sede', 'multisede-pos' ) . '</h1>';

		if ( empty( $sedes ) ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'No tienes ninguna sede asignada. Pide a un administrador que te asigne una en tu perfil de usuario.', 'multisede-pos' ) .
				'</p></div></div>';
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Solo lectura de filtros.
		$sede_id  = isset( $_GET['sede'] ) ? absint( wp_unslash( $_GET['sede'] ) ) : 0;
		$busqueda = isset( $_GET['busqueda'] ) ? sanitize_text_field( wp_unslash( $_GET['busqueda'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$guardado = isset( $_GET['guardado'] ) ? absint( wp_unslash( $_GET['guardado'] ) ) : -1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $sede_id || ! $this->puede_ver_sede( $sede_id ) ) {
			$sede_id = (int) $sedes[0]->ID;
		}

		if ( $guardado > 0 ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: número de productos actualizados. */
						_n( '%d producto actualizado.', '%d productos actualizados.', $guardado, 'multisede-pos' ),
						$guardado
					)
				)
			);
		} elseif ( 0 === $guardado ) {
			echo '<div class="notice notice-info is-dismissible"><p>' .
				esc_html__( 'No había ningún cambio que guardar.', 'multisede-pos' ) . '</p></div>';
		}

		$this->filtros( $sedes, $sede_id, $busqueda );

		$query = $this->consultar_productos( $busqueda, $paged );
		$puede_editar = current_user_can( 'msp_gestionar_stock' );

		if ( ! $query->have_posts() ) {
			echo '<p>' . esc_html__( 'No se encontraron productos.', 'multisede-pos' ) . '</p></div>';
			return;
		}

		echo '<form method="post">';
		wp_nonce_field( 'msp_inventario', 'msp_inventario_nonce' );
		echo '<input type="hidden" name="msp_inventario_action" value="guardar" />';
		echo '<input type="hidden" name="sede" value="' . esc_attr( $sede_id ) . '" />';
		echo '<input type="hidden" name="busqueda" value="' . esc_attr( $busqueda ) . '" />';
		echo '<input type="hidden" name="paged" value="' . esc_attr( $paged ) . '" />';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Producto', 'multisede-pos' ) . '</th>';
		echo '<th>' . esc_html__( 'SKU', 'multisede-pos' ) . '</th>';
		echo '<th style="width:120px">' . esc_html__( 'Stock en la sede', 'multisede-pos' ) . '</th>';
		echo '<th style="width:110px">' . esc_html__( 'Reservado', 'multisede-pos' ) . '</th>';
		echo '<th style="width:110px">' . esc_html__( 'Disponible', 'multisede-pos' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $query->posts as $producto_id ) {
			$product = wc_get_product( $producto_id );
			if ( ! $product ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) ) {
				// El padre solo es un encabezado: el stock vive en las variaciones.
				echo '<tr><td colspan="5" style="background:#f6f7f7"><strong>' .
					esc_html( $product->get_name() ) . '</strong></td></tr>';

				foreach ( $product->get_children() as $variacion_id ) {
					$variacion = wc_get_product( $variacion_id );
					if ( $variacion ) {
						$this->fila( $variacion, $sede_id, $puede_editar, true );
					}
				}
				continue;
			}

			$this->fila( $product, $sede_id, $puede_editar, false );
		}

		echo '</tbody></table>';

		if ( $puede_editar ) {
			echo '<p><button type="submit" class="button button-primary">' .
				esc_html__( 'Guardar cambios', 'multisede-pos' ) . '</button></p>';
		} else {
			echo '<p class="description">' .
				esc_html__( 'Solo tienes permiso para consultar el inventario, no para ajustarlo.', 'multisede-pos' ) .
				'</p>';
		}

		echo '</form>';

		$this->paginacion( $query, $sede_id, $busqueda, $paged );
		echo '</div>';
	}

	/**
	 * Fila de un producto o variación.
	 *
	 * @param WC_Product $product      Producto.
	 * @param int        $sede_id      Sede.
	 * @param bool       $puede_editar Si el usuario puede ajustar.
	 * @param bool       $es_variacion Si es una variación (se indenta).
	 */
	private function fila( $product, $sede_id, $puede_editar, $es_variacion ) {
		$producto_id = $product->get_id();

		// Una sola consulta con los dos números reales. El reservado NO se
		// deduce del disponible: si el stock cae por debajo de lo reservado
		// (una merma sobre unidades ya comprometidas), el reservado real es
		// justo el dato que el gerente necesita ver.
		$datos      = MSP_Stock::por_sede( $producto_id );
		$stock      = isset( $datos[ $sede_id ] ) ? $datos[ $sede_id ]['stock'] : 0;
		$reservado  = isset( $datos[ $sede_id ] ) ? $datos[ $sede_id ]['reservado'] : 0;
		$disponible = max( 0, $stock - $reservado );
		$sobreventa = $reservado > $stock;

		$nombre = $product->get_name();
		if ( $es_variacion ) {
			$atributos = wc_get_formatted_variation( $product, true, false );
			$nombre    = $atributos ? $atributos : $nombre;
		}

		echo '<tr>';
		echo '<td' . ( $es_variacion ? ' style="padding-left:28px"' : '' ) . '>' . esc_html( $nombre ) . '</td>';
		echo '<td>' . esc_html( $product->get_sku() ? $product->get_sku() : '—' ) . '</td>';

		echo '<td>';
		if ( $puede_editar ) {
			echo '<input type="number" min="0" step="1" style="width:90px" name="msp_stock[' . esc_attr( $producto_id ) . ']" value="' . esc_attr( $stock ) . '" />';
		} else {
			echo '<strong>' . esc_html( $stock ) . '</strong>';
		}
		echo '</td>';

		echo '<td>';
		if ( $sobreventa ) {
			// Hay más unidades comprometidas que unidades en la repisa.
			echo '<span style="color:#b32d2e;font-weight:600" title="' .
				esc_attr__( 'Hay más unidades reservadas por pedidos web que unidades en la sede. Repón stock o cancela alguno de esos pedidos.', 'multisede-pos' ) .
				'">' . esc_html( $reservado ) . ' ⚠</span>';
		} elseif ( $reservado > 0 ) {
			echo '<span style="color:#996800">' . esc_html( $reservado ) . '</span>';
		} else {
			echo '<span style="color:#999">0</span>';
		}
		echo '</td>';

		echo '<td><strong' . ( $disponible > 0 ? '' : ' style="color:#b32d2e"' ) . '>' .
			esc_html( $disponible ) . '</strong></td>';
		echo '</tr>';
	}

	/**
	 * Selector de sede y buscador.
	 *
	 * @param WP_Post[] $sedes    Sedes disponibles.
	 * @param int       $sede_id  Sede activa.
	 * @param string    $busqueda Término de búsqueda.
	 */
	private function filtros( $sedes, $sede_id, $busqueda ) {
		echo '<form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';

		echo '<label><strong>' . esc_html__( 'Sede:', 'multisede-pos' ) . '</strong> <select name="sede">';
		foreach ( $sedes as $sede ) {
			echo '<option value="' . esc_attr( $sede->ID ) . '" ' . selected( $sede->ID, $sede_id, false ) . '>' .
				esc_html( $sede->post_title ) . '</option>';
		}
		echo '</select></label>';

		echo '<input type="search" name="busqueda" value="' . esc_attr( $busqueda ) . '" placeholder="' .
			esc_attr__( 'Buscar por nombre o SKU…', 'multisede-pos' ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Filtrar', 'multisede-pos' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Consulta los productos de la página actual.
	 *
	 * @param string $busqueda Término.
	 * @param int    $paged    Página.
	 * @return WP_Query
	 */
	private function consultar_productos( $busqueda, $paged ) {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => self::POR_PAGE,
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);

		if ( '' !== $busqueda ) {
			// Un SKU exacto gana a la búsqueda por texto.
			$por_sku = wc_get_product_id_by_sku( $busqueda );
			if ( $por_sku ) {
				$producto = wc_get_product( $por_sku );
				// Si el SKU es de una variación, mostramos su producto padre.
				if ( $producto && $producto->is_type( 'variation' ) ) {
					$por_sku = $producto->get_parent_id();
				}
				$args['post__in'] = array( $por_sku );
			} else {
				$args['s'] = $busqueda;
			}
		}

		return new WP_Query( $args );
	}

	/**
	 * Paginación.
	 *
	 * @param WP_Query $query    Consulta.
	 * @param int      $sede_id  Sede.
	 * @param string   $busqueda Búsqueda.
	 * @param int      $paged    Página actual.
	 */
	private function paginacion( $query, $sede_id, $busqueda, $paged ) {
		if ( $query->max_num_pages < 2 ) {
			return;
		}

		$enlaces = paginate_links(
			array(
				// Se quita 'guardado' o el aviso de "X productos actualizados"
				// reaparecería al cambiar de página, donde ya no es cierto.
				'base'      => remove_query_arg( 'guardado', add_query_arg( 'paged', '%#%' ) ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $query->max_num_pages,
				'add_args'  => array(
					'page'     => self::PAGE,
					'sede'     => $sede_id,
					'busqueda' => $busqueda,
				),
				'prev_text' => '«',
				'next_text' => '»',
			)
		);

		if ( $enlaces ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $enlaces ) . '</div></div>';
		}
	}
}
