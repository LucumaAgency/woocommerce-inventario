<?php
/**
 * Motor de emisión electrónica: arma, firma y envía el comprobante a SUNAT.
 *
 * Fase 2. Aquí NO se decide cuándo emitir (eso es Fase 3): esta clase recibe un
 * comprobante ya reservado por MSP_Comprobante y lo lleva hasta el CDR.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emite comprobantes contra el web service de SUNAT usando Greenter.
 */
class MSP_Emisor {

	/** Opción con los ajustes de facturación. */
	const OPCION = 'msp_facturacion';

	/** Carpeta de archivos dentro de uploads. */
	const CARPETA = 'msp-comprobantes';

	/**
	 * Ajustes guardados, con sus valores por defecto.
	 *
	 * El entorno arranca en 'beta' a propósito: en desarrollo, un envío
	 * accidental a producción ensucia la numeración real de saraih y eso no se
	 * deshace.
	 *
	 * @return array
	 */
	public static function ajustes() {
		$guardados = get_option( self::OPCION, array() );
		if ( ! is_array( $guardados ) ) {
			$guardados = array();
		}

		return array_merge(
			array(
				'entorno'       => 'beta',
				// Apagada por defecto: encender esto hace que cada venta real
				// consuma numeración. Es una decisión, no un valor por defecto.
				'emision_automatica' => 0,
				// Interruptor de pruebas: hace fallar el envío a propósito para
				// ejercitar la cola de reintentos. Se ignora en producción.
				'simular_fallo'      => 0,
				'cert_path'     => '',
				'sol_usuario'   => 'MODDATOS',
				'sol_clave'     => 'moddatos',
				'ruc'           => '20000000001',
				'razon_social'  => 'EMPRESA DE PRUEBA',
				'direccion'     => '',
				'ubigeo'        => '',
				'departamento'  => '',
				'provincia'     => '',
				'distrito'      => '',
			),
			$guardados
		);
	}

	/**
	 * ¿Estamos apuntando a producción?
	 *
	 * @return bool
	 */
	public static function es_produccion() {
		$a = self::ajustes();
		return 'produccion' === $a['entorno'];
	}

	/**
	 * Ruta del certificado PEM.
	 *
	 * Orden de preferencia:
	 * 1. La constante MSP_CERT_PATH de wp-config.php. Es la vía recomendada en
	 *    producción: no se puede cambiar desde el panel ni viaja en los backups
	 *    de la base de datos.
	 * 2. El ajuste guardado.
	 * 3. El certificado de PRUEBA que viaja con el plugin, solo si NO estamos
	 *    en producción.
	 *
	 * @return string Ruta, o cadena vacía si no hay ninguna utilizable.
	 */
	public static function ruta_certificado() {
		if ( defined( 'MSP_CERT_PATH' ) && MSP_CERT_PATH && file_exists( MSP_CERT_PATH ) ) {
			return MSP_CERT_PATH;
		}

		$a = self::ajustes();
		if ( $a['cert_path'] && file_exists( $a['cert_path'] ) ) {
			return $a['cert_path'];
		}

		if ( ! self::es_produccion() ) {
			$prueba = MSP_PLUGIN_DIR . 'certs-prueba/certificado-prueba.pem';
			if ( file_exists( $prueba ) ) {
				return $prueba;
			}
		}

		return '';
	}

