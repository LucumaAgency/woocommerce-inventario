<?php
/**
 * Ticket: la representación impresa de la boleta electrónica.
 *
 * Fase 5. Lo que SUNAT tiene es el XML; lo que el cliente se lleva es este
 * papel. La ley exige que lleve el QR con los datos del comprobante, para que
 * cualquiera pueda verificar en la web de SUNAT que la boleta existe.
 *
 * El PDF no se genera en el servidor a propósito: el ticket es una página HTML
 * pensada para imprimirse en papel de 80 mm, y el propio navegador la manda a la
 * impresora térmica o la guarda como PDF. Meter una librería de PDF (o peor, el
 * binario de wkhtmltopdf, descontinuado) sería cargar megas y una dependencia
 * frágil para hacer lo que el navegador ya hace bien.
 *
 * Excepción: las impresoras que no se ven desde el sistema de impresión de
 * Android (la iMin Falcon 1, donde el diálogo de Chrome no las ofrece). Para
 * ellas el mismo ticket sale también como comandos ESC/POS, que se entregan a
 * RawBT desde el botón de abajo. Esa parte vive en `MSP_Ticket_EscPos`.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Genera el QR y pinta el ticket imprimible.
 */
class MSP_Ticket {

	/** Acción de admin-post que sirve el ticket. */
	const ACTION = 'msp_ticket';

