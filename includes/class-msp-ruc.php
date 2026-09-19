<?php
/**
 * Consulta del RUC: razón social y estado del contribuyente.
 *
 * El cajero teclea el RUC y el nombre aparece solo. No es comodidad: SUNAT
 * compara la razón social con su padrón, así que un dedazo se convierte en una
 * factura observada con la venta ya cobrada.
 *
 * @package Multisede_POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Busca los datos de un RUC en un padrón externo, con cache.
 */
class MSP_Ruc {

	/** Opción con los ajustes de la consulta. */
	const OPCION = 'msp_ruc_consulta';

	/** Proveedor por defecto: padrón público de SUNAT, gratuito y sin registro. */
	const ENDPOINT = 'https://openruc.com/api/ruc/{ruc}';

	/** Segundos de espera. Pasado esto, el cajero teclea el nombre y sigue. */
	const TIMEOUT = 2;

	/** Días que se guarda una respuesta. El padrón se mueve poco. */
	const CACHE_DIAS = 30;

	/**
	 * Engancha los hooks del módulo.
	 */
	public function init() {
		add_action( 'wp_ajax_msp_consultar_ruc', array( $this, 'ajax_consultar' ) );
		// El checkout lo usa gente sin sesión: la consulta también tiene que
		// responderles. No expone nada que no esté ya en la web pública de
		// SUNAT, y solo contesta a RUCs que pasan la validación local.
		add_action( 'wp_ajax_nopriv_msp_consultar_ruc', array( $this, 'ajax_consultar' ) );
	}

	/**
	 * Ajustes de la consulta.
	 *
	 * @return array{activa:bool,endpoint:string}
	 */
	public static function ajustes() {
		$guardados = get_option( self::OPCION, array() );
		$ajustes   = array(
			'activa'   => isset( $guardados['activa'] ) ? (bool) $guardados['activa'] : true,
			'endpoint' => ! empty( $guardados['endpoint'] ) ? (string) $guardados['endpoint'] : self::ENDPOINT,
		);

		/**
		 * Permite apuntar a otro padrón sin tocar el POS ni el checkout.
		 *
		 * Es el punto por donde se cambia el proveedor gratuito por uno de pago
		 * —o por una copia local del padrón reducido de SUNAT— sin mover nada
		 * más. El `{ruc}` de la URL se sustituye por el número.
		 *
		 * @param array $ajustes Ajustes de la consulta.
		 */
		return apply_filters( 'msp_ruc_ajustes', $ajustes );
	}

	/**
	 * ¿Está encendida la consulta?
	 *
	 * @return bool
	 */
	public static function activa() {
		$a = self::ajustes();
		return $a['activa'] && ! empty( $a['endpoint'] );
	}