	/**
	 * Carpeta donde se conservan XML y CDR.
	 *
	 * La ley obliga a conservarlos. Se guardan en uploads con las protecciones
	 * habituales (index.php y .htaccess), porque es la única ruta escribible
	 * que existe seguro en cualquier hosting. Si el servidor lo permite,
	 * conviene moverla fuera del webroot.
	 *
	 * @return string|WP_Error
	 */
	public static function carpeta_archivos() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'msp_uploads', $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::CARPETA;
		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'msp_mkdir', __( 'No se pudo crear la carpeta de comprobantes.', 'multisede-pos' ) );
		}

		// Que no sean listables ni descargables.
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silencio.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * Construye el cliente de Greenter listo para firmar y enviar.
	 *
	 * @return \Greenter\See|WP_Error
	 */
	public static function see() {
		if ( ! function_exists( 'msp_facturacion_disponible' ) || ! msp_facturacion_disponible() ) {
			return new WP_Error( 'msp_sin_greenter', __( 'El motor de facturación no está disponible: faltan las dependencias del plugin.', 'multisede-pos' ) );
		}

		$cert = self::ruta_certificado();
		if ( ! $cert ) {
			return new WP_Error( 'msp_sin_certificado', __( 'No hay certificado digital configurado.', 'multisede-pos' ) );
		}

		$contenido = file_get_contents( $cert ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $contenido ) {
			return new WP_Error( 'msp_cert_ilegible', __( 'El certificado existe pero no se puede leer. Revisa permisos y propietario del archivo.', 'multisede-pos' ) );
		}

		$a   = self::ajustes();
		$see = new \Greenter\See();
		$see->setCertificate( $contenido );
		$see->setService(
			self::es_produccion()
				? \Greenter\Ws\Services\SunatEndpoints::FE_PRODUCCION
				: \Greenter\Ws\Services\SunatEndpoints::FE_BETA
		);
		// SUNAT espera el RUC y el usuario secundario concatenados.
		$see->setClaveSOL( $a['ruc'], $a['sol_usuario'], $a['sol_clave'] );

		return $see;
	}

	/**
	 * Comprueba las credenciales SOL sin emitir nada.
	 *
	 * El truco: se envía a propósito un archivo que NO es un comprobante. SUNAT
	 * valida primero quién eres y después qué le mandas, así que la respuesta
	 * distingue las dos cosas:
	 *
	 * - Se queja del contenido ("El archivo ZIP no contiene comprobantes") →
	 *   pasó la autenticación: las credenciales son válidas.
	 * - Se queja del usuario o la clave → las credenciales están mal.
	 *
	 * Es seguro incluso en producción: lo enviado no es un documento, así que no
	 * se emite ninguna boleta ni se consume numeración. Vale la pena porque el
	 * sandbox NO valida credenciales (acepta cualquiera), y sin esto las reales
	 * de saraih solo se estrenarían con la primera boleta de verdad.
	 *
	 * @return array {
	 *     @type bool   $ok      True si la autenticación pasó.
	 *     @type string $mensaje Explicación para la pantalla.
	 * }
	 */
	public static function probar_credenciales() {
		if ( ! function_exists( 'msp_facturacion_disponible' ) || ! msp_facturacion_disponible() ) {
			return array(
				'ok'      => false,
				'mensaje' => __( 'El motor de emisión no está disponible: faltan las dependencias del plugin.', 'multisede-pos' ),
			);
		}

		$a = self::ajustes();

		if ( '' === trim( (string) $a['sol_usuario'] ) || '' === trim( (string) $a['sol_clave'] ) ) {
			return array(
				'ok'      => false,
				'mensaje' => __( 'Faltan el usuario o la clave SOL.', 'multisede-pos' ),
			);
		}

		try {
			$cliente = new \Greenter\Ws\Services\SoapClient(
				\Greenter\Ws\Services\WsdlProvider::getBillPath(),
				array(
					'exceptions'         => true,
					'connection_timeout' => 20,
					'stream_context'     => stream_context_create(
						array(
							'ssl'  => array(
								'verify_peer'       => false,
								'verify_peer_name'  => false,
								'allow_self_signed' => true,
							),
							'http' => array( 'timeout' => 25 ),
						)
					),
				)
			);
			$cliente->setService(
				self::es_produccion()
					? \Greenter\Ws\Services\SunatEndpoints::FE_PRODUCCION
					: \Greenter\Ws\Services\SunatEndpoints::FE_BETA
			);
			$cliente->setCredentials( $a['ruc'] . $a['sol_usuario'], $a['sol_clave'] );

			$cliente->call(
				'sendBill',
				array(
					array(
						'fileName'    => $a['ruc'] . '-03-XXXX-0.zip',
						'contentFile' => 'msp-comprobacion-de-credenciales',
					),
				)
			);

			// Si no lanza excepción, algo muy raro pasó: SUNAT debería haberse
			// quejado del contenido. Se toma como no concluyente.
			return array(
				'ok'      => false,
				'mensaje' => __( 'SUNAT respondió de forma inesperada. Reintenta en unos minutos.', 'multisede-pos' ),
			);

		} catch ( \SoapFault $e ) {
			return self::interpretar_fault( $e );
		} catch ( \Exception $e ) {
			return array(
				'ok'      => false,
				'mensaje' => sprintf(
					/* translators: %s: mensaje de error. */
					__( 'No se pudo contactar con SUNAT: %s', 'multisede-pos' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Traduce la respuesta de la prueba de credenciales.
	 *
	 * @param \SoapFault $e Error devuelto por SUNAT.
	 * @return array
	 */
	private static function interpretar_fault( $e ) {
		$codigo  = (string) ( isset( $e->faultcode ) ? $e->faultcode : '' );
		$mensaje = (string) $e->getMessage();
		$texto   = $codigo . ' ' . $mensaje;

		// Quejas sobre el contenido = la autenticación pasó.
		$paso = preg_match( '/0157|0126|0109|ZIP|comprobante/i', $texto );

		// Quejas sobre quién eres.
		$auth = preg_match( '/0102|0103|0105|0110|0111|usuario|contrase|clave|password/i', $texto );

		if ( $auth && ! $paso ) {
			return array(
				'ok'      => false,
				'mensaje' => sprintf(
					/* translators: %s: respuesta literal de SUNAT. */
					__( 'Usuario o clave SOL incorrectos. SUNAT respondió: %s', 'multisede-pos' ),
					$mensaje
				),
			);
		}

		if ( $paso ) {
			// En beta este resultado NO significa nada: el sandbox acepta
			// cualquier credencial. Comprobado enviando usuario y clave
			// inventados, que también "pasan". Decir "válidas" ahí sería mentir,
			// y lo peor que puede hacer esta pantalla es dar una falsa
			// tranquilidad justo sobre el dato que detiene la emisión si falla.
			if ( ! self::es_produccion() ) {
				return array(
					'ok'      => false,
					'mensaje' => __( 'SUNAT respondió, así que hay conexión y el envío llega. Pero esto NO prueba que las credenciales sirvan: el sandbox acepta cualquier usuario y clave, incluso inventados. Esta comprobación solo tiene valor con el entorno en producción.', 'multisede-pos' ),
				);
			}

			return array(
				'ok'      => true,
				'mensaje' => __( 'Credenciales válidas: SUNAT aceptó la identificación y solo objetó el contenido de prueba, que es lo esperado. No se emitió ningún comprobante ni se consumió numeración.', 'multisede-pos' ),
			);
		}

		return array(
			'ok'      => false,
			'mensaje' => sprintf(
				/* translators: %s: respuesta literal de SUNAT. */
				__( 'Respuesta no concluyente de SUNAT: %s', 'multisede-pos' ),
				$texto
			),
		);
	}

	/**
	 * Emite un comprobante ya reservado.
	 *
	 * @param int $comprobante_id ID en wp_msp_comprobantes.
	 * @return array|WP_Error Fila actualizada, o WP_Error.
	 */
	public static function emitir( $comprobante_id ) {
		$c = MSP_Comprobante::obtener( $comprobante_id );
		if ( ! $c ) {
			return new WP_Error( 'msp_no_existe', __( 'El comprobante no existe.', 'multisede-pos' ) );
		}
		if ( 'aceptado' === $c['estado'] ) {
			return new WP_Error( 'msp_ya_aceptado', __( 'Ese comprobante ya fue aceptado por SUNAT.', 'multisede-pos' ) );
		}

		$see = self::see();
		if ( is_wp_error( $see ) ) {
			return self::anotar_fallo( $comprobante_id, $see );
		}

		$invoice = self::armar( $c );
		if ( is_wp_error( $invoice ) ) {
			return self::anotar_fallo( $comprobante_id, $invoice );
		}

		// Fallo simulado, solo fuera de producción: es la única forma de
		// ejercitar la cola de reintentos antes de que exista una venta real.
		// El sandbox de SUNAT no sirve para provocarlo: acepta cualquier clave
		// SOL mientras el usuario sea MODDATOS, así que un envío nunca falla ahí
		// por credenciales.
		if ( ! empty( self::ajustes()['simular_fallo'] ) && ! self::es_produccion() ) {
			return self::anotar_fallo(
				$comprobante_id,
				new WP_Error(
					'msp_fallo_simulado',
					__( 'Fallo de envío simulado (interruptor de pruebas activo en Facturación).', 'multisede-pos' )
				)
			);
		}

		try {
			$xml = $see->getXmlSigned( $invoice );
			$res = $see->send( $invoice );
		} catch ( \Exception $e ) {
			MSP_Comprobante::actualizar(
				$comprobante_id,
				array(
					'estado'       => 'error',
					'ultimo_error' => substr( $e->getMessage(), 0, 1000 ),
				)
			);
			return new WP_Error( 'msp_excepcion', $e->getMessage() );
		}

		$datos = array();

		// Conservar el XML firmado siempre: exista o no CDR, es obligación legal.
		$rutas = self::guardar_archivos( $c, $xml, $res->isSuccess() ? $res->getCdrZip() : null );
		if ( ! is_wp_error( $rutas ) ) {
			$datos['xml_path'] = $rutas['xml'];
			if ( ! empty( $rutas['cdr'] ) ) {
				$datos['cdr_path'] = $rutas['cdr'];
			}
		}

		if ( ! $res->isSuccess() ) {
			$err                   = $res->getError();
			$datos['estado']       = 'error';
			$datos['ultimo_error'] = sprintf( '[%s] %s', $err->getCode(), $err->getMessage() );
			MSP_Comprobante::actualizar( $comprobante_id, $datos );
			return new WP_Error( 'msp_envio', $datos['ultimo_error'] );
		}

		$cdr  = $res->getCdrResponse();
		$code = (string) $cdr->getCode();

		if ( '0' === $code ) {
			$datos['estado']       = 'aceptado';
			$datos['ultimo_error'] = '';
		} else {
			// Rechazo: NO se reintenta a ciegas. Casi siempre es un dato mal
			// puesto, y el correlativo se conserva intacto (SUNAT exige
			// numeración sin saltos). La política de reintento es Fase 3.
			$datos['estado']       = 'rechazado';
			$datos['ultimo_error'] = sprintf( '[%s] %s', $code, $cdr->getDescription() );
		}
		$datos['hash'] = substr( (string) self::hash_de_xml( $xml ), 0, 64 );

		MSP_Comprobante::actualizar( $comprobante_id, $datos );

		return MSP_Comprobante::obtener( $comprobante_id );
	}

	/**
	 * Deja constancia de un fallo ocurrido ANTES de enviar.
	 *
	 * Sin esto, un problema de configuración —certificado ausente, ilegible, o
	 * Greenter a medias— devolvía el error a quien llamara pero no tocaba la
	 * fila: el comprobante se quedaba "En cola" para siempre, sin motivo visible
	 * en pantalla, mientras la cola lo reintentaba en silencio. Quien mirara la
	 * pantalla no tenía forma de saber qué arreglar.
	 *
	 * @param int      $comprobante_id ID del comprobante.
	 * @param WP_Error $error          Error ocurrido.
	 * @return WP_Error El mismo error, para poder devolverlo en cadena.
	 */
	private static function anotar_fallo( $comprobante_id, $error ) {
		MSP_Comprobante::actualizar(
			$comprobante_id,
			array(
				'estado'       => 'error',
				'ultimo_error' => substr( $error->get_error_message(), 0, 1000 ),
			)
		);
		return $error;
	}

	/**
	 * Envía un resumen diario y guarda el ticket que devuelve SUNAT.
	 *
	 * El resumen es asíncrono: SUNAT no responde si lo aceptó, responde un
	 * **ticket** y sigue procesando por su cuenta. El resultado se consulta
	 * después con `consultar_ticket()`. Por eso el estado del resumen distingue
	 * "enviado" (tenemos ticket, falta saber) de "aceptado".
	 *
	 * @param int $resumen_id ID del resumen.
	 * @return array|WP_Error Fila actualizada, o error.
	 */
	public static function enviar_resumen( $resumen_id ) {
		$r = MSP_Resumen::obtener( $resumen_id );
		if ( ! $r ) {
			return new WP_Error( 'msp_no_existe', __( 'El resumen no existe.', 'multisede-pos' ) );
		}
		if ( in_array( $r['estado'], array( 'aceptado', 'rechazado' ), true ) ) {
			return new WP_Error( 'msp_resumen_cerrado', __( 'Ese resumen ya tiene respuesta de SUNAT.', 'multisede-pos' ) );
		}

		$see = self::see();
		if ( is_wp_error( $see ) ) {
			return self::anotar_fallo_resumen( $resumen_id, $see );
		}

		$documento = self::armar_resumen( $r );
		if ( is_wp_error( $documento ) ) {
			return self::anotar_fallo_resumen( $resumen_id, $documento );
		}

		if ( ! empty( self::ajustes()['simular_fallo'] ) && ! self::es_produccion() ) {
			return self::anotar_fallo_resumen(
				$resumen_id,
				new WP_Error( 'msp_fallo_simulado', __( 'Fallo de envío simulado (interruptor de pruebas activo).', 'multisede-pos' ) )
			);
		}

		try {
			$xml = $see->getXmlSigned( $documento );
			$res = $see->send( $documento );
		} catch ( \Exception $e ) {
			return self::anotar_fallo_resumen( $resumen_id, new WP_Error( 'msp_excepcion', $e->getMessage() ) );
		}

		$datos = array( 'enviado_at' => current_time( 'mysql' ) );

		$ruta = self::guardar_archivo_suelto( $r['identificador'], $xml );
		if ( $ruta ) {
			$datos['xml_path'] = $ruta;
		}

		if ( ! $res || ! $res->isSuccess() ) {
			$err = $res ? $res->getError() : null;
			return self::anotar_fallo_resumen(
				$resumen_id,
				new WP_Error(
					'msp_envio',
					$err ? sprintf( '[%s] %s', $err->getCode(), $err->getMessage() ) : __( 'SUNAT no devolvió respuesta.', 'multisede-pos' )
				),
				$datos
			);
		}

		$datos['estado']       = 'enviado';
		$datos['ticket']       = (string) $res->getTicket();
		$datos['ultimo_error'] = '';
		MSP_Resumen::actualizar( $resumen_id, $datos );

		return MSP_Resumen::obtener( $resumen_id );
	}

	/**
	 * Pregunta a SUNAT por el resultado de un resumen ya enviado.
	 *
	 * Códigos de SUNAT: 0 procesado, 98 en proceso, 99 con errores. El 98 no es
	 * un fallo: significa "todavía estoy en ello", y hay que volver a preguntar.
	 *
	 * @param int $resumen_id ID del resumen.
	 * @return array|WP_Error
	 */
	public static function consultar_ticket( $resumen_id ) {
		$r = MSP_Resumen::obtener( $resumen_id );
		if ( ! $r || '' === $r['ticket'] ) {
			return new WP_Error( 'msp_sin_ticket', __( 'Ese resumen todavía no tiene ticket.', 'multisede-pos' ) );
		}

		$see = self::see();
		if ( is_wp_error( $see ) ) {
			return self::anotar_fallo_resumen( $resumen_id, $see );
		}

		try {
			$res = $see->getStatus( $r['ticket'] );
		} catch ( \Exception $e ) {
			return self::anotar_fallo_resumen( $resumen_id, new WP_Error( 'msp_excepcion', $e->getMessage() ) );
		}

		$code = $res ? (string) $res->getCode() : '';

		if ( '98' === $code ) {
			// En proceso. No es error: se vuelve a preguntar más tarde.
			return new WP_Error( 'msp_en_proceso', __( 'SUNAT sigue procesando el resumen.', 'multisede-pos' ) );
		}

		$datos = array();
		$cdr   = method_exists( $res, 'getCdrZip' ) ? $res->getCdrZip() : null;
		if ( $cdr ) {
			$ruta = self::guardar_archivo_suelto( 'R-' . $r['identificador'], $cdr, 'zip' );
			if ( $ruta ) {
				$datos['cdr_path'] = $ruta;
			}
		}

		if ( '0' === $code ) {
			$datos['estado']       = 'aceptado';
			$datos['ultimo_error'] = '';
			$datos['proximo_intento'] = null;
			MSP_Resumen::actualizar( $resumen_id, $datos );
			return MSP_Resumen::obtener( $resumen_id );
		}

		// 99 u otro: SUNAT lo procesó y lo rechazó. No se reintenta a ciegas,
		// igual que con las boletas: el mismo contenido daría el mismo rechazo.
		$err                      = $res ? $res->getError() : null;
		$datos['estado']          = 'rechazado';
		$datos['ultimo_error']    = $err ? sprintf( '[%s] %s', $err->getCode(), $err->getMessage() ) : sprintf( '[%s]', $code );
		$datos['proximo_intento'] = null;
		MSP_Resumen::actualizar( $resumen_id, $datos );

		return new WP_Error( 'msp_resumen_rechazado', $datos['ultimo_error'] );
	}

	/**
	 * Arma el resumen diario de Greenter a partir de la fila y sus comprobantes.
	 *
	 * @param array $r Fila del resumen.
	 * @return \Greenter\Model\Summary\Summary|WP_Error
	 */
	private static function armar_resumen( $r ) {
		$comprobantes = MSP_Resumen::comprobantes( (int) $r['id'] );
		if ( ! $comprobantes ) {
			return new WP_Error( 'msp_resumen_vacio', __( 'El resumen no tiene comprobantes que comunicar.', 'multisede-pos' ) );
		}

		$a       = self::ajustes();
		$empresa = ( new \Greenter\Model\Company\Company() )
			->setRuc( $a['ruc'] )
			->setRazonSocial( $a['razon_social'] );

		$detalles = array();
		foreach ( $comprobantes as $c ) {
			$total = round( (float) $c['total'], 2 );
			$igv   = round( (float) $c['igv'], 2 );

			$detalles[] = ( new \Greenter\Model\Summary\SummaryDetail() )
				->setTipoDoc( '03' )
				->setSerieNro( MSP_Comprobante::numero( $c ) )
				->setEstado( '3' ) // 1 informar, 2 corregir, 3 ANULAR.
				->setClienteTipo( $c['cliente_num_doc'] ? '1' : '0' )
				->setClienteNro( $c['cliente_num_doc'] ? $c['cliente_num_doc'] : '-' )
				->setTotal( $total )
				->setMtoOperGravadas( round( $total - $igv, 2 ) )
				->setMtoIGV( $igv );
		}

		$zona = new DateTimeZone( wp_timezone_string() );

		return ( new \Greenter\Model\Summary\Summary() )
			->setFecGeneracion( new DateTime( 'now', $zona ) )
			->setFecResumen( new DateTime( $r['fecha_referencia'], $zona ) )
			->setCorrelativo( (string) (int) $r['correlativo'] )
			->setMoneda( 'PEN' )
			->setCompany( $empresa )
			->setDetails( $detalles );
	}

	/**
	 * Guarda un archivo en la carpeta de conservación.
	 *
	 * @param string $nombre    Nombre base.
	 * @param string $contenido Contenido.
	 * @param string $ext       Extensión.
	 * @return string Ruta guardada, o cadena vacía.
	 */
	private static function guardar_archivo_suelto( $nombre, $contenido, $ext = 'xml' ) {
		$dir = self::carpeta_archivos();
		if ( is_wp_error( $dir ) || ! $contenido ) {
			return '';
		}

		$a    = self::ajustes();
		$ruta = $dir . '/' . $a['ruc'] . '-' . sanitize_file_name( $nombre ) . '.' . $ext;

		return file_put_contents( $ruta, $contenido ) ? $ruta : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Deja constancia de un fallo en un resumen.
	 *
	 * @param int      $resumen_id ID del resumen.
	 * @param WP_Error $error      Error.
	 * @param array    $extra      Campos extra a guardar.
	 * @return WP_Error
	 */
	private static function anotar_fallo_resumen( $resumen_id, $error, $extra = array() ) {
		MSP_Resumen::actualizar(
			$resumen_id,
			array_merge(
				$extra,
				array(
					'estado'       => 'error',
					'ultimo_error' => substr( $error->get_error_message(), 0, 1000 ),
				)
			)
		);
		return $error;
	}

	/**
	 * Extrae el valor de la firma del XML, para el QR de la Fase 5.
	 *
	 * @param string $xml XML firmado.
	 * @return string
	 */
	public static function hash_de_xml( $xml ) {
		if ( ! $xml ) {
			return '';
		}
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		if ( ! $doc->loadXML( $xml ) ) {
			libxml_clear_errors();
			return '';
		}
		libxml_clear_errors();
		$nodos = $doc->getElementsByTagName( 'DigestValue' );
		return $nodos->length ? trim( $nodos->item( 0 )->nodeValue ) : '';
	}

	/**
	 * Guarda XML y CDR en disco.
	 *
	 * @param array       $c   Fila del comprobante.
	 * @param string      $xml XML firmado.
	 * @param string|null $cdr ZIP del CDR, si lo hay.
	 * @return array|WP_Error
	 */
	private static function guardar_archivos( $c, $xml, $cdr ) {
		$dir = self::carpeta_archivos();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$a      = self::ajustes();
		// Mismo número que va dentro del XML y que muestra el panel: si el archivo
		// se llamara "B001-5" y el documento dijera "B001-00000005", buscar un
		// comprobante en la carpeta de conservación sería un acertijo.
		$nombre = sprintf( '%s-03-%s-%08d', $a['ruc'], $c['serie'], (int) $c['correlativo'] );
		$rutas  = array( 'xml' => '', 'cdr' => '' );

		$ruta_xml = $dir . '/' . $nombre . '.xml';
		if ( file_put_contents( $ruta_xml, $xml ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$rutas['xml'] = $ruta_xml;
		}

		if ( $cdr ) {
			$ruta_cdr = $dir . '/R-' . $nombre . '.zip';
			if ( file_put_contents( $ruta_cdr, $cdr ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$rutas['cdr'] = $ruta_cdr;
			}
		}

		return $rutas;
	}

	/**
	 * Arma el objeto de Greenter desde la fila y su pedido.
	 *
	 * @param array $c Fila del comprobante.
	 * @return \Greenter\Model\Sale\Invoice|WP_Error
	 */
	private static function armar( $c ) {
		$a = self::ajustes();

		$direccion = ( new \Greenter\Model\Company\Address() )
			->setUbigueo( $a['ubigeo'] ? $a['ubigeo'] : '150101' )
			->setDepartamento( $a['departamento'] ? $a['departamento'] : 'LIMA' )
			->setProvincia( $a['provincia'] ? $a['provincia'] : 'LIMA' )
			->setDistrito( $a['distrito'] ? $a['distrito'] : 'LIMA' )
			->setDireccion( $a['direccion'] ? $a['direccion'] : '-' )
			->setCodLocal( self::codigo_local( $c['sede_id'] ) );

		$empresa = ( new \Greenter\Model\Company\Company() )
			->setRuc( $a['ruc'] )
			->setRazonSocial( $a['razon_social'] )
			->setAddress( $direccion );

		// Por defecto, consumidor final. La captura de DNI llega en Fase 3.
		$cliente = ( new \Greenter\Model\Client\Client() )
			->setTipoDoc( $c['cliente_tipo_doc'] ? $c['cliente_tipo_doc'] : '0' )
			->setNumDoc( $c['cliente_num_doc'] ? $c['cliente_num_doc'] : '-' )
			->setRznSocial( $c['cliente_nombre'] ? $c['cliente_nombre'] : 'CLIENTE VARIOS' );

		$lineas = self::lineas( $c );
		if ( is_wp_error( $lineas ) ) {
			return $lineas;
		}

		$gravadas = 0;
		$igv      = 0;
		foreach ( $lineas as $l ) {
			$gravadas += $l->getMtoValorVenta();
			$igv      += $l->getIgv();
		}
		$gravadas = round( $gravadas, 2 );
		$igv      = round( $igv, 2 );
		$total    = round( $gravadas + $igv, 2 );

		return ( new \Greenter\Model\Sale\Invoice() )
			->setUblVersion( '2.1' )
			->setTipoOperacion( '0101' )
			->setTipoDoc( '03' )
			->setSerie( $c['serie'] )
			// Correlativo a 8 dígitos, el mismo formato que muestra el panel y que
			// irá impreso en el ticket. SUNAT acepta ambas formas, pero sin el
			// relleno el documento queda registrado como "B001-2" mientras que
			// aquí se lee "B001-00000002": dos números para el mismo documento,
			// y el desajuste solo aparece cuando alguien lo busca en SOL o el
			// contador cruza el registro de ventas.
			->setCorrelativo( sprintf( '%08d', (int) $c['correlativo'] ) )
			->setFechaEmision( new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) ) )
			->setTipoMoneda( 'PEN' )
			->setCompany( $empresa )
			->setClient( $cliente )
			->setMtoOperGravadas( $gravadas )
			->setMtoIGV( $igv )
			->setTotalImpuestos( $igv )
			->setValorVenta( $gravadas )
			->setSubTotal( $total )
			->setMtoImpVenta( $total )
			->setDetails( $lineas )
			->setLegends(
				array(
					( new \Greenter\Model\Sale\Legend() )
						->setCode( '1000' )
						->setValue( self::monto_en_letras( $total ) ),
				)
			);
	}

	/**
	 * Construye las líneas del comprobante desde el pedido.
	 *
	 * @param array $c Fila del comprobante.
	 * @return array|WP_Error
	 */
	private static function lineas( $c ) {
		$pedido = $c['pedido_id'] ? wc_get_order( (int) $c['pedido_id'] ) : null;

		// Sin pedido (emisión de prueba): una línea con el total de la fila.
		if ( ! $pedido ) {
			$total = (float) $c['total'];
			if ( $total <= 0 ) {
				return new WP_Error( 'msp_sin_lineas', __( 'El comprobante no tiene pedido ni total con el que armar la boleta.', 'multisede-pos' ) );
			}
			return array( self::linea( 'PRUEBA', __( 'Producto de prueba', 'multisede-pos' ), 1, $total ) );
		}

		$lineas = array();
		foreach ( $pedido->get_items() as $item ) {
			$cantidad = (int) $item->get_quantity();
			if ( $cantidad < 1 ) {
				continue;
			}
			$producto = $item->get_product();
			$sku      = $producto ? $producto->get_sku() : '';
			// El total de la línea YA incluye IGV: los precios de la tienda son
			// con impuesto incluido.
			$total_linea = (float) $item->get_total() + (float) $item->get_total_tax();
			$lineas[]    = self::linea(
				$sku ? $sku : (string) $item->get_product_id(),
				$item->get_name(),
				$cantidad,
				$total_linea
			);
		}

		if ( ! $lineas ) {
			return new WP_Error( 'msp_pedido_vacio', __( 'El pedido no tiene líneas que facturar.', 'multisede-pos' ) );
		}

		return $lineas;
	}

	/**
	 * Crea una línea a partir de su total CON IGV.
	 *
	 * El IGV se calcula como (total − base) y no como (base × 0.18). Con la
	 * segunda fórmula, precios enteros como 6, 10 o 15 soles descuadran un
	 * céntimo al redondear, y SUNAT rechaza el comprobante por ello: pasa en 9
	 * de los 44 precios del catálogo de saraih.
	 *
	 * @param string $codigo      Código o SKU.
	 * @param string $descripcion Descripción.
	 * @param int    $cantidad    Unidades.
	 * @param float  $total_linea Total de la línea CON IGV.
	 * @return \Greenter\Model\Sale\SaleDetail
	 */
	private static function linea( $codigo, $descripcion, $cantidad, $total_linea ) {
		$total_linea = round( (float) $total_linea, 2 );
		$base        = round( $total_linea / 1.18, 2 );
		$igv         = round( $total_linea - $base, 2 );
		$unitario    = $cantidad > 0 ? round( $base / $cantidad, 10 ) : $base;

		return ( new \Greenter\Model\Sale\SaleDetail() )
			->setCodProducto( substr( (string) $codigo, 0, 30 ) )
			->setUnidad( 'NIU' )
			->setCantidad( $cantidad )
			->setDescripcion( substr( wp_strip_all_tags( (string) $descripcion ), 0, 250 ) )
			->setMtoBaseIgv( $base )
			->setPorcentajeIgv( 18 )
			->setIgv( $igv )
			->setTipAfeIgv( '10' )
			->setTotalImpuestos( $igv )
			->setMtoValorVenta( $base )
			->setMtoValorUnitario( $unitario )
			->setMtoPrecioUnitario( $cantidad > 0 ? round( $total_linea / $cantidad, 10 ) : $total_linea );
	}

	/**
	 * Código de establecimiento anexo de la sede.
	 *
	 * @param int $sede_id Sede.
	 * @return string
	 */
	public static function codigo_local( $sede_id ) {
		$codigo = get_post_meta( (int) $sede_id, '_msp_codigo_anexo', true );
		return $codigo ? substr( sanitize_text_field( $codigo ), 0, 4 ) : '0000';
	}

	/**
	 * Monto en letras para la leyenda obligatoria.
	 *
	 * @param float $monto Monto.
	 * @return string
	 */
	public static function monto_en_letras( $monto ) {
		$entero   = (int) floor( $monto );
		$centimos = (int) round( ( $monto - $entero ) * 100 );
		return sprintf( '%s CON %02d/100 SOLES', self::numero_a_letras( $entero ), $centimos );
	}

	/**
	 * Convierte un entero a letras (hasta millones), en mayúsculas.
	 *
	 * @param int $n Número.
	 * @return string
	 */
	private static function numero_a_letras( $n ) {
		$n = (int) $n;
		if ( 0 === $n ) {
			return 'CERO';
		}
		if ( $n < 0 ) {
			return 'MENOS ' . self::numero_a_letras( abs( $n ) );
		}

		$unidades = array( '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE' );
		$decenas  = array( 3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA', 7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA' );
		$centenas = array( 1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS', 6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS' );

		if ( $n <= 20 ) {
			return $unidades[ $n ];
		}
		if ( $n < 30 ) {
			return 'VEINTI' . $unidades[ $n - 20 ];
		}
		if ( $n < 100 ) {
			$d = (int) floor( $n / 10 );
			$u = $n % 10;
			return $decenas[ $d ] . ( $u ? ' Y ' . $unidades[ $u ] : '' );
		}
		if ( 100 === $n ) {
			return 'CIEN';
		}
		if ( $n < 1000 ) {
			$c    = (int) floor( $n / 100 );
			$rest = $n % 100;
			return $centenas[ $c ] . ( $rest ? ' ' . self::numero_a_letras( $rest ) : '' );
		}
		if ( $n < 1000000 ) {
			$miles = (int) floor( $n / 1000 );
			$rest  = $n % 1000;
			$pref  = 1 === $miles ? 'MIL' : self::numero_a_letras( $miles ) . ' MIL';
			return $pref . ( $rest ? ' ' . self::numero_a_letras( $rest ) : '' );
		}

		$millones = (int) floor( $n / 1000000 );
		$rest     = $n % 1000000;
		$pref     = 1 === $millones ? 'UN MILLON' : self::numero_a_letras( $millones ) . ' MILLONES';
		return $pref . ( $rest ? ' ' . self::numero_a_letras( $rest ) : '' );
	}
}
