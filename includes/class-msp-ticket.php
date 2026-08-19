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

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'servir' ) );
	}

	/**
	 * URL del ticket de un comprobante.
	 *
	 * @param int $comprobante_id ID del comprobante.
	 * @return string
	 */
	public static function url( $comprobante_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::ACTION,
					'comprobante' => (int) $comprobante_id,
				),
				admin_url( 'admin-post.php' )
			),
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
	 * @param array $c Fila del comprobante.
	 * @return string
	 */
	public static function cadena_qr( $c ) {
		$a = MSP_Emisor::ajustes();

		return implode(
			'|',
			array(
				$a['ruc'],
				'03',
				$c['serie'],
				(int) $c['correlativo'],
				number_format( (float) $c['igv'], 2, '.', '' ),
				number_format( (float) $c['total'], 2, '.', '' ),
				gmdate( 'Y-m-d', strtotime( $c['emitido_at'] ) ),
				$c['cliente_num_doc'] ? '1' : '0',
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
		$a        = MSP_Emisor::ajustes();
		$sede     = get_post( (int) $c['sede_id'] );
		$direccion = $sede ? get_post_meta( $sede->ID, '_msp_direccion', true ) : '';
		$lineas   = $this->lineas( $c );
		$total    = (float) $c['total'];
		$igv      = (float) $c['igv'];
		$base     = round( $total - $igv, 2 );
		$qr       = self::qr_svg( self::cadena_qr( $c ) );
		$anulado  = in_array( $c['baja_estado'], array( 'anulado', 'enviada', 'pendiente' ), true );

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
	/* 80 mm es el ancho estándar del papel térmico de mostrador. Con `auto` de
	   alto, el ticket se corta donde termina en vez de gastar una hoja entera. */
	@page { size: 80mm auto; margin: 0; }
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
	.acciones button {
		font: inherit; font-size: 13px; padding: 8px 18px; cursor: pointer;
		border: 1px solid #000; background: #fff;
	}
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
	<?php esc_html_e( 'BOLETA DE VENTA ELECTRÓNICA', 'multisede-pos' ); ?><br>
	<?php echo esc_html( MSP_Comprobante::numero( $c ) ); ?>
</div>

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
			<td><?php esc_html_e( 'DNI', 'multisede-pos' ); ?></td>
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
<?php else : ?>
	<?php /* El QR lleva el hash de la firma, que no existe hasta que el
	         comprobante se firma y se envía. Imprimir un QR sin él daría un
	         código que el verificador de SUNAT no reconoce, que es peor que no
	         ponerlo: el cliente creería tener algo comprobable. */ ?>
	<div class="legal" style="margin-top:8px">
		<strong><?php esc_html_e( 'El código QR aparece cuando SUNAT confirma la boleta.', 'multisede-pos' ); ?></strong><br>
		<?php esc_html_e( 'Suele tardar unos segundos: recarga esta página antes de imprimir.', 'multisede-pos' ); ?>
	</div>
<?php endif; ?>

<div class="legal">
	<?php esc_html_e( 'Representación impresa de la boleta de venta electrónica.', 'multisede-pos' ); ?><br>
	<?php esc_html_e( 'Consúltala en www.sunat.gob.pe', 'multisede-pos' ); ?>
	<?php if ( ! MSP_Emisor::es_produccion() ) : ?>
		<br><strong><?php esc_html_e( '*** DOCUMENTO DE PRUEBA — SIN VALOR ***', 'multisede-pos' ); ?></strong>
	<?php endif; ?>
</div>

<div class="acciones">
	<button type="button" onclick="window.print()"><?php esc_html_e( 'Imprimir', 'multisede-pos' ); ?></button>
	<p style="font-size:11px">
		<?php esc_html_e( 'Para guardarlo como PDF, elige "Guardar como PDF" en el destino de impresión.', 'multisede-pos' ); ?>
	</p>
</div>

</body>
</html>
		<?php
	}
}
