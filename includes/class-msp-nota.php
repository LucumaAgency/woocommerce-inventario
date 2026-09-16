<?php
/**
 * Notas de crédito: devoluciones y correcciones, con aprobación del gerente.
 *
 * La nota de crédito es lo que la comunicación de baja no puede hacer: revertir
 * **parte** de una venta, corregir un RUC mal escrito, o anular pasados los 7
 * días del plazo de baja. Y hace falta sobre todo en **boletas**, que es donde
 * está casi toda la venta de la tienda.
 *
 * El flujo tiene dos manos a propósito:
 *
 *   cajero SOLICITA  →  gerente APRUEBA  →  se emite a SUNAT
 *
 * La solicitud **no reserva correlativo**: el número se toma al aprobar. Si se
 * reservara al pedirla, cada solicitud rechazada quemaría un número de la serie,
 * y SUNAT exige numeración sin huecos. Por eso las solicitudes viven en su
 * propia tabla y no en la de comprobantes: una es un trámite interno que puede
 * decirse que no, la otra es un documento fiscal.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Solicitudes de nota de crédito y su emisión.
 */
class MSP_Nota {

	/** Capacidad para pedir una nota (cajero). */
	const CAP_SOLICITAR = 'msp_solicitar_notas';

	/** Capacidad para aprobarla y que se emita (gerente / admin). */
	const CAP_APROBAR = 'msp_aprobar_notas';

