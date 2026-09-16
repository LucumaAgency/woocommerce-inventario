<?php
/**
 * Factura electrónica en el canal web: pedirla en el checkout.
 *
 * Una boleta se emite siempre: si el cliente no dice nada, sale a su nombre y
 * ya está. La factura no funciona así — necesita **RUC y razón social del
 * comprador**, y sin eso SUNAT la rechaza. Así que el checkout tiene que
 * preguntarlo antes de cobrar, no después.
 *
 * Todo lo que hace esta clase es **decorar el pedido**: marca `_msp_tipo_comprobante`
 * y guarda los datos del comprador. Quién emite, cuándo y cómo no cambia: sigue
 * siendo la cola, con el mismo motor que ya emite boletas. Por eso aquí no hay
 * una línea que hable con SUNAT.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campos de factura en el checkout y su validación.
 */
class MSP_Factura {

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'registrar_campos' ), 30 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validar' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'guardar' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'script' ) );

		// En el pedido del panel, que se vea de un vistazo que lleva factura.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'mostrar_en_pedido' ) );
	}

	/**
	 * ¿Hay alguna sede que pueda emitir facturas?
	 *
	 * Si ninguna tiene serie de factura configurada, el checkout no enseña nada:
	 * ofrecer una factura que después no se puede emitir es peor que no
	 * ofrecerla, porque el cliente ya se fue con la expectativa.
	 *
	 * @return bool
	 */
	public static function disponible() {
		if ( ! class_exists( 'MSP_Sedes' ) || ! class_exists( 'MSP_Comprobante' ) ) {
			return false;
		}

		return ! empty( self::sedes_con_factura() );
	}

	/**
	 * Sedes que pueden emitir facturas, con su serie.
	 *
	 * No basta con que la meta exista: una serie vacía o mal escrita no sirve
	 * para emitir, y habilitar la casilla con ella prometería al cliente una
	 * factura que después falla al reservar el correlativo.
	 *
	 * @return array sede_id => serie.
	 */
	public static function sedes_con_factura() {
		if ( ! class_exists( 'MSP_Sedes' ) || ! class_exists( 'MSP_Comprobante' ) ) {
			return array();
		}

		$sedes = get_posts(
			array(
				'post_type'      => MSP_Sedes::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Pocas sedes, y el resultado se cachea abajo.
					array(
						'key'     => MSP_Comprobante::META_SERIE_FACTURA,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$con_serie = array();
		foreach ( $sedes as $sede_id ) {
			$serie = MSP_Comprobante::serie_de_sede( $sede_id, 'factura' );
			if ( MSP_Comprobante::serie_valida( $serie, 'factura' ) ) {
				$con_serie[ (int) $sede_id ] = $serie;
			}
		}

		return $con_serie;
	}

	/**
	 * ¿Puede esta sede emitir facturas?
	 *
	 * @param int $sede_id Sede.
	 * @return bool
	 */
	public static function sede_factura( $sede_id ) {
		return MSP_Comprobante::serie_valida( MSP_Comprobante::serie_de_sede( $sede_id, 'factura' ), 'factura' );
	}

	/**
	 * Valida un RUC peruano: 11 dígitos, tipo conocido y dígito verificador.
	 *
	 * Se comprueba entero y en local. No se consulta el RUC contra ningún
	 * servicio —esa decisión está tomada y razonada en `PLAN-FACTURAS.md`—, pero
	 * el dígito verificador sí se puede verificar aquí mismo y ataja el error
	 * más común: un dígito mal tecleado. Sin esto, el error aparece cuando SUNAT
	 * rechaza la factura, con el pedido ya cobrado.
	 *
	 * @param string $ruc RUC.
	 * @return bool
	 */
	public static function ruc_valido( $ruc ) {
		$ruc = preg_replace( '/[^0-9]/', '', (string) $ruc );

		if ( 11 !== strlen( $ruc ) ) {
			return false;
		}

		// Los dos primeros dígitos dicen qué es: 10 y 15 persona natural con
		// negocio, 17 sucesión indivisa, 20 persona jurídica.
		if ( ! in_array( substr( $ruc, 0, 2 ), array( '10', '15', '17', '20' ), true ) ) {
			return false;
		}

		$pesos = array( 5, 4, 3, 2, 7, 6, 5, 4, 3, 2 );
		$suma  = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$suma += (int) $ruc[ $i ] * $pesos[ $i ];
		}

		$resto      = $suma % 11;
		$verificador = 11 - $resto;
		if ( 10 === $verificador ) {
			$verificador = 0;
		} elseif ( 11 === $verificador ) {
			$verificador = 1;
		}

		return (int) $ruc[10] === $verificador;
	}

	/**
	 * Añade al checkout la casilla de factura y sus datos.
	 *
	 * Van como campos nativos de WooCommerce, igual que el DNI: así el tema los
	 * maqueta con el resto y no quedan colgando fuera del formulario.
	 *
	 * @param array $campos Campos del checkout.
	 * @return array
	 */
	public function registrar_campos( $campos ) {
		if ( ! self::disponible() ) {
			return $campos;
		}

		// Después del DNI (prioridad 120), que cierra los datos del cliente.
		$campos['billing']['msp_quiere_factura'] = array(
			'type'     => 'checkbox',
			'label'    => __( 'Necesito factura (con RUC)', 'multisede-pos' ),
			'required' => false,
			'class'    => array( 'form-row-wide' ),
			'priority' => 130,
		);

		$campos['billing']['msp_ruc'] = array(
			'type'              => 'text',
			'label'             => __( 'RUC', 'multisede-pos' ),
			'placeholder'       => __( '11 dígitos', 'multisede-pos' ),
			'required'          => false,
			'class'             => array( 'form-row-wide', 'msp-campo-factura' ),
			'priority'          => 131,
			'custom_attributes' => array(
				'inputmode' => 'numeric',
				'maxlength' => '11',
			),
		);

		$campos['billing']['msp_razon_social'] = array(
			'type'        => 'text',
			'label'       => __( 'Razón social', 'multisede-pos' ),
			'placeholder' => __( 'Nombre de la empresa tal como figura en SUNAT', 'multisede-pos' ),
			'required'    => false,
			'class'       => array( 'form-row-wide', 'msp-campo-factura' ),
			'priority'    => 132,
		);

		$campos['billing']['msp_direccion_fiscal'] = array(
			'type'        => 'text',
			'label'       => __( 'Dirección fiscal (opcional)', 'multisede-pos' ),
			'required'    => false,
			'class'       => array( 'form-row-wide', 'msp-campo-factura' ),
			'priority'    => 133,
		);

		return $campos;
	}

	/**
	 * Muestra u oculta los campos de factura según la casilla.
	 *
	 * JS mínimo e inline: son tres campos de un formulario que ya existe, no
	 * merece un archivo ni una dependencia. Si el JS no corre, los campos se ven
	 * siempre y el checkout sigue funcionando — el servidor es quien valida.
	 */
	public function script() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! self::disponible() ) {
			return;
		}
		?>
		<script>
		( function () {
			function sincronizar() {
				var casilla = document.getElementById( 'msp_quiere_factura' );
				if ( ! casilla ) { return; }
				var quiere = casilla.checked;
				document.querySelectorAll( '.msp-campo-factura' ).forEach( function ( fila ) {
					fila.style.display = quiere ? '' : 'none';
				} );
			}
			document.addEventListener( 'change', function ( e ) {
				if ( e.target && 'msp_quiere_factura' === e.target.id ) { sincronizar(); }
			} );
			document.addEventListener( 'DOMContentLoaded', sincronizar );
			// El checkout se repinta solo al cambiar envío o pago.
			if ( window.jQuery ) { jQuery( document.body ).on( 'updated_checkout', sincronizar ); }
			sincronizar();
		} )();
		</script>
		<?php
	}

	/**
	 * ¿Pidió factura en este envío del checkout?
	 *
	 * @return bool
	 */
	private static function la_pidio() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo valida el nonce del checkout.
		return ! empty( $_POST['msp_quiere_factura'] );
	}

	/**
	 * Un campo del checkout, limpio.
	 *
	 * @param string $campo Nombre.
	 * @return string
	 */
	private static function campo( $campo ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo valida el nonce del checkout.
		return isset( $_POST[ $campo ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $campo ] ) ) ) : '';
	}

	/**
	 * Valida los datos de la factura antes de cobrar.
	 *
	 * Todo lo que no se compruebe aquí se convierte en un rechazo de SUNAT con
	 * el pedido ya pagado, que es mucho más caro de resolver.
	 */
	public function validar() {
		if ( ! self::la_pidio() ) {
			return;
		}

		// La factura la emite la sede que surte el pedido. Si esa tienda no
		// tiene serie de factura, no hay con qué emitirla.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo valida el nonce del checkout.
		$sede = isset( $_POST['msp_sede_recojo'] ) ? absint( wp_unslash( $_POST['msp_sede_recojo'] ) ) : 0;
		if ( $sede && ! self::sede_factura( $sede ) ) {
			wc_add_notice(
				__( 'La tienda que elegiste todavía no emite facturas. Elige otra tienda o quita la casilla de factura para recibir una boleta.', 'multisede-pos' ),
				'error'
			);
			return;
		}

		$ruc = preg_replace( '/[^0-9]/', '', self::campo( 'msp_ruc' ) );

		if ( '' === $ruc ) {
			wc_add_notice( __( 'Para emitir la factura necesitamos tu RUC.', 'multisede-pos' ), 'error' );
		} elseif ( 11 !== strlen( $ruc ) ) {
			wc_add_notice( __( 'El RUC debe tener 11 dígitos.', 'multisede-pos' ), 'error' );
		} elseif ( ! self::ruc_valido( $ruc ) ) {
			wc_add_notice( __( 'Ese RUC no es válido: revisa que no falte o sobre un dígito.', 'multisede-pos' ), 'error' );
		}

		if ( '' === self::campo( 'msp_razon_social' ) ) {
			wc_add_notice( __( 'Falta la razón social para la factura.', 'multisede-pos' ), 'error' );
		}
	}

	/**
	 * Marca el pedido como "lleva factura" y guarda al comprador.
	 *
	 * @param WC_Order $order Pedido.
	 * @param array    $data  Datos enviados.
	 */
	public function guardar( $order, $data ) {
		unset( $data );

		if ( ! self::la_pidio() ) {
			return;
		}

		$ruc = preg_replace( '/[^0-9]/', '', self::campo( 'msp_ruc' ) );
		if ( ! self::ruc_valido( $ruc ) ) {
			return; // La validación ya avisó; no se marca el pedido a medias.
		}

		$order->update_meta_data( '_msp_tipo_comprobante', 'factura' );
		$order->update_meta_data( '_msp_cliente_tipo_doc', MSP_Comprobante::DOC_RUC );
		$order->update_meta_data( '_msp_cliente_num_doc', $ruc );
		$order->update_meta_data( '_msp_cliente_nombre', self::campo( 'msp_razon_social' ) );

		$direccion = self::campo( 'msp_direccion_fiscal' );
		if ( '' !== $direccion ) {
			$order->update_meta_data( '_msp_cliente_direccion', $direccion );
		}
	}

	/**
	 * Muestra los datos de facturación en el pedido del panel.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public function mostrar_en_pedido( $order ) {
		if ( 'factura' !== MSP_Comprobante::tipo_valido( $order->get_meta( '_msp_tipo_comprobante' ) ) ) {
			return;
		}

		echo '<p><strong>' . esc_html__( 'Factura solicitada', 'multisede-pos' ) . '</strong><br>';
		echo esc_html__( 'RUC:', 'multisede-pos' ) . ' ' . esc_html( $order->get_meta( '_msp_cliente_num_doc' ) ) . '<br>';
		echo esc_html( $order->get_meta( '_msp_cliente_nombre' ) );
		$direccion = $order->get_meta( '_msp_cliente_direccion' );
		if ( $direccion ) {
			echo '<br>' . esc_html( $direccion );
		}
		echo '</p>';
	}
}