	/**
	 * Datos de un RUC.
	 *
	 * Devuelve siempre algo con lo que se pueda seguir: si el padrón no
	 * contesta, un WP_Error que el POS convierte en «tecléalo». Nunca lanza ni
	 * bloquea la venta.
	 *
	 * @param string $ruc RUC de 11 dígitos.
	 * @return array|WP_Error
	 */
	public static function consultar( $ruc ) {
		$ruc = preg_replace( '/[^0-9]/', '', (string) $ruc );

		// Se valida antes de salir a la red: el dígito verificador se comprueba
		// en local desde la v1.23.0, así que un número mal tecleado no llega a
		// molestar a nadie.
		if ( ! class_exists( 'MSP_Factura' ) || ! MSP_Factura::ruc_valido( $ruc ) ) {
			return new WP_Error( 'msp_ruc_invalido', __( 'Ese RUC no es válido.', 'multisede-pos' ) );
		}

		if ( ! self::activa() ) {
			return new WP_Error( 'msp_ruc_apagada', __( 'La consulta del RUC está apagada.', 'multisede-pos' ) );
		}

		$clave    = 'msp_ruc_' . $ruc;
		$guardado = get_transient( $clave );
		if ( is_array( $guardado ) ) {
			$guardado['cache'] = true;
			return $guardado;
		}

		$ajustes  = self::ajustes();
		$url      = str_replace( '{ruc}', rawurlencode( $ruc ), $ajustes['endpoint'] );
		$respuesta = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 2,
				'headers'     => array( 'Accept' => 'application/json' ),
				'user-agent'  => 'Multisede POS/' . MSP_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $respuesta ) ) {
			return new WP_Error( 'msp_ruc_sin_respuesta', __( 'No se pudo consultar el RUC.', 'multisede-pos' ) );
		}

		$codigo = (int) wp_remote_retrieve_response_code( $respuesta );
		if ( 200 !== $codigo ) {
			// Un 404 es un RUC que no está en el padrón: es una respuesta, no
			// una avería, y se guarda para no repetir la consulta.
			if ( 404 === $codigo ) {
				$vacio = array(
					'ruc'          => $ruc,
					'razon_social' => '',
					'encontrado'   => false,
				);
				set_transient( $clave, $vacio, DAY_IN_SECONDS );
				return $vacio;
			}
			return new WP_Error( 'msp_ruc_sin_respuesta', __( 'No se pudo consultar el RUC.', 'multisede-pos' ) );
		}

		$datos = json_decode( wp_remote_retrieve_body( $respuesta ), true );
		if ( ! is_array( $datos ) || empty( $datos['razon_social'] ) ) {
			return new WP_Error( 'msp_ruc_sin_datos', __( 'El padrón no devolvió la razón social.', 'multisede-pos' ) );
		}

		$limpio = array(
			'ruc'          => $ruc,
			'razon_social' => sanitize_text_field( (string) $datos['razon_social'] ),
			'estado'       => isset( $datos['estado'] ) ? sanitize_text_field( (string) $datos['estado'] ) : '',
			'condicion'    => isset( $datos['condicion'] ) ? sanitize_text_field( (string) $datos['condicion'] ) : '',
			'direccion'    => isset( $datos['direccion'] ) ? sanitize_text_field( (string) $datos['direccion'] ) : '',
			'as_of'        => isset( $datos['as_of'] ) ? sanitize_text_field( (string) $datos['as_of'] ) : '',
			'encontrado'   => true,
			'cache'        => false,
		);

		$limpio['aviso'] = self::aviso( $limpio );

		set_transient( $clave, $limpio, self::CACHE_DIAS * DAY_IN_SECONDS );

		return $limpio;
	}

	/**
	 * Aviso sobre el contribuyente, si lo hay.
	 *
	 * Un cliente de baja o no habido no impide emitirle la factura, pero es algo
	 * que conviene saber ANTES de cobrar, no después.
	 *
	 * @param array $datos Datos del padrón.
	 * @return string Vacío si no hay nada que advertir.
	 */
	private static function aviso( $datos ) {
		$estado    = strtoupper( $datos['estado'] );
		$condicion = strtoupper( $datos['condicion'] );

		if ( $estado && false === strpos( $estado, 'ACTIVO' ) ) {
			/* translators: %s: estado del contribuyente en SUNAT. */
			return sprintf( __( 'Ojo: en SUNAT este RUC figura como %s.', 'multisede-pos' ), $datos['estado'] );
		}
		if ( $condicion && false === strpos( $condicion, 'HABIDO' ) ) {
			/* translators: %s: condición del contribuyente en SUNAT. */
			return sprintf( __( 'Ojo: en SUNAT este RUC figura como %s.', 'multisede-pos' ), $datos['condicion'] );
		}

		return '';
	}

	/**
	 * AJAX: devuelve la razón social de un RUC.
	 */
	public function ajax_consultar() {
		check_ajax_referer( 'msp_ruc', 'nonce' );

		$ruc   = isset( $_POST['ruc'] ) ? wp_unslash( $_POST['ruc'] ) : '';
		$datos = self::consultar( $ruc );

		if ( is_wp_error( $datos ) ) {
			// Se responde 200 a propósito: no encontrar el nombre no es un
			// error de la venta. El campo se queda editable y el cajero sigue.
			wp_send_json_success(
				array(
					'encontrado' => false,
					'motivo'     => $datos->get_error_message(),
				)
			);
		}

		wp_send_json_success( $datos );
	}
}