	/**
	 * Nombre de la tabla de solicitudes.
	 *
	 * @return string
	 */
	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'msp_notas';
	}

	/**
	 * Estados por los que pasa una solicitud.
	 *
	 * @return array
	 */
	public static function estados() {
		return array(
			'pendiente' => __( 'Esperando aprobación', 'multisede-pos' ),
			'aprobada'  => __( 'Aprobada, emitiendo', 'multisede-pos' ),
			'emitida'   => __( 'Emitida', 'multisede-pos' ),
			'rechazada' => __( 'Rechazada', 'multisede-pos' ),
			'fallida'   => __( 'Aprobada, pero falló la emisión', 'multisede-pos' ),
		);
	}

	/**
	 * Crea una solicitud de nota de crédito.
	 *
	 * @param array $datos {
	 *     @type int    $comprobante_id  Comprobante que se corrige (obligatorio).
	 *     @type string $motivo          Código del catálogo 09.
	 *     @type string $detalle         Texto libre del cajero.
	 *     @type array  $lineas          item_id => cantidad a devolver. Vacío = todo.
	 *     @type bool   $devolver_stock  Si la mercadería vuelve a la tienda.
	 * }
	 * @return array|WP_Error Solicitud creada.
	 */
	public static function solicitar( $datos ) {
		global $wpdb;

		if ( ! current_user_can( self::CAP_SOLICITAR ) ) {
			return new WP_Error( 'msp_sin_permiso', __( 'No tienes permiso para pedir notas de crédito.', 'multisede-pos' ) );
		}

		$comprobante_id = isset( $datos['comprobante_id'] ) ? (int) $datos['comprobante_id'] : 0;
		$c              = MSP_Comprobante::obtener( $comprobante_id );

		if ( ! $c ) {
			return new WP_Error( 'msp_no_existe', __( 'Ese comprobante no existe.', 'multisede-pos' ) );
		}

		// Solo se corrige lo que SUNAT ya aceptó. Si todavía está en cola o fue
		// rechazado, no hay nada que corregir: se arregla el envío, no se emite
		// una nota sobre un documento que para SUNAT no existe.
		if ( 'aceptado' !== $c['estado'] ) {
			return new WP_Error(
				'msp_no_aceptado',
				__( 'Ese comprobante todavía no fue aceptado por SUNAT, así que no hay nada que corregir. Revisa su estado en Comprobantes.', 'multisede-pos' )
			);
		}

		if ( MSP_Comprobante::es_nota( $c ) ) {
			return new WP_Error( 'msp_nota_de_nota', __( 'No se emite una nota de crédito sobre otra nota de crédito.', 'multisede-pos' ) );
		}

		$motivo = isset( $datos['motivo'] ) ? sanitize_text_field( $datos['motivo'] ) : '';
		if ( ! isset( MSP_Comprobante::motivos_nota()[ $motivo ] ) ) {
			return new WP_Error( 'msp_sin_motivo', __( 'Elige el motivo de la nota de crédito.', 'multisede-pos' ) );
		}

		// La sede tiene que tener la serie del tipo de nota que toca: la de
		// boletas y la de facturas son distintas.
		$tipo_nota = MSP_Comprobante::tipo_nota_para( $c );
		$serie     = MSP_Comprobante::serie_de_sede( (int) $c['sede_id'], $tipo_nota );
		if ( ! MSP_Comprobante::serie_valida( $serie, $tipo_nota ) ) {
			return new WP_Error(
				'msp_sin_serie_nota',
				sprintf(
					/* translators: %s: ejemplo de serie. */
					__( 'La tienda de esa venta no tiene configurada la serie de notas de crédito (ej. %s). Pídeselo al administrador.', 'multisede-pos' ),
					MSP_Comprobante::dato_tipo( $tipo_nota, 'prefijo' ) . 'C00'
				)
			);
		}

		$lineas = self::normalizar_lineas( $c, isset( $datos['lineas'] ) ? $datos['lineas'] : array() );
		if ( is_wp_error( $lineas ) ) {
			return $lineas;
		}

		$total = self::total_de_lineas( $c, $lineas );
		if ( $total <= 0 ) {
			return new WP_Error( 'msp_nota_vacia', __( 'La nota de crédito no puede ser por S/ 0.00: marca qué se devuelve.', 'multisede-pos' ) );
		}

		// Ya devuelto antes: no se puede devolver dos veces lo mismo.
		$ya = self::total_ya_acreditado( $comprobante_id );
		if ( round( $ya + $total, 2 ) > round( (float) $c['total'], 2 ) ) {
			return new WP_Error(
				'msp_nota_excede',
				sprintf(
					/* translators: 1: importe ya devuelto, 2: total del comprobante. */
					__( 'Ya se devolvieron S/ %1$s de este comprobante, que es de S/ %2$s. No se puede devolver más de lo que se cobró.', 'multisede-pos' ),
					number_format( $ya, 2 ),
					number_format( (float) $c['total'], 2 )
				)
			);
		}

		$ok = $wpdb->insert(
			self::tabla(),
			array(
				'comprobante_id' => $comprobante_id,
				'pedido_id'      => $c['pedido_id'] ? (int) $c['pedido_id'] : null,
				'sede_id'        => (int) $c['sede_id'],
				'motivo'         => $motivo,
				'detalle'        => isset( $datos['detalle'] ) ? sanitize_textarea_field( $datos['detalle'] ) : '',
				'lineas'         => wp_json_encode( $lineas ),
				'total'          => $total,
				'devolver_stock' => ! empty( $datos['devolver_stock'] ) ? 1 : 0,
				'estado'         => 'pendiente',
				'solicitante_id' => get_current_user_id(),
				'solicitado_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%d', '%s', '%d', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'msp_no_guardada', __( 'No se pudo guardar la solicitud.', 'multisede-pos' ) );
		}

		$solicitud = self::obtener( (int) $wpdb->insert_id );

		if ( $c['pedido_id'] ) {
			$pedido = wc_get_order( (int) $c['pedido_id'] );
			if ( $pedido ) {
				$pedido->add_order_note(
					sprintf(
						/* translators: 1: importe, 2: usuario. */
						__( 'Nota de crédito solicitada por S/ %1$s (%2$s). Pendiente de que un gerente la apruebe.', 'multisede-pos' ),
						number_format( $total, 2 ),
						wp_get_current_user()->display_name
					)
				);
				$pedido->save();
			}
		}

		/**
		 * Se ha pedido una nota de crédito.
		 *
		 * @param array $solicitud Solicitud.
		 */
		do_action( 'msp_nota_solicitada', $solicitud );

		return $solicitud;
	}

	/**
	 * Aprueba una solicitud y emite la nota.
	 *
	 * Aquí es donde se toma el correlativo: en la aprobación, no en la
	 * solicitud.
	 *
	 * @param int    $id        Solicitud.
	 * @param string $respuesta Comentario del gerente.
	 * @return array|WP_Error
	 */
	public static function aprobar( $id, $respuesta = '' ) {
		if ( ! current_user_can( self::CAP_APROBAR ) ) {
			return new WP_Error( 'msp_sin_permiso', __( 'Solo un gerente puede aprobar notas de crédito.', 'multisede-pos' ) );
		}

		$s = self::obtener( $id );
		if ( ! $s ) {
			return new WP_Error( 'msp_no_existe', __( 'Esa solicitud no existe.', 'multisede-pos' ) );
		}
		if ( 'pendiente' !== $s['estado'] ) {
			return new WP_Error( 'msp_ya_resuelta', __( 'Esa solicitud ya fue resuelta.', 'multisede-pos' ) );
		}

		// Quien pide no aprueba: es el sentido de tener dos manos.
		if ( (int) $s['solicitante_id'] === get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'msp_autoaprobacion',
				__( 'No puedes aprobar tu propia solicitud: tiene que revisarla otra persona.', 'multisede-pos' )
			);
		}

		$c = MSP_Comprobante::obtener( (int) $s['comprobante_id'] );
		if ( ! $c ) {
			return new WP_Error( 'msp_no_existe', __( 'El comprobante original ya no existe.', 'multisede-pos' ) );
		}

		self::actualizar(
			$id,
			array(
				'estado'      => 'aprobada',
				'revisor_id'  => get_current_user_id(),
				'revisado_at' => current_time( 'mysql' ),
				'respuesta'   => $respuesta,
			)
		);

		// Se reserva el correlativo de la nota AHORA.
		$nota = MSP_Comprobante::reservar(
			array(
				'sede_id'          => (int) $c['sede_id'],
				'pedido_id'        => $c['pedido_id'] ? (int) $c['pedido_id'] : null,
				'tipo'             => MSP_Comprobante::tipo_nota_para( $c ),
				'doc_afectado_id'  => (int) $c['id'],
				'motivo'           => $s['motivo'],
				'motivo_texto'     => self::texto_motivo( $s ),
				'cliente_tipo_doc' => $c['cliente_tipo_doc'],
				'cliente_num_doc'  => $c['cliente_num_doc'],
				'cliente_nombre'   => $c['cliente_nombre'],
				'total'            => (float) $s['total'],
				'igv'              => self::igv_de( (float) $s['total'], $c ),
			)
		);

		if ( is_wp_error( $nota ) ) {
			self::actualizar( $id, array( 'estado' => 'fallida', 'respuesta' => $nota->get_error_message() ) );
			return $nota;
		}

		self::actualizar( $id, array( 'estado' => 'emitida', 'nota_comprobante_id' => (int) $nota['id'] ) );

		// El stock y el dinero se mueven al aprobar, no al pedir: hasta aquí no
		// había pasado nada real.
		self::aplicar_efectos( $s, $c, $nota );

		// La emisión a SUNAT va por la cola de siempre, con sus reintentos: una
		// nota no tiene por qué esperar a SUNAT delante del cliente.
		MSP_Cola::programar( (int) $nota['id'], 0 );

		/**
		 * Nota de crédito aprobada y encolada.
		 *
		 * @param array $solicitud Solicitud.
		 * @param array $nota      Comprobante de la nota.
		 */
		do_action( 'msp_nota_aprobada', self::obtener( $id ), $nota );

		return $nota;
	}

	/**
	 * Rechaza una solicitud. No se emite nada ni se gasta numeración.
	 *
	 * @param int    $id        Solicitud.
	 * @param string $respuesta Motivo del rechazo.
	 * @return true|WP_Error
	 */
	public static function rechazar( $id, $respuesta = '' ) {
		if ( ! current_user_can( self::CAP_APROBAR ) ) {
			return new WP_Error( 'msp_sin_permiso', __( 'Solo un gerente puede rechazar notas de crédito.', 'multisede-pos' ) );
		}

		$s = self::obtener( $id );
		if ( ! $s || 'pendiente' !== $s['estado'] ) {
			return new WP_Error( 'msp_ya_resuelta', __( 'Esa solicitud ya fue resuelta.', 'multisede-pos' ) );
		}

		self::actualizar(
			$id,
			array(
				'estado'      => 'rechazada',
				'revisor_id'  => get_current_user_id(),
				'revisado_at' => current_time( 'mysql' ),
				'respuesta'   => $respuesta,
			)
		);

		if ( $s['pedido_id'] ) {
			$pedido = wc_get_order( (int) $s['pedido_id'] );
			if ( $pedido ) {
				$pedido->add_order_note(
					sprintf(
						/* translators: 1: usuario, 2: motivo. */
						__( 'Nota de crédito RECHAZADA por %1$s. %2$s', 'multisede-pos' ),
						wp_get_current_user()->display_name,
						$respuesta
					)
				);
				$pedido->save();
			}
		}

		return true;
	}

	/**
	 * Devuelve stock y dinero, si corresponde.
	 *
	 * @param array $s    Solicitud.
	 * @param array $c    Comprobante original.
	 * @param array $nota Comprobante de la nota.
	 */
	private static function aplicar_efectos( $s, $c, $nota ) {
		$pedido = $s['pedido_id'] ? wc_get_order( (int) $s['pedido_id'] ) : null;

		// Stock: solo si el cajero lo marcó. Lo normal es que la prenda se
		// revenda, pero puede volver dañada, y eso solo lo ve quien la tiene
		// delante.
		if ( ! empty( $s['devolver_stock'] ) && $pedido && class_exists( 'MSP_Stock' ) ) {
			$lineas = json_decode( (string) $s['lineas'], true );
			if ( is_array( $lineas ) ) {
				foreach ( $lineas as $item_id => $cantidad ) {
					$item = $pedido->get_item( (int) $item_id );
					if ( ! $item ) {
						continue;
					}
					$producto_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
					MSP_Stock::ajustar( (int) $producto_id, (int) $s['sede_id'], (int) $cantidad );
				}
			}
		}

		if ( $pedido ) {
			$pedido->add_order_note(
				sprintf(
					/* translators: 1: número de la nota, 2: importe, 3: quién aprobó. */
					__( 'Nota de crédito %1$s emitida por S/ %2$s, aprobada por %3$s.', 'multisede-pos' ),
					MSP_Comprobante::numero( $nota ),
					number_format( (float) $s['total'], 2 ),
					wp_get_current_user()->display_name
				)
			);
			$pedido->save();
		}

		/**
		 * Para que la caja registre la salida de efectivo.
		 *
		 * @param array         $solicitud Solicitud.
		 * @param array         $nota      Comprobante de la nota.
		 * @param WC_Order|null $pedido    Pedido.
		 */
		do_action( 'msp_nota_emitida', $s, $nota, $pedido );
	}

	/**
	 * Comprueba y normaliza las líneas a devolver.
	 *
	 * Vacío significa "todo el comprobante".
	 *
	 * @param array $c      Comprobante.
	 * @param array $lineas item_id => cantidad.
	 * @return array|WP_Error
	 */
	private static function normalizar_lineas( $c, $lineas ) {
		$pedido = $c['pedido_id'] ? wc_get_order( (int) $c['pedido_id'] ) : null;
		if ( ! $pedido ) {
			return array(); // Sin pedido (emisión suelta): la nota va por el total.
		}

		$salida = array();
		foreach ( (array) $lineas as $item_id => $cantidad ) {
			$cantidad = (int) $cantidad;
			$item     = $pedido->get_item( (int) $item_id );
			if ( ! $item || $cantidad < 1 ) {
				continue;
			}
			if ( $cantidad > (int) $item->get_quantity() ) {
				return new WP_Error(
					'msp_cantidad_excede',
					sprintf(
						/* translators: %s: nombre del producto. */
						__( 'No se pueden devolver más unidades de «%s» de las que se vendieron.', 'multisede-pos' ),
						$item->get_name()
					)
				);
			}
			$salida[ (int) $item_id ] = $cantidad;
		}

		if ( ! $salida ) {
			// Devolución total: todas las líneas, con toda su cantidad.
			foreach ( $pedido->get_items() as $item_id => $item ) {
				$salida[ (int) $item_id ] = (int) $item->get_quantity();
			}
		}

		return $salida;
	}

	/**
	 * Importe de las líneas marcadas, con IGV.
	 *
	 * @param array $c      Comprobante.
	 * @param array $lineas item_id => cantidad.
	 * @return float
	 */
	private static function total_de_lineas( $c, $lineas ) {
		$pedido = $c['pedido_id'] ? wc_get_order( (int) $c['pedido_id'] ) : null;
		if ( ! $pedido || ! $lineas ) {
			return round( (float) $c['total'], 2 );
		}

		$total = 0;
		foreach ( $lineas as $item_id => $cantidad ) {
			$item = $pedido->get_item( (int) $item_id );
			if ( ! $item ) {
				continue;
			}
			$unidades = max( 1, (int) $item->get_quantity() );
			$linea    = (float) $item->get_total() + (float) $item->get_total_tax();
			$total   += ( $linea / $unidades ) * (int) $cantidad;
		}

		return round( $total, 2 );
	}

	/**
	 * Cuánto se ha acreditado ya de un comprobante.
	 *
	 * @param int $comprobante_id Comprobante.
	 * @return float
	 */
	public static function total_ya_acreditado( $comprobante_id ) {
		global $wpdb;
		$tabla = self::tabla();

		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( total ), 0 ) FROM {$tabla}
				 WHERE comprobante_id = %d AND estado IN ( 'aprobada', 'emitida' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $comprobante_id
			)
		);
	}

	/**
	 * IGV de la nota, proporcional al del comprobante original.
	 *
	 * Se calcula desde el original y no como total/1.18 para que una venta con
	 * algo exonerado no acabe declarando un IGV que no se cobró.
	 *
	 * @param float $total Total de la nota.
	 * @param array $c     Comprobante original.
	 * @return float
	 */
	private static function igv_de( $total, $c ) {
		$total_original = round( (float) $c['total'], 2 );
		if ( $total_original <= 0 ) {
			return 0;
		}
		return round( (float) $c['igv'] * ( $total / $total_original ), 2 );
	}

	/**
	 * Texto del motivo, con el detalle del cajero si lo puso.
	 *
	 * @param array $s Solicitud.
	 * @return string
	 */
	public static function texto_motivo( $s ) {
		$motivos = MSP_Comprobante::motivos_nota();
		$texto   = isset( $motivos[ $s['motivo'] ] ) ? $motivos[ $s['motivo'] ] : __( 'Devolución', 'multisede-pos' );

		if ( ! empty( $s['detalle'] ) ) {
			$texto .= ' — ' . $s['detalle'];
		}

		return mb_substr( $texto, 0, 250 );
	}

	/**
	 * Una solicitud.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function obtener( $id ) {
		global $wpdb;
		$tabla = self::tabla();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Lista de solicitudes.
	 *
	 * @param array $args {
	 *     @type string $estado  Filtro por estado.
	 *     @type int    $sede_id Filtro por sede.
	 *     @type int    $limite  Máximo.
	 * }
	 * @return array
	 */
	public static function listar( $args = array() ) {
		global $wpdb;
		$tabla = self::tabla();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['estado'] ) ) {
			$where[]  = 'estado = %s';
			$params[] = $args['estado'];
		}
		if ( ! empty( $args['sede_id'] ) ) {
			$where[]  = 'sede_id = %d';
			$params[] = (int) $args['sede_id'];
		}

		$limite   = isset( $args['limite'] ) ? (int) $args['limite'] : 50;
		$params[] = $limite;

		$sql = "SELECT * FROM {$tabla} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Cuántas esperan aprobación.
	 *
	 * @return int
	 */
	public static function pendientes() {
		global $wpdb;
		$tabla = self::tabla();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabla} WHERE estado = 'pendiente'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Actualiza campos de una solicitud.
	 *
	 * @param int   $id    ID.
	 * @param array $datos Campos.
	 * @return bool
	 */
	private static function actualizar( $id, $datos ) {
		global $wpdb;

		$permitidos = array(
			'estado'              => '%s',
			'revisor_id'          => '%d',
			'revisado_at'         => '%s',
			'respuesta'           => '%s',
			'nota_comprobante_id' => '%d',
		);

		$campos   = array();
		$formatos = array();
		foreach ( $permitidos as $campo => $formato ) {
			if ( array_key_exists( $campo, $datos ) ) {
				$campos[ $campo ] = $datos[ $campo ];
				$formatos[]       = $formato;
			}
		}

		if ( ! $campos ) {
			return false;
		}

		return (bool) $wpdb->update( self::tabla(), $campos, array( 'id' => (int) $id ), $formatos, array( '%d' ) );
	}
}
