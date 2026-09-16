<?php
/**
 * Salida ESC/POS del ticket, para impresoras que no se ven desde el sistema
 * de impresión de Android.
 *
 * Por qué existe: en la iMin Falcon 1 la impresora térmica integrada no aparece
 * en el diálogo de Chrome, así que el `window.print()` del ticket HTML no llega
 * a ninguna parte (ni con un print service genérico instalado). La vía que sí
 * funciona es armar aquí los comandos ESC/POS y entregárselos a RawBT por su
 * esquema de URL: el navegador no imprime, solo pasa el trabajo ya hecho.
 *
 * El ticket HTML de `MSP_Ticket` se queda como está y sigue siendo el camino
 * normal en escritorio. Esto es una segunda salida, no un reemplazo.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compone el ticket como flujo de comandos ESC/POS.
 */
class MSP_Ticket_EscPos {

	const ESC = "\x1b";
	const GS  = "\x1d";

	/**
	 * Páginas de códigos que entiende el comando `ESC t n`.
	 *
	 * Es el ajuste que decide si la Ñ y las tildes salen bien o salen como
	 * basura, y cambia de una impresora a otra aunque ambas digan ser ESC/POS.
	 * Por eso es configurable y no una constante.
	 *
	 * @return array clave => array( etiqueta, n del comando, destino de iconv )
	 */
	public static function paginas_codigos() {
		return array(
			'cp850'   => array( 'CP850 (multilingüe)', 2, 'CP850' ),
			'cp858'   => array( 'CP858 (CP850 con €)', 19, 'CP858' ),
			'win1252' => array( 'Windows-1252', 16, 'CP1252' ),
			'cp437'   => array( 'CP437 (EE. UU.)', 0, 'CP437' ),
			'utf8'    => array( 'UTF-8 sin convertir', null, '' ),
		);
	}

	/**
	 * Ajustes de impresión, con sus valores por defecto.
	 *
	 * Viven en la misma opción que el resto de la facturación.
	 *
	 * @return array
	 */
	public static function ajustes() {
		$a = MSP_Emisor::ajustes();

		return array(
			'activo'   => ! empty( $a['escpos_activo'] ),
			'columnas' => isset( $a['escpos_columnas'] ) ? max( 24, min( 64, (int) $a['escpos_columnas'] ) ) : 42,
			'codepage' => isset( $a['escpos_codepage'] ) && isset( self::paginas_codigos()[ $a['escpos_codepage'] ] )
				? $a['escpos_codepage']
				: 'cp850',
			'cortar'   => isset( $a['escpos_cortar'] ) ? (int) $a['escpos_cortar'] : 1,
			'copias'   => isset( $a['escpos_copias'] ) ? max( 1, min( 3, (int) $a['escpos_copias'] ) ) : 1,
			'auto'     => ! empty( $a['escpos_auto'] ),
			'qr_nativo' => isset( $a['escpos_qr_nativo'] ) ? (int) $a['escpos_qr_nativo'] : 1,
		);
	}

	/**
	 * ¿Está encendida esta salida?
	 *
	 * @return bool
	 */
	public static function activo() {
		$a = self::ajustes();
		return $a['activo'];
	}

