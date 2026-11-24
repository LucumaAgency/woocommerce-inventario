<?php
/**
 * Pantalla de notas de crédito: pedirlas y aprobarlas.
 *
 * Dos vistas en una: el cajero ve el formulario para pedir una sobre una venta,
 * y el gerente ve la bandeja de lo que espera su visto bueno. Están juntas
 * porque son el mismo expediente visto desde los dos lados, y separar la
 * pantalla obligaría al gerente a saltar entre dos para entender qué le están
 * pidiendo.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menú y formularios de notas de crédito.
 */
class MSP_Notas_Pantalla {

	const PAGE = 'msp-notas';

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'registrar_pagina' ), 20 );
		add_action( 'admin_init', array( $this, 'procesar' ) );
	}

	/**
	 * Añade la pantalla bajo Caja, con el número de pendientes a la vista.
	 *
	 * El contador en el menú no es decoración: una nota pendiente es dinero que
	 * el cliente espera y mercadería que ya volvió. Sin el aviso, la solicitud
	 * se queda ahí hasta que alguien pregunta.
	 */
	public function registrar_pagina() {
		if ( ! current_user_can( MSP_Nota::CAP_SOLICITAR ) && ! current_user_can( MSP_Nota::CAP_APROBAR ) ) {
			return;
		}

		$titulo     = __( 'Notas de crédito', 'multisede-pos' );
		$pendientes = current_user_can( MSP_Nota::CAP_APROBAR ) ? MSP_Nota::pendientes() : 0;

		if ( $pendientes ) {
			$titulo .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $pendientes . '</span></span>';
		}

		add_submenu_page(
			'msp-caja',
			__( 'Notas de crédito', 'multisede-pos' ),
			$titulo,
			MSP_Nota::CAP_SOLICITAR,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * URL de la pantalla.
	 *
	 * @param array $args Parámetros extra.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Procesa solicitud, aprobación y rechazo.
	 */
	public function procesar() {
		if ( ! isset( $_POST['msp_nota_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		check_admin_referer( 'msp_notas', 'msp_nota_nonce' );

		$accion = sanitize_key( wp_unslash( $_POST['msp_nota_action'] ) );
		$aviso  = '';

		if ( 'solicitar' === $accion ) {
			$lineas = array();
			if ( isset( $_POST['linea'] ) && is_array( $_POST['linea'] ) ) {
				foreach ( wp_unslash( $_POST['linea'] ) as $item_id => $cantidad ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$lineas[ (int) $item_id ] = (int) $cantidad;
				}
			}

			$r = MSP_Nota::solicitar(
				array(
					'comprobante_id' => isset( $_POST['comprobante'] ) ? (int) $_POST['comprobante'] : 0,
					'motivo'         => isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '',
					'detalle'        => isset( $_POST['detalle'] ) ? sanitize_textarea_field( wp_unslash( $_POST['detalle'] ) ) : '',
					'lineas'         => $lineas,
					'devolver_stock' => ! empty( $_POST['devolver_stock'] ),
				)
			);

			$aviso = is_wp_error( $r )
				? '⚠️ ' . $r->get_error_message()
				: __( 'Solicitud enviada. Un gerente tiene que aprobarla para que la nota se emita.', 'multisede-pos' );

		} elseif ( 'aprobar' === $accion ) {
			$r = MSP_Nota::aprobar(
				isset( $_POST['solicitud'] ) ? (int) $_POST['solicitud'] : 0,
				isset( $_POST['respuesta'] ) ? sanitize_text_field( wp_unslash( $_POST['respuesta'] ) ) : ''
			);

			$aviso = is_wp_error( $r )
				? '⚠️ ' . $r->get_error_message()
				: sprintf(
					/* translators: %s: número de la nota. */
					__( 'Nota de crédito %s emitida y enviada a SUNAT.', 'multisede-pos' ),
					MSP_Comprobante::numero( $r )
				);

		} elseif ( 'rechazar' === $accion ) {
			$r     = MSP_Nota::rechazar(
				isset( $_POST['solicitud'] ) ? (int) $_POST['solicitud'] : 0,
				isset( $_POST['respuesta'] ) ? sanitize_text_field( wp_unslash( $_POST['respuesta'] ) ) : ''
			);
			$aviso = is_wp_error( $r ) ? '⚠️ ' . $r->get_error_message() : __( 'Solicitud rechazada. No se emitió nada.', 'multisede-pos' );
		}

		wp_safe_redirect( self::url( array( 'aviso' => rawurlencode( $aviso ) ) ) );
		exit;
	}

	/**
	 * Pinta la pantalla.
	 */
	public function render() {
		$aviso = isset( $_GET['aviso'] ) ? sanitize_text_field( wp_unslash( $_GET['aviso'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sobre = isset( $_GET['comprobante'] ) ? (int) $_GET['comprobante'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Notas de crédito', 'multisede-pos' ); ?></h1>

			<?php if ( $aviso ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $aviso ); ?></p></div>
			<?php endif; ?>

			<p class="description" style="max-width:48em">
				<?php esc_html_e( 'La nota de crédito devuelve dinero al cliente y, si se marca, mercadería a la tienda. El cajero la pide y un gerente la aprueba: hasta que alguien la apruebe no se emite nada ni se gasta numeración.', 'multisede-pos' ); ?>
			</p>

			<?php if ( $sobre ) : ?>
				<?php $this->formulario( $sobre ); ?>
			<?php else : ?>
				<?php $this->buscador(); ?>
			<?php endif; ?>

			<?php $this->bandeja(); ?>
		</div>
		<?php
	}

	/**
	 * Buscador de la venta sobre la que se pide la nota.
	 *
	 * Es la entrada del cajero: no ve el listado de Comprobantes (es de
	 * reportes), así que busca la venta aquí por el número del ticket o del
	 * pedido. Solo le salen ventas de sus tiendas.
	 */
	private function buscador() {
		$q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filas = '' !== $q ? MSP_Nota::buscar_comprobantes( $q ) : array();
		?>
		<h2><?php esc_html_e( 'Pedir una nota de crédito', 'multisede-pos' ); ?></h2>
		<form method="get" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<label for="msp-nota-q" class="screen-reader-text"><?php esc_html_e( 'Número de boleta, factura o pedido', 'multisede-pos' ); ?></label>
			<input type="search" id="msp-nota-q" name="q" value="<?php echo esc_attr( $q ); ?>" style="width:260px"
				placeholder="<?php esc_attr_e( 'B100-25, F100-3 o n.º de pedido', 'multisede-pos' ); ?>" />
			<?php submit_button( __( 'Buscar venta', 'multisede-pos' ), 'secondary', '', false ); ?>
		</form>
		<p class="description" style="margin-top:0">
			<?php esc_html_e( 'Escribe el número que figura en el ticket del cliente (no hacen falta los ceros) o el número de pedido.', 'multisede-pos' ); ?>
		</p>

		<?php if ( '' === $q ) : ?>
			<?php return; ?>
		<?php endif; ?>

		<?php if ( ! $filas ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'No se encontró esa venta en tus tiendas. Revisa el número del ticket.', 'multisede-pos' ); ?>
			</p></div>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped" style="max-width:60em;margin-top:8px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Comprobante', 'multisede-pos' ); ?></th>
					<th><?php esc_html_e( 'Pedido', 'multisede-pos' ); ?></th>
					<th><?php esc_html_e( 'Cliente', 'multisede-pos' ); ?></th>
					<th><?php esc_html_e( 'Total', 'multisede-pos' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $filas as $c ) : ?>
					<tr>
						<td>
							<?php echo esc_html( MSP_Comprobante::dato_tipo( $c['tipo'], 'corto' ) . ' ' . MSP_Comprobante::numero( $c ) ); ?>
						</td>
						<td><?php echo $c['pedido_id'] ? '#' . esc_html( $c['pedido_id'] ) : '—'; ?></td>
						<td><?php echo esc_html( isset( $c['cliente_nombre'] ) && $c['cliente_nombre'] ? $c['cliente_nombre'] : '—' ); ?></td>
						<td>S/ <?php echo esc_html( number_format( (float) $c['total'], 2 ) ); ?></td>
						<td>
							<?php if ( MSP_Comprobante::es_nota( $c ) ) : ?>
								<em><?php esc_html_e( 'Es una nota de crédito: no se corrige con otra nota.', 'multisede-pos' ); ?></em>
							<?php elseif ( 'aceptado' !== $c['estado'] ) : ?>
								<em><?php esc_html_e( 'SUNAT todavía no la aceptó: aún no se puede pedir nota.', 'multisede-pos' ); ?></em>
							<?php else : ?>
								<a class="button button-primary" href="<?php echo esc_url( self::url( array( 'comprobante' => $c['id'] ) ) ); ?>">
									<?php esc_html_e( 'Pedir nota sobre esta venta', 'multisede-pos' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Formulario para pedir una nota sobre un comprobante.
	 *
	 * @param int $comprobante_id Comprobante.
	 */
	private function formulario( $comprobante_id ) {
		$c = MSP_Comprobante::obtener( $comprobante_id );
		if ( ! $c ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Ese comprobante no existe.', 'multisede-pos' ) . '</p></div>';
			return;
		}

		if ( ! MSP_Nota::puede_pedir_sobre( $c ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Esa venta es de una tienda que no tienes asignada.', 'multisede-pos' ) . '</p></div>';
			return;
		}

		$pedido    = $c['pedido_id'] ? wc_get_order( (int) $c['pedido_id'] ) : null;
		$acreditado = MSP_Nota::total_ya_acreditado( $comprobante_id );
		?>
		<h2>
			<?php
			printf(
				/* translators: 1: tipo, 2: número. */
				esc_html__( 'Nueva nota de crédito sobre %1$s %2$s', 'multisede-pos' ),
				esc_html( MSP_Comprobante::dato_tipo( $c['tipo'], 'corto' ) ),
				esc_html( MSP_Comprobante::numero( $c ) )
			);
			?>
		</h2>

		<?php if ( $acreditado > 0 ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: 1: importe devuelto, 2: total. */
						esc_html__( 'Ojo: de este comprobante ya se devolvieron S/ %1$s de S/ %2$s.', 'multisede-pos' ),
						esc_html( number_format( $acreditado, 2 ) ),
						esc_html( number_format( (float) $c['total'], 2 ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" style="background:#fff;border:1px solid #c3c4c7;padding:16px;max-width:52em">
			<?php wp_nonce_field( 'msp_notas', 'msp_nota_nonce' ); ?>
			<input type="hidden" name="msp_nota_action" value="solicitar" />
			<input type="hidden" name="comprobante" value="<?php echo esc_attr( $comprobante_id ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="motivo"><?php esc_html_e( 'Motivo', 'multisede-pos' ); ?></label></th>
					<td>
						<select name="motivo" id="motivo" required>
							<option value=""><?php esc_html_e( '— elegir —', 'multisede-pos' ); ?></option>
							<?php foreach ( MSP_Comprobante::motivos_nota() as $codigo => $etiqueta ) : ?>
								<option value="<?php echo esc_attr( $codigo ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="detalle"><?php esc_html_e( 'Detalle', 'multisede-pos' ); ?></label></th>
					<td>
						<input type="text" class="large-text" name="detalle" id="detalle" maxlength="200"
							placeholder="<?php esc_attr_e( 'Ej. La clienta devolvió el pantalón, talla equivocada', 'multisede-pos' ); ?>" />
						<p class="description"><?php esc_html_e( 'Lo lee el gerente al aprobar, y va impreso en la nota.', 'multisede-pos' ); ?></p>
					</td>
				</tr>

				<?php if ( $pedido ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Qué se devuelve', 'multisede-pos' ); ?></th>
						<td>
							<table class="widefat striped" style="max-width:40em">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Producto', 'multisede-pos' ); ?></th>
										<th><?php esc_html_e( 'Vendidas', 'multisede-pos' ); ?></th>
										<th><?php esc_html_e( 'Devolver', 'multisede-pos' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $pedido->get_items() as $item_id => $item ) : ?>
										<tr>
											<td><?php echo esc_html( $item->get_name() ); ?></td>
											<td><?php echo esc_html( $item->get_quantity() ); ?></td>
											<td>
												<input type="number" name="linea[<?php echo esc_attr( $item_id ); ?>]"
													min="0" max="<?php echo esc_attr( $item->get_quantity() ); ?>" value="0"
													style="width:80px" />
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p class="description">
								<?php esc_html_e( 'Deja todo en 0 para devolver la venta completa.', 'multisede-pos' ); ?>
							</p>
						</td>
					</tr>
				<?php endif; ?>

				<tr>
					<th scope="row"><?php esc_html_e( 'Mercadería', 'multisede-pos' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="devolver_stock" value="1" checked />
							<?php esc_html_e( 'La mercadería vuelve al stock de la tienda', 'multisede-pos' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Desmárcalo si vuelve dañada o no vuelve: entonces el stock no se repone. Lo decide quien la tiene delante.', 'multisede-pos' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Pedir la nota de crédito', 'multisede-pos' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Bandeja de solicitudes.
	 */
	private function bandeja() {
		$puede_aprobar = current_user_can( MSP_Nota::CAP_APROBAR );
		// El cajero ve solo las de sus tiendas: la bandeja lleva clientes e importes.
		$solicitudes = array_values(
			array_filter(
				MSP_Nota::listar( array( 'limite' => 50 ) ),
				array( 'MSP_Nota', 'puede_pedir_sobre' )
			)
		);
		$estados       = MSP_Nota::estados();
		$motivos       = MSP_Comprobante::motivos_nota();
		?>
		<h2><?php esc_html_e( 'Solicitudes', 'multisede-pos' ); ?></h2>

		<?php if ( ! $solicitudes ) : ?>
			<p><?php esc_html_e( 'Todavía no hay solicitudes.', 'multisede-pos' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Comprobante', 'multisede-pos' ); ?></th>
					<th><?php esc_html_e( 'Motivo', 'multisede-pos' ); ?></th>
					<th><?php esc_html_e( 'Importe', 'multisede-pos' ); ?></th>
					<th><?php esc_html_e( 'Stock', 'multisede-pos' ); ?></th>
					<th><?php esc_html_e( 'Pidió', 'multisede-pos' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'multisede-pos' ); ?></th>
					<th><?php esc_html_e( 'Nota emitida', 'multisede-pos' ); ?></th>
					<?php if ( $puede_aprobar ) : ?>
						<th><?php esc_html_e( 'Decisión', 'multisede-pos' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $solicitudes as $s ) : ?>
					<?php
					$original = MSP_Comprobante::obtener( (int) $s['comprobante_id'] );
					$nota     = $s['nota_comprobante_id'] ? MSP_Comprobante::obtener( (int) $s['nota_comprobante_id'] ) : null;
					$quien    = get_userdata( (int) $s['solicitante_id'] );
					?>
					<tr>
						<td>
							<?php echo $original ? esc_html( MSP_Comprobante::numero( $original ) ) : '—'; ?>
						</td>
						<td>
							<?php echo esc_html( isset( $motivos[ $s['motivo'] ] ) ? $motivos[ $s['motivo'] ] : $s['motivo'] ); ?>
							<?php if ( $s['detalle'] ) : ?>
								<br><em style="color:#666"><?php echo esc_html( $s['detalle'] ); ?></em>
							<?php endif; ?>
						</td>
						<td>S/ <?php echo esc_html( number_format( (float) $s['total'], 2 ) ); ?></td>
						<td><?php echo $s['devolver_stock'] ? '✅' : '—'; ?></td>
						<td>
							<?php echo esc_html( $quien ? $quien->display_name : '—' ); ?><br>
							<span style="color:#666"><?php echo esc_html( $s['solicitado_at'] ); ?></span>
						</td>
						<td>
							<?php echo esc_html( isset( $estados[ $s['estado'] ] ) ? $estados[ $s['estado'] ] : $s['estado'] ); ?>
							<?php if ( $s['respuesta'] ) : ?>
								<br><em style="color:#666"><?php echo esc_html( $s['respuesta'] ); ?></em>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $nota ) : ?>
								<code><?php echo esc_html( MSP_Comprobante::numero( $nota ) ); ?></code>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<?php if ( $puede_aprobar ) : ?>
							<td>
								<?php if ( 'pendiente' === $s['estado'] && (int) $s['solicitante_id'] === get_current_user_id() ) : ?>
									<em style="color:#666"><?php esc_html_e( 'La pediste tú: la aprueba otra persona.', 'multisede-pos' ); ?></em>
								<?php elseif ( 'pendiente' === $s['estado'] ) : ?>
									<form method="post" style="display:flex;gap:4px;flex-wrap:wrap">
										<?php wp_nonce_field( 'msp_notas', 'msp_nota_nonce' ); ?>
										<input type="hidden" name="solicitud" value="<?php echo esc_attr( $s['id'] ); ?>" />
										<input type="text" name="respuesta" style="width:130px"
											placeholder="<?php esc_attr_e( 'comentario', 'multisede-pos' ); ?>" />
										<button type="submit" name="msp_nota_action" value="aprobar" class="button button-primary"
											onclick="return confirm('<?php esc_attr_e( '¿Aprobar? Se emitirá la nota a SUNAT y no se puede deshacer.', 'multisede-pos' ); ?>')">
											<?php esc_html_e( 'Aprobar', 'multisede-pos' ); ?>
										</button>
										<button type="submit" name="msp_nota_action" value="rechazar" class="button">
											<?php esc_html_e( 'Rechazar', 'multisede-pos' ); ?>
										</button>
									</form>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