	/** Acción de admin-post que sirve el ticket de prueba. */
	const ACTION_PRUEBA = 'msp_ticket_prueba';

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'servir' ) );
		add_action( 'admin_post_' . self::ACTION_PRUEBA, array( $this, 'servir_prueba' ) );
	}

	/**
	 * URL del ticket de prueba, sin la sede: el POS le añade `&sede=` con la
	 * que esté elegida en el momento.
	 *
	 * @return string
	 */
	public static function url_prueba() {
		// add_query_arg y no wp_nonce_url: esta devuelve la URL escapada
		// (&amp;), y el JS le concatena la sede y la abre tal cual.
		return add_query_arg(
			array(
				'action'   => self::ACTION_PRUEBA,
				'_wpnonce' => wp_create_nonce( self::ACTION_PRUEBA ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Comprobante ficticio para probar la impresora.
	 *
	 * No se guarda en ninguna tabla, no pasa por la cola ni por SUNAT y no
	 * reserva correlativo: la numeración real no tiene huecos que explicar. Va
	 * con la serie de la sede y correlativo 0 para que el papel tenga el mismo
	 * largo que uno de verdad, y lleva QR (con datos inventados) para probar
	 * que la impresora lo saca legible.
	 *
	 * @param int $sede_id Sede desde la que se prueba.
	 * @return array
	 */
	public static function comprobante_prueba( $sede_id ) {
		$serie = MSP_Comprobante::serie_de_sede( $sede_id, 'boleta' );

		return array(
			'id'               => 0,
			'prueba'           => true,
			'pedido_id'        => 0,
			'sede_id'          => (int) $sede_id,
			'ruc'              => MSP_Emisor::ruc_de_sede( $sede_id ),
			'tipo'             => 'boleta',
			'entorno'          => MSP_Comprobante::entorno_actual(),
			'serie'            => $serie ? $serie : 'B000',
			'correlativo'      => 0,
			'cliente_tipo_doc' => '0',
			'cliente_num_doc'  => '',
			'cliente_nombre'   => 'CLIENTE DE PRUEBA',
			'total'            => 10.00,
			'igv'              => 1.53,
			'estado'           => 'prueba',
			'hash'             => 'PRUEBA',
			'baja_estado'      => '',
			'emitido_at'       => current_time( 'mysql' ),
			'lineas_prueba'    => array(
				array(
					'descripcion' => 'PRODUCTO DE PRUEBA',
					'cantidad'    => 1,
					'importe'     => 10.00,
				),
			),
		);
	}

	/**
	 * Sirve el ticket de prueba.
	 */
	public function servir_prueba() {
		check_admin_referer( self::ACTION_PRUEBA );

		if ( ! $this->puede() ) {
			wp_die( esc_html__( 'Sin permiso para imprimir tickets.', 'multisede-pos' ) );
		}

		$sede_id = isset( $_GET['sede'] ) ? absint( wp_unslash( $_GET['sede'] ) ) : 0;
		if ( ! $sede_id || 'msp_sede' !== get_post_type( $sede_id ) || ! MSP_Roles::puede_usuario_sede( $sede_id ) ) {
			wp_die( esc_html__( 'No tienes acceso a esa sede.', 'multisede-pos' ) );
		}

		$this->render( self::comprobante_prueba( $sede_id ) );
		exit;
	}

	/**
	 * URL del ticket de un comprobante.
	 *
	 * @param int $comprobante_id ID del comprobante.
	 * @return string
	 */
	public static function url( $comprobante_id, $auto = false ) {
		$args = array(
			'action'      => self::ACTION,
			'comprobante' => (int) $comprobante_id,
		);

		// `auto=1` hace que la página dispare sola la impresión por RawBT. Lo
		// usa el POS: en mostrador, con el cliente delante, un clic menos por
		// venta se nota.
		if ( $auto ) {
			$args['auto'] = 1;
		}

		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin-post.php' ) ),
			self::ACTION . '_' . (int) $comprobante_id
		);
	}

	/**
	 * Cadena que va dentro del QR.
	 *
	 * SUNAT no da ningún helper: hay que componerla campo por campo, separados
	 * por barras y en este orden exacto. Un campo de más o de menos y el
	 * verificador de SUNAT no reconoce el comprobante.
	 *
	 * RUC | tipo | serie | correlativo | IGV | total | fecha | tipo doc cliente |
	 * nro doc cliente | hash de la firma
	 *
	 * El tipo sale del comprobante ('03' boleta, '01' factura): un QR que
	 * declara boleta sobre una factura no lo reconoce el verificador.
	 *
	 * @param array $c Fila del comprobante.
	 * @return string
	 */
	public static function cadena_qr( $c ) {
		$a = MSP_Emisor::ajustes_de_comprobante( $c );

		return implode(
			'|',
			array(
				$a['ruc'],
				MSP_Comprobante::codigo_sunat( $c ),
				$c['serie'],
				(int) $c['correlativo'],
				number_format( (float) $c['igv'], 2, '.', '' ),
				number_format( (float) $c['total'], 2, '.', '' ),
				gmdate( 'Y-m-d', strtotime( $c['emitido_at'] ) ),
				// Tipo de documento del comprador (catálogo 06), no un '1' fijo:
				// en una factura es RUC ('6') y el verificador de SUNAT compara
				// este campo con el del XML. Si no coinciden, no reconoce el
				// comprobante aunque todo lo demás esté bien.
				$c['cliente_num_doc'] ? ( $c['cliente_tipo_doc'] ? $c['cliente_tipo_doc'] : '1' ) : '0',
				$c['cliente_num_doc'] ? $c['cliente_num_doc'] : '-',
				$c['hash'],
			)
		);
	}

	/**
	 * Dibuja el QR como SVG en línea.
	 *
	 * SVG y no PNG: no depende de GD ni de Imagick (que faltan en muchos
	 * hostings compartidos), se imprime nítido a cualquier tamaño y viaja dentro
	 * del propio HTML, sin un archivo que servir ni que conservar.
	 *
	 * @param string $texto Contenido del QR.
	 * @return string SVG, o cadena vacía si no se pudo generar.
	 */
	public static function qr_svg( $texto ) {
		if ( ! class_exists( '\\chillerlan\\QRCode\\QRCode' ) ) {
			return '';
		}

		try {
			$opciones = new \chillerlan\QRCode\QROptions(
				array(
					'outputType'          => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
					'eccLevel'            => \chillerlan\QRCode\QRCode::ECC_M,
					'imageBase64'         => false,
					'svgUseFillAttributes' => false,
					'addQuietzone'        => true,
				)
			);
			return ( new \chillerlan\QRCode\QRCode( $opciones ) )->render( $texto );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * ¿Puede el usuario actual imprimir tickets?
	 *
	 * El cajero tiene que poder: es quien entrega el papel al cliente.
	 *
	 * @return bool
	 */
	private function puede() {
		return current_user_can( 'msp_usar_pos' ) || current_user_can( 'msp_ver_reportes' );
	}

	/**
	 * Sirve la página del ticket.
	 */
	public function servir() {
		$id = isset( $_GET['comprobante'] ) ? absint( wp_unslash( $_GET['comprobante'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( self::ACTION . '_' . $id );

		if ( ! $this->puede() ) {
			wp_die( esc_html__( 'Sin permiso para imprimir tickets.', 'multisede-pos' ) );
		}

		$c = MSP_Comprobante::obtener( $id );
		if ( ! $c ) {
			wp_die( esc_html__( 'Ese comprobante no existe.', 'multisede-pos' ) );
		}

		$this->render( $c );
		exit;
	}

	/**
	 * Líneas del ticket, tomadas del pedido.
	 *
	 * @param array $c Fila del comprobante.
	 * @return array Lista de {descripcion, cantidad, importe}.
	 */
	private function lineas( $c ) {
		if ( ! empty( $c['lineas_prueba'] ) ) {
			return $c['lineas_prueba'];
		}

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
	 * Pinta el ticket.
	 *
	 * @param array $c Fila del comprobante.
	 */
	private function render( $c ) {
		// Los datos de la empresa salen del emisor del comprobante, no de los
		// ajustes globales: con dos empresas, el ticket tiene que decir cuál
		// emitió esa venta.
		$a        = MSP_Emisor::ajustes_de_comprobante( $c );
		$sede     = get_post( (int) $c['sede_id'] );
		$direccion = $sede ? get_post_meta( $sede->ID, '_msp_direccion', true ) : '';
		$lineas   = $this->lineas( $c );
		$total    = (float) $c['total'];
		$igv      = (float) $c['igv'];
		$base     = round( $total - $igv, 2 );
		$qr       = self::qr_svg( self::cadena_qr( $c ) );
		$anulado  = in_array( $c['baja_estado'], array( 'anulado', 'enviada', 'pendiente' ), true );

		// Salida ESC/POS para RawBT. Solo si está encendida y el comprobante ya
		// volvió firmado: sin hash no hay QR válido y no se imprime nada.
		$escpos  = MSP_Ticket_EscPos::activo() ? MSP_Ticket_EscPos::base64( $c ) : '';
		$aj_esc  = MSP_Ticket_EscPos::ajustes();
		$auto    = $escpos && $aj_esc['auto'] && ! empty( $_GET['auto'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- El nonce lo valida servir() antes de llegar aquí.

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( MSP_Comprobante::numero( $c ) ); ?></title>
<style>
	/* 80 mm es el ancho estándar del papel térmico de mostrador. `size: 80mm
	   auto` NO vale: Chrome descarta la regla entera y el PDF sale en A4. Aquí
	   va un alto provisional y el script de abajo pone el alto real del ticket,
	   para que el PDF mida 80 mm de ancho y termine donde termina el ticket. */
	@page { size: 80mm 297mm; margin: 0; }
	* { box-sizing: border-box; }
	body {
		margin: 0; padding: 6mm 4mm;
		width: 80mm;
		font-family: "DejaVu Sans Mono", "Courier New", monospace;
		font-size: 11px; line-height: 1.45; color: #000; background: #fff;
	}
	.c { text-align: center; }
	.b { font-weight: 700; }
	h1 { font-size: 13px; margin: 0 0 2px; text-transform: uppercase; }
	.sub { font-size: 10px; }
	hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
	table { width: 100%; border-collapse: collapse; }
	td { padding: 1px 0; vertical-align: top; }
	td.n { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
	.tot td { font-size: 12px; }
	.qr { margin: 8px auto 4px; width: 42mm; }
	.qr svg { width: 100%; height: auto; display: block; }
	.legal { font-size: 9px; text-align: center; margin-top: 6px; }
	.anulado {
		border: 2px solid #000; text-align: center; font-weight: 700;
		padding: 3px; margin: 6px 0; letter-spacing: .1em;
	}
	.acciones { text-align: center; padding: 12px; }
	.acciones button, .acciones .btn {
		font: inherit; font-size: 13px; padding: 8px 18px; cursor: pointer;
		border: 1px solid #000; background: #fff; display: inline-block;
		text-decoration: none; color: #000;
	}
	.acciones .btn-primario { background: #000; color: #fff; font-weight: 700; }
	.acciones .aviso { font-size: 11px; margin-top: 10px; padding: 8px; border: 1px dashed #000; }
	@media print { .acciones { display: none; } }
</style>
</head>
<body>

<div class="c">
	<h1><?php echo esc_html( $a['razon_social'] ); ?></h1>
	<div class="sub">RUC <?php echo esc_html( $a['ruc'] ); ?></div>
	<?php if ( $sede ) : ?>
		<div class="sub"><?php echo esc_html( $sede->post_title ); ?></div>
	<?php endif; ?>
	<?php if ( $direccion ) : ?>
		<div class="sub"><?php echo esc_html( $direccion ); ?></div>
	<?php endif; ?>
</div>

<hr>

<div class="c b">
	<?php echo esc_html( mb_strtoupper( MSP_Comprobante::dato_tipo( $c['tipo'], 'etiqueta' ) ) ); ?><br>
	<?php echo esc_html( MSP_Comprobante::numero( $c ) ); ?>
</div>

<?php if ( ! empty( $c['prueba'] ) ) : ?>
	<div class="anulado"><?php esc_html_e( 'TICKET DE PRUEBA — NO ES UN COMPROBANTE', 'multisede-pos' ); ?></div>
<?php endif; ?>

<?php if ( 'anulado' === $c['baja_estado'] ) : ?>
	<div class="anulado"><?php esc_html_e( 'ANULADA', 'multisede-pos' ); ?></div>
<?php elseif ( $anulado ) : ?>
	<div class="anulado"><?php esc_html_e( 'BAJA EN TRÁMITE', 'multisede-pos' ); ?></div>
<?php endif; ?>

<hr>

<table>
	<tr>
		<td><?php esc_html_e( 'Fecha', 'multisede-pos' ); ?></td>
		<td class="n"><?php echo esc_html( gmdate( 'd/m/Y H:i', strtotime( $c['emitido_at'] ) ) ); ?></td>
	</tr>
	<tr>
		<td><?php esc_html_e( 'Cliente', 'multisede-pos' ); ?></td>
		<td class="n"><?php echo esc_html( $c['cliente_nombre'] ); ?></td>
	</tr>
	<?php if ( $c['cliente_num_doc'] ) : ?>
		<tr>
			<td><?php echo esc_html( 'factura' === MSP_Comprobante::tipo_valido( $c['tipo'] ) ? __( 'RUC', 'multisede-pos' ) : __( 'DNI', 'multisede-pos' ) ); ?></td>
			<td class="n"><?php echo esc_html( $c['cliente_num_doc'] ); ?></td>
		</tr>
	<?php endif; ?>
</table>

<hr>

<table>
	<?php if ( $lineas ) : ?>
		<?php foreach ( $lineas as $l ) : ?>
			<tr>
				<td colspan="2"><?php echo esc_html( $l['descripcion'] ); ?></td>
			</tr>
			<tr>
				<td><?php echo esc_html( $l['cantidad'] ); ?> x</td>
				<td class="n"><?php echo esc_html( number_format( $l['importe'], 2 ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	<?php else : ?>
		<tr>
			<td><?php esc_html_e( 'Venta', 'multisede-pos' ); ?></td>
			<td class="n"><?php echo esc_html( number_format( $total, 2 ) ); ?></td>
		</tr>
	<?php endif; ?>
</table>

<hr>

<table>
	<tr>
		<td><?php esc_html_e( 'Op. gravada', 'multisede-pos' ); ?></td>
		<td class="n">S/ <?php echo esc_html( number_format( $base, 2 ) ); ?></td>
	</tr>
	<tr>
		<td><?php esc_html_e( 'IGV (18%)', 'multisede-pos' ); ?></td>
		<td class="n">S/ <?php echo esc_html( number_format( $igv, 2 ) ); ?></td>
	</tr>
	<tr class="tot b">
		<td><?php esc_html_e( 'TOTAL', 'multisede-pos' ); ?></td>
		<td class="n">S/ <?php echo esc_html( number_format( $total, 2 ) ); ?></td>
	</tr>
</table>

<div class="legal">
	<?php echo esc_html( MSP_Emisor::monto_en_letras( $total ) ); ?>
</div>

<?php if ( $qr && '' !== $c['hash'] ) : ?>
	<div class="qr"><?php echo $qr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG generado por la librería de QR. ?></div>
	<script>window.sessionStorage.removeItem( 'msp-ticket-<?php echo (int) $c['id']; ?>' );</script>
<?php else : ?>
	<?php /* El QR lleva el hash de la firma, que no existe hasta que el
	         comprobante se firma y se envía. Imprimir un QR sin él daría un
	         código que el verificador de SUNAT no reconoce, que es peor que no
	         ponerlo: el cliente creería tener algo comprobable. */ ?>
	<div class="legal" id="msp-esperando" style="margin-top:8px">
		<strong><?php esc_html_e( 'Esperando la confirmación de SUNAT para el código QR…', 'multisede-pos' ); ?></strong><br>
		<span id="msp-espera-detalle"><?php esc_html_e( 'Suele tardar unos segundos. Esta página se actualiza sola.', 'multisede-pos' ); ?></span>
	</div>
	<script>
	/* Se recarga sola hasta que exista el hash de la firma, en vez de dejar al
	   cajero adivinar cuándo recargar. Con tope: si SUNAT no responde en un par
	   de minutos, deja de intentarlo y lo dice, para no tener una pestaña
	   recargándose sola toda la tarde en el mostrador. */
	( function () {
		var MAX = 40, ESPERA = 3000;
		var clave = 'msp-ticket-<?php echo (int) $c['id']; ?>';
		var n = parseInt( window.sessionStorage.getItem( clave ) || '0', 10 );

		if ( n >= MAX ) {
			window.sessionStorage.removeItem( clave );
			document.getElementById( 'msp-espera-detalle' ).textContent =
				<?php echo wp_json_encode( __( 'SUNAT no ha respondido todavía. Revisa la pantalla de Comprobantes: la boleta es válida igual, el QR se puede imprimir después.', 'multisede-pos' ) ); ?>;
			return;
		}

		window.setTimeout( function () {
			window.sessionStorage.setItem( clave, n + 1 );
			window.location.reload();
		}, ESPERA );
	} )();
	</script>
<?php endif; ?>

<div class="legal">
	<?php
	printf(
		/* translators: %s: nombre del comprobante en minúsculas ("boleta de venta electrónica" o "factura electrónica"). */
		esc_html__( 'Representación impresa de la %s.', 'multisede-pos' ),
		esc_html( mb_strtolower( MSP_Comprobante::dato_tipo( $c['tipo'], 'etiqueta' ) ) )
	);
	?><br>
	<?php esc_html_e( 'Consúltala en www.sunat.gob.pe', 'multisede-pos' ); ?>
	<?php if ( ! empty( $c['prueba'] ) || ! MSP_Emisor::es_produccion() ) : ?>
		<br><strong><?php esc_html_e( '*** DOCUMENTO DE PRUEBA — SIN VALOR ***', 'multisede-pos' ); ?></strong>
	<?php endif; ?>
</div>

<div class="acciones">
	<?php if ( $escpos ) : ?>
		<?php
		/* La impresora integrada de la Falcon 1 no aparece en el diálogo de
		   Chrome, así que aquí no se imprime: se le entrega a RawBT el ticket
		   ya convertido a comandos ESC/POS y RawBT habla con la impresora. */
		?>
		<a class="btn btn-primario" id="msp-rawbt" href="rawbt:base64,<?php echo esc_attr( $escpos ); ?>">
			<?php esc_html_e( 'Imprimir en la impresora', 'multisede-pos' ); ?>
		</a>
		<button type="button" onclick="window.print()"><?php esc_html_e( 'Imprimir por el navegador', 'multisede-pos' ); ?></button>

		<div class="aviso" id="msp-rawbt-alterno" hidden>
			<strong><?php esc_html_e( 'No se abrió RawBT.', 'multisede-pos' ); ?></strong><br>
			<?php esc_html_e( 'Si está instalado, prueba el formato alterno: algunas versiones de RawBT usan otro modo de recibir el trabajo.', 'multisede-pos' ); ?>
			<br><br>
			<a class="btn" href="intent:base64,<?php echo esc_attr( $escpos ); ?>#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;">
				<?php esc_html_e( 'Probar formato alterno', 'multisede-pos' ); ?>
			</a>
			<a class="btn" href="https://play.google.com/store/apps/details?id=ru.a402d.rawbtprinter" target="_blank" rel="noopener">
				<?php esc_html_e( 'Instalar RawBT', 'multisede-pos' ); ?>
			</a>
		</div>

		<script>
		( function () {
			var enlace  = document.getElementById( 'msp-rawbt' );
			var alterno = document.getElementById( 'msp-rawbt-alterno' );
			var salio   = false;

			/* Si RawBT abre, la pestaña deja de estar visible. Si a los 2,5 s
			   seguimos aquí mirando la misma página, es que nadie recogió el
			   trabajo: el cajero necesita saberlo, no quedarse esperando. */
			function vigilar() {
				salio = false;
				window.setTimeout( function () {
					if ( ! salio && ! document.hidden ) {
						alterno.hidden = false;
					}
				}, 2500 );
			}

			document.addEventListener( 'visibilitychange', function () {
				if ( document.hidden ) {
					salio = true;
				}
			} );

			enlace.addEventListener( 'click', vigilar );

			<?php if ( $auto ) : ?>
			/* Llega desde el POS con el cliente delante: se manda solo. */
			window.setTimeout( function () {
				vigilar();
				window.location.href = enlace.getAttribute( 'href' );
			}, 300 );
			<?php endif; ?>
		} )();
		</script>
	<?php else : ?>
		<button type="button" onclick="window.print()"><?php esc_html_e( 'Imprimir', 'multisede-pos' ); ?></button>
	<?php endif; ?>

	<p style="font-size:11px">
		<?php esc_html_e( 'Para guardarlo como PDF, elige "Guardar como PDF" en el destino de impresión.', 'multisede-pos' ); ?>
	</p>
</div>

<script>
	/* Alto de página = alto del ticket, en mm (96 px por pulgada). Se aplica al
	   cargar y justo antes de imprimir, cuando ya están ocultos los botones. */
	( function () {
		var estilo = document.createElement( 'style' );
		document.head.appendChild( estilo );
		function ajustar() {
			var mm = Math.ceil( document.body.scrollHeight * 25.4 / 96 ) + 2;
			estilo.textContent = '@page { size: 80mm ' + mm + 'mm; margin: 0; }';
		}
		window.addEventListener( 'load', ajustar );
		window.addEventListener( 'beforeprint', function () {
			var acc = document.querySelector( '.acciones' );
			var antes = acc ? acc.style.display : '';
			if ( acc ) { acc.style.display = 'none'; }
			ajustar();
			if ( acc ) { acc.style.display = antes; }
		} );
	} )();
	</script>
</body>
</html>
		<?php
	}
}