	/**
	 * Convierte el texto a la página de códigos de la impresora.
	 *
	 * Con `//TRANSLIT` para que un carácter que no exista en la página salga
	 * como su aproximación (á → a) en vez de desaparecer o cortar la línea.
	 *
	 * @param string $texto Texto en UTF-8.
	 * @return string
	 */
	private static function codificar( $texto ) {
		$a  = self::ajustes();
		$cp = self::paginas_codigos()[ $a['codepage'] ];

		if ( ! $cp[2] || ! function_exists( 'iconv' ) ) {
			return $texto;
		}

		$convertido = @iconv( 'UTF-8', $cp[2] . '//TRANSLIT', $texto ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv avisa por carácter no convertible; el fallback cubre el caso.
		return false === $convertido ? $texto : $convertido;
	}

	/**
	 * Parte un texto en líneas del ancho del papel, sin cortar palabras.
	 *
	 * @param string $texto Texto.
	 * @param int    $cols  Ancho en caracteres.
	 * @return array
	 */
	private static function envolver( $texto, $cols ) {
		$texto = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $texto ) ) );
		if ( '' === $texto ) {
			return array();
		}
		return explode( "\n", wordwrap( $texto, $cols, "\n", true ) );
	}

	/**
	 * Línea con algo a la izquierda y algo a la derecha, rellenando el medio.
	 *
	 * Si no caben los dos, manda el de la derecha: es el importe.
	 *
	 * @param string $izq  Texto izquierdo.
	 * @param string $der  Texto derecho.
	 * @param int    $cols Ancho.
	 * @return string
	 */
	private static function lr( $izq, $der, $cols ) {
		$izq   = (string) $izq;
		$der   = (string) $der;
		$hueco = $cols - mb_strlen( $der ) - 1;

		if ( $hueco < 1 ) {
			return str_pad( mb_substr( $der, 0, $cols ), $cols, ' ', STR_PAD_LEFT ) . "\n";
		}
		if ( mb_strlen( $izq ) > $hueco ) {
			$izq = mb_substr( $izq, 0, $hueco );
		}

		return $izq . str_repeat( ' ', $cols - mb_strlen( $izq ) - mb_strlen( $der ) ) . $der . "\n";
	}

	/**
	 * Centra una línea.
	 *
	 * @param string $texto Texto.
	 * @param int    $cols  Ancho.
	 * @return string
	 */
	private static function centrar( $texto, $cols ) {
		$lineas = self::envolver( $texto, $cols );
		$salida = '';
		foreach ( $lineas as $l ) {
			$pad     = (int) floor( ( $cols - mb_strlen( $l ) ) / 2 );
			$salida .= str_repeat( ' ', max( 0, $pad ) ) . $l . "\n";
		}
		return $salida;
	}

	/**
	 * El QR por comando nativo de la impresora (`GS ( k`).
	 *
	 * Se imprime más nítido y ocupa unas decenas de bytes, frente a mandar un
	 * bitmap. La cadena es la misma que compone `MSP_Ticket::cadena_qr()`: la
	 * que exige SUNAT, campo por campo.
	 *
	 * @param string $texto Contenido del QR.
	 * @return string
	 */
	private static function qr( $texto ) {
		$len = strlen( $texto ) + 3;
		$pl  = chr( $len % 256 );
		$ph  = chr( (int) floor( $len / 256 ) );

		return self::GS . '(k' . chr( 4 ) . chr( 0 ) . '1A' . chr( 50 ) . chr( 0 )   // Modelo 2.
			. self::GS . '(k' . chr( 3 ) . chr( 0 ) . '1C' . chr( 6 )                // Tamaño del módulo.
			. self::GS . '(k' . chr( 3 ) . chr( 0 ) . '1E' . chr( 49 )               // Corrección M, la misma del SVG.
			. self::GS . '(k' . $pl . $ph . '1P0' . $texto                           // Cargar datos.
			. self::GS . '(k' . chr( 3 ) . chr( 0 ) . '1Q0';                         // Imprimir.
	}

	/**
	 * Arma el ticket completo en ESC/POS.
	 *
	 * Devuelve cadena vacía si el comprobante todavía no tiene el hash de la
	 * firma: sin él el QR no lo reconoce el verificador de SUNAT, y un QR que
	 * no verifica es peor que ninguno. Mismo criterio que el ticket HTML.
	 *
	 * @param array $c Fila del comprobante.
	 * @return string Flujo de bytes, o '' si aún no se puede imprimir.
	 */
	public static function comandos( $c ) {
		if ( empty( $c['hash'] ) ) {
			return '';
		}

		$aj        = self::ajustes();
		$cols      = $aj['columnas'];
		$a         = MSP_Emisor::ajustes_de_comprobante( $c );
		$sede      = get_post( (int) $c['sede_id'] );
		$direccion = $sede ? get_post_meta( $sede->ID, '_msp_direccion', true ) : '';
		$total     = (float) $c['total'];
		$igv       = (float) $c['igv'];
		$base      = round( $total - $igv, 2 );
		$regla     = str_repeat( '-', $cols ) . "\n";

		$t = '';

		// Cabecera, centrada y en negrita el nombre de la empresa.
		$t .= self::ESC . 'a' . chr( 1 );
		$t .= self::ESC . 'E' . chr( 1 ) . self::centrar( strtoupper( $a['razon_social'] ), $cols ) . self::ESC . 'E' . chr( 0 );
		$t .= self::centrar( 'RUC ' . $a['ruc'], $cols );
		if ( $sede ) {
			$t .= self::centrar( $sede->post_title, $cols );
		}
		if ( $direccion ) {
			$t .= self::centrar( $direccion, $cols );
		}

		$t .= "\n";
		$t .= self::ESC . 'E' . chr( 1 );
		// Sin tildes a propósito: el encabezado va en mayúsculas y algunas
		// páginas de códigos no traen la Ó acentuada en versales.
		$t .= self::centrar(
			'factura' === MSP_Comprobante::tipo_valido( $c['tipo'] ) ? 'FACTURA ELECTRONICA' : 'BOLETA DE VENTA ELECTRONICA',
			$cols
		);
		$t .= self::centrar( MSP_Comprobante::numero( $c ), $cols );
		$t .= self::ESC . 'E' . chr( 0 );

		if ( 'anulado' === $c['baja_estado'] ) {
			$t .= self::ESC . 'E' . chr( 1 ) . self::centrar( '*** ANULADA ***', $cols ) . self::ESC . 'E' . chr( 0 );
		} elseif ( in_array( $c['baja_estado'], array( 'enviada', 'pendiente' ), true ) ) {
			$t .= self::ESC . 'E' . chr( 1 ) . self::centrar( '*** BAJA EN TRAMITE ***', $cols ) . self::ESC . 'E' . chr( 0 );
		}

		// El cuerpo va alineado a la izquierda.
		$t .= self::ESC . 'a' . chr( 0 );
		$t .= $regla;
		$t .= self::lr( 'Fecha', gmdate( 'd/m/Y H:i', strtotime( $c['emitido_at'] ) ), $cols );
		if ( $c['cliente_nombre'] ) {
			foreach ( self::envolver( 'Cliente: ' . $c['cliente_nombre'], $cols ) as $l ) {
				$t .= $l . "\n";
			}
		}
		if ( $c['cliente_num_doc'] ) {
			$etiqueta = 'factura' === MSP_Comprobante::tipo_valido( $c['tipo'] ) ? 'RUC' : 'DNI';
			$t       .= self::lr( $etiqueta, $c['cliente_num_doc'], $cols );
		}
		$t .= $regla;

		// Las líneas del pedido: descripción completa arriba, cantidad e
		// importe debajo. Con los nombres largos del catálogo real, ponerlo
		// todo en una línea recortaría justo lo que distingue un producto de
		// otro.
		$lineas = self::lineas( $c );
		if ( $lineas ) {
			foreach ( $lineas as $l ) {
				foreach ( self::envolver( $l['descripcion'], $cols ) as $fila ) {
					$t .= $fila . "\n";
				}
				$t .= self::lr(
					'  ' . $l['cantidad'] . ' x ' . number_format( $l['importe'] / max( 1, $l['cantidad'] ), 2 ),
					number_format( $l['importe'], 2 ),
					$cols
				);
			}
		} else {
			$t .= self::lr( 'Venta', number_format( $total, 2 ), $cols );
		}

		$t .= $regla;
		$t .= self::lr( 'Op. gravada', 'S/ ' . number_format( $base, 2 ), $cols );
		$t .= self::lr( 'IGV (18%)', 'S/ ' . number_format( $igv, 2 ), $cols );
		$t .= self::ESC . 'E' . chr( 1 );
		$t .= self::lr( 'TOTAL', 'S/ ' . number_format( $total, 2 ), $cols );
		$t .= self::ESC . 'E' . chr( 0 );
		$t .= "\n";

		foreach ( self::envolver( MSP_Emisor::monto_en_letras( $total ), $cols ) as $l ) {
			$t .= $l . "\n";
		}

		// Hasta aquí es texto: se convierte a la página de códigos de la
		// impresora. El QR va después porque son bytes, no texto, y pasarlo
		// por iconv lo destruiría.
		$salida = self::ESC . '@';                       // Reiniciar la impresora.
		$cp     = self::paginas_codigos()[ $aj['codepage'] ];
		if ( null !== $cp[1] ) {
			$salida .= self::ESC . 't' . chr( $cp[1] );
		}
		$salida .= self::codificar( $t );

		if ( $aj['qr_nativo'] ) {
			$salida .= self::ESC . 'a' . chr( 1 ) . self::qr( MSP_Ticket::cadena_qr( $c ) ) . "\n";
		}

		$pie = 'factura' === MSP_Comprobante::tipo_valido( $c['tipo'] )
			? self::centrar( 'Representacion impresa de la', $cols ) . self::centrar( 'factura electronica.', $cols )
			: self::centrar( 'Representacion impresa de la boleta', $cols ) . self::centrar( 'de venta electronica.', $cols );
		$pie .= self::centrar( 'Consultala en www.sunat.gob.pe', $cols );
		if ( ! MSP_Emisor::es_produccion() ) {
			$pie .= self::centrar( '*** DOCUMENTO DE PRUEBA ***', $cols );
			$pie .= self::centrar( '*** SIN VALOR ***', $cols );
		}

		$salida .= self::ESC . 'a' . chr( 1 ) . self::codificar( $pie );
		$salida .= self::ESC . 'a' . chr( 0 ) . "\n\n\n";

		if ( $aj['cortar'] ) {
			$salida .= self::GS . 'V' . chr( 66 ) . chr( 0 ); // Corte parcial con avance.
		}

		// Las copias se repiten enteras: es lo que espera quien pide dos.
		if ( $aj['copias'] > 1 ) {
			$salida = str_repeat( $salida, $aj['copias'] );
		}

		return $salida;
	}

	/**
	 * Líneas del pedido.
	 *
	 * @param array $c Fila del comprobante.
	 * @return array
	 */
	private static function lineas( $c ) {
		$lineas = array();

		if ( empty( $c['pedido_id'] ) || ! function_exists( 'wc_get_order' ) ) {
			return $lineas;
		}

		$order = wc_get_order( (int) $c['pedido_id'] );
		if ( ! $order ) {
			return $lineas;
		}

		foreach ( $order->get_items() as $item ) {
			$lineas[] = array(
				'descripcion' => $item->get_name(),
				'cantidad'    => (int) $item->get_quantity(),
				'importe'     => (float) $order->get_line_total( $item, true ),
			);
		}

		return $lineas;
	}

	/**
	 * El ticket en base64, que es como lo recibe RawBT.
	 *
	 * @param array $c Fila del comprobante.
	 * @return string Vacío si el comprobante todavía no se puede imprimir.
	 */
	public static function base64( $c ) {
		$bytes = self::comandos( $c );
		return $bytes ? base64_encode( $bytes ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Es el formato que exige el esquema de RawBT.
	}
}
