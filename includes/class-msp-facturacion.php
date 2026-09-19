<?php
/**
 * Pantalla de facturación electrónica: ajustes y emisión de prueba.
 *
 * Fase 2. La emisión se dispara a mano desde aquí, a propósito: el objetivo es
 * probar el motor aislado antes de cablearlo a la venta real (Fase 3).
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ajustes y pruebas de facturación electrónica.
 */
class MSP_Facturacion {

	const PAGE = 'msp-facturacion';

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'registrar_pagina' ) );
		add_action( 'admin_init', array( $this, 'procesar' ) );
	}

	/**
	 * Registra la pantalla en el menú.
	 */
	public function registrar_pagina() {
		add_submenu_page(
			'msp-caja',
			__( 'Facturación electrónica', 'multisede-pos' ),
			__( 'Facturación', 'multisede-pos' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Guarda ajustes y lanza la emisión de prueba.
	 */
	public function procesar() {
		if ( ! isset( $_POST['msp_fact_action'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		check_admin_referer( 'msp_facturacion', 'msp_fact_nonce' );

		$accion = sanitize_key( wp_unslash( $_POST['msp_fact_action'] ) );
		$aviso  = '';

		if ( 'guardar' === $accion ) {
			$campos = array( 'entorno', 'cert_path', 'sol_usuario', 'sol_clave', 'ruc', 'razon_social', 'direccion', 'ubigeo', 'departamento', 'provincia', 'distrito' );
			$nuevos = MSP_Emisor::ajustes();
			foreach ( $campos as $campo ) {
				if ( isset( $_POST[ $campo ] ) ) {
					$nuevos[ $campo ] = sanitize_text_field( wp_unslash( $_POST[ $campo ] ) );
				}
			}
			// El interruptor solo acepta dos valores: cualquier cosa rara vuelve
			// a beta. Un envío accidental a producción no se deshace.
			$nuevos['entorno'] = 'produccion' === $nuevos['entorno'] ? 'produccion' : 'beta';

			$nuevos['emision_automatica'] = ! empty( $_POST['emision_automatica'] ) ? 1 : 0;
			$nuevos['simular_fallo']      = ! empty( $_POST['simular_fallo'] ) ? 1 : 0;

			// Impresión ESC/POS (RawBT). Los límites los pone
			// MSP_Ticket_EscPos::ajustes(); aquí solo se guarda lo que llega.
			$nuevos['escpos_activo']    = ! empty( $_POST['escpos_activo'] ) ? 1 : 0;
			$nuevos['escpos_cortar']    = ! empty( $_POST['escpos_cortar'] ) ? 1 : 0;
			$nuevos['escpos_auto']      = ! empty( $_POST['escpos_auto'] ) ? 1 : 0;
			$nuevos['escpos_qr_nativo'] = ! empty( $_POST['escpos_qr_nativo'] ) ? 1 : 0;
			$nuevos['escpos_columnas']  = isset( $_POST['escpos_columnas'] ) ? (int) $_POST['escpos_columnas'] : 42;
			$nuevos['escpos_copias']    = isset( $_POST['escpos_copias'] ) ? (int) $_POST['escpos_copias'] : 1;
			$nuevos['escpos_codepage']  = isset( $_POST['escpos_codepage'] )
				? sanitize_key( wp_unslash( $_POST['escpos_codepage'] ) )
				: 'cp850';
			update_option( self::opcion(), $nuevos );

			// Consulta del RUC. Vive en su propia opción porque no es parte de
			// la emisión: es una ayuda para teclear menos.
			update_option(
				MSP_Ruc::OPCION,
				array(
					'activa'   => ! empty( $_POST['ruc_consulta_activa'] ) ? 1 : 0,
					'endpoint' => isset( $_POST['ruc_consulta_endpoint'] )
						? esc_url_raw( wp_unslash( $_POST['ruc_consulta_endpoint'] ) )
						: MSP_Ruc::ENDPOINT,
				)
			);

			$aviso = 'guardado';

		} elseif ( 'guardar_emisor' === $accion ) {
			$aviso = $this->guardar_emisor();

		} elseif ( 'borrar_emisor' === $accion ) {
			$ruc       = isset( $_POST['ruc_emisor'] ) ? preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['ruc_emisor'] ) ) : '';
			$emisores  = get_option( MSP_Emisor::OPCION_EMISORES, array() );
			$en_uso    = $this->sedes_del_emisor( $ruc );
			if ( $en_uso ) {
				$aviso = sprintf(
					/* translators: %d: número de sedes. */
					__( '⚠️ No se borró: %d sede(s) todavía emiten con ese RUC. Cámbialas de empresa primero.', 'multisede-pos' ),
					count( $en_uso )
				);
			} else {
				unset( $emisores[ $ruc ] );
				update_option( MSP_Emisor::OPCION_EMISORES, $emisores );
				$aviso = __( 'Empresa emisora eliminada. Los comprobantes ya emitidos con ese RUC se conservan.', 'multisede-pos' );
			}

		} elseif ( 'credenciales' === $accion ) {
			$r     = MSP_Emisor::probar_credenciales();
			$aviso = ( $r['ok'] ? '✅ ' : '⚠️ ' ) . $r['mensaje'];

		} elseif ( 'probar' === $accion ) {
			$sede_id = isset( $_POST['sede_prueba'] ) ? (int) $_POST['sede_prueba'] : 0;
			$total   = isset( $_POST['total_prueba'] ) ? (float) wp_unslash( $_POST['total_prueba'] ) : 15.00;
			$aviso   = $this->emitir_prueba( $sede_id, $total );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::PAGE,
					'aviso' => rawurlencode( $aviso ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Nombre de la opción (delegado en el emisor).
	 *
	 * @return string
	 */
	private static function opcion() {
		return MSP_Emisor::OPCION;
	}

	/**
	 * Reserva un comprobante de prueba y lo emite.
	 *
	 * @param int   $sede_id Sede emisora.
	 * @param float $total   Total con IGV.
	 * @return string Mensaje de resultado.
	 */
	private function emitir_prueba( $sede_id, $total ) {
		if ( MSP_Emisor::es_produccion() ) {
			return __( 'No se emiten pruebas en producción: cambia el entorno a beta.', 'multisede-pos' );
		}
		if ( ! $sede_id ) {
			return __( 'Elige una sede con serie de boleta configurada.', 'multisede-pos' );
		}

		$total = round( max( 0.10, (float) $total ), 2 );
		$base  = round( $total / 1.18, 2 );

		$comprobante = MSP_Comprobante::reservar(
			array(
				'sede_id'        => $sede_id,
				'tipo'           => 'boleta',
				'cliente_nombre' => 'CLIENTE VARIOS',
				'total'          => $total,
				'igv'            => round( $total - $base, 2 ),
			)
		);

		if ( is_wp_error( $comprobante ) ) {
			return $comprobante->get_error_message();
		}

		$resultado = MSP_Emisor::emitir( (int) $comprobante['id'] );

		if ( is_wp_error( $resultado ) ) {
			return sprintf(
				/* translators: 1: número del comprobante, 2: mensaje de error. */
				__( 'Comprobante %1$s: %2$s', 'multisede-pos' ),
				MSP_Comprobante::numero( $comprobante ),
				$resultado->get_error_message()
			);
		}

		return sprintf(
			/* translators: 1: número, 2: estado. */
			__( 'Comprobante %1$s → %2$s', 'multisede-pos' ),
			MSP_Comprobante::numero( $resultado ),
			strtoupper( $resultado['estado'] )
		);
	}

	/**
	 * Pinta la pantalla.
	 */
	public function render() {
		$a         = MSP_Emisor::ajustes();
		$cert      = MSP_Emisor::ruta_certificado();
		$listo     = function_exists( 'msp_facturacion_disponible' ) && msp_facturacion_disponible();
		$sedes     = MSP_Sedes::obtener_sedes_activas();
		$aviso     = isset( $_GET['aviso'] ) ? sanitize_text_field( wp_unslash( $_GET['aviso'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Facturación electrónica', 'multisede-pos' ); ?></h1>

			<?php if ( $aviso ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $aviso ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $listo ) : ?>
				<div class="notice notice-error"><p>
					<?php esc_html_e( 'El motor de emisión no está disponible: faltan las dependencias (carpeta vendor).', 'multisede-pos' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( ! empty( $a['simular_fallo'] ) && ! MSP_Emisor::es_produccion() ) : ?>
				<div class="notice notice-warning"><p>
					<strong><?php esc_html_e( 'Fallo de envío simulado: ACTIVO.', 'multisede-pos' ); ?></strong>
					<?php esc_html_e( 'Ningún comprobante llegará a SUNAT mientras esté encendido. Apágalo al terminar de probar.', 'multisede-pos' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( MSP_Emisor::es_produccion() ) : ?>
				<div class="notice notice-warning"><p>
					<strong><?php esc_html_e( 'Entorno de PRODUCCIÓN.', 'multisede-pos' ); ?></strong>
					<?php esc_html_e( 'Los comprobantes que se emitan son reales y consumen numeración de verdad.', 'multisede-pos' ); ?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Estado', 'multisede-pos' ); ?></h2>
			<table class="widefat striped" style="max-width:760px">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Motor (Greenter)', 'multisede-pos' ); ?></td>
						<td><?php echo $listo ? '✅ ' . esc_html__( 'cargado', 'multisede-pos' ) : '❌ ' . esc_html__( 'no disponible', 'multisede-pos' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Certificado', 'multisede-pos' ); ?></td>
						<td>
							<?php if ( $cert ) : ?>
								<code><?php echo esc_html( $cert ); ?></code>
								<?php if ( false !== strpos( $cert, 'certs-prueba' ) ) : ?>
									<br><em><?php esc_html_e( 'Es el certificado de PRUEBA de Greenter. Solo vale para beta.', 'multisede-pos' ); ?></em>
								<?php endif; ?>
							<?php else : ?>
								❌ <?php esc_html_e( 'sin certificado utilizable', 'multisede-pos' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Entorno', 'multisede-pos' ); ?></td>
						<td><strong><?php echo esc_html( strtoupper( $a['entorno'] ) ); ?></strong></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Zona horaria del sitio', 'multisede-pos' ); ?></td>
						<td>
							<?php
							$tz = wp_timezone_string();
							echo esc_html( $tz );
							if ( 'America/Lima' !== $tz ) {
								echo ' — <strong>' . esc_html__( 'debería ser America/Lima: la fecha de emisión va en la boleta.', 'multisede-pos' ) . '</strong>';
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Ajustes', 'multisede-pos' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'msp_facturacion', 'msp_fact_nonce' ); ?>
				<input type="hidden" name="msp_fact_action" value="guardar" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="entorno"><?php esc_html_e( 'Entorno', 'multisede-pos' ); ?></label></th>
						<td>
							<select name="entorno" id="entorno">
								<option value="beta" <?php selected( $a['entorno'], 'beta' ); ?>><?php esc_html_e( 'Beta (pruebas)', 'multisede-pos' ); ?></option>
								<option value="produccion" <?php selected( $a['entorno'], 'produccion' ); ?>><?php esc_html_e( 'Producción', 'multisede-pos' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Emisión automática', 'multisede-pos' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="emision_automatica" value="1" <?php checked( ! empty( $a['emision_automatica'] ) ); ?> />
								<?php esc_html_e( 'Emitir boleta de cada venta del POS y de cada pedido web al recogerlo', 'multisede-pos' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'La emisión ocurre en segundo plano: el cobro no espera a SUNAT. Con esto apagado el POS vende igual, pero no genera comprobantes.', 'multisede-pos' ); ?>
								<?php if ( ! empty( $a['emision_automatica'] ) && MSP_Emisor::es_produccion() ) : ?>
									<br><strong><?php esc_html_e( 'Encendida y en producción: cada venta consume numeración real.', 'multisede-pos' ); ?></strong>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<?php if ( ! MSP_Emisor::es_produccion() ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Simular fallo de envío', 'multisede-pos' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="simular_fallo" value="1" <?php checked( ! empty( $a['simular_fallo'] ) ); ?> />
									<?php esc_html_e( 'Hacer que los envíos fallen a propósito', 'multisede-pos' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Solo para probar la cola de reintentos. El sandbox de SUNAT acepta cualquier clave SOL, así que es la única forma de ver qué pasa cuando el envío falla. Se ignora en producción; acuérdate de apagarlo igual.', 'multisede-pos' ); ?>
								</p>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="cert_path"><?php esc_html_e( 'Ruta del certificado PEM', 'multisede-pos' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" name="cert_path" id="cert_path" value="<?php echo esc_attr( $a['cert_path'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Mejor definirla como constante MSP_CERT_PATH en wp-config.php: así no se puede cambiar desde el panel ni viaja en los backups de la base de datos.', 'multisede-pos' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Credenciales SOL', 'multisede-pos' ); ?></th>
						<td>
							<input type="text" name="sol_usuario" value="<?php echo esc_attr( $a['sol_usuario'] ); ?>" placeholder="<?php esc_attr_e( 'usuario secundario', 'multisede-pos' ); ?>" />
							<input type="password" name="sol_clave" value="<?php echo esc_attr( $a['sol_clave'] ); ?>" placeholder="<?php esc_attr_e( 'clave', 'multisede-pos' ); ?>" autocomplete="new-password" />
							<p class="description">
								<?php esc_html_e( 'En beta: MODDATOS / moddatos. En producción, el usuario secundario de saraih.', 'multisede-pos' ); ?>
								<br><strong><?php esc_html_e( 'Ojo: el sandbox NO valida la clave.', 'multisede-pos' ); ?></strong>
								<?php esc_html_e( 'Acepta cualquiera mientras el usuario sea MODDATOS, así que unas credenciales correctas en beta no demuestran nada. Las reales se estrenan con la primera boleta de producción: hay que vigilarla.', 'multisede-pos' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Emisor', 'multisede-pos' ); ?></th>
						<td>
							<input type="text" name="ruc" value="<?php echo esc_attr( $a['ruc'] ); ?>" placeholder="RUC" />
							<input type="text" class="regular-text" name="razon_social" value="<?php echo esc_attr( $a['razon_social'] ); ?>" placeholder="<?php esc_attr_e( 'Razón social', 'multisede-pos' ); ?>" />
							<p class="description"><?php esc_html_e( 'Tal como figura en la ficha RUC, carácter por carácter. Es la causa número uno de rechazos.', 'multisede-pos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Domicilio fiscal', 'multisede-pos' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="direccion" value="<?php echo esc_attr( $a['direccion'] ); ?>" placeholder="<?php esc_attr_e( 'Dirección', 'multisede-pos' ); ?>" /><br>
							<input type="text" name="ubigeo" value="<?php echo esc_attr( $a['ubigeo'] ); ?>" placeholder="<?php esc_attr_e( 'Ubigeo', 'multisede-pos' ); ?>" />
							<input type="text" name="departamento" value="<?php echo esc_attr( $a['departamento'] ); ?>" placeholder="<?php esc_attr_e( 'Departamento', 'multisede-pos' ); ?>" />
							<input type="text" name="provincia" value="<?php echo esc_attr( $a['provincia'] ); ?>" placeholder="<?php esc_attr_e( 'Provincia', 'multisede-pos' ); ?>" />
							<input type="text" name="distrito" value="<?php echo esc_attr( $a['distrito'] ); ?>" placeholder="<?php esc_attr_e( 'Distrito', 'multisede-pos' ); ?>" />
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Impresión del ticket (ESC/POS · RawBT)', 'multisede-pos' ); ?></h2>
				<p class="description" style="max-width:44em">
					<?php esc_html_e( 'Para terminales cuya impresora no aparece en el diálogo de impresión de Chrome, como la iMin Falcon 1. Con esto encendido, el ticket muestra un botón que le entrega el trabajo a la app RawBT en vez de intentar imprimir desde el navegador. El ticket normal sigue funcionando igual en escritorio.', 'multisede-pos' ); ?>
				</p>
				<?php $esc = MSP_Ticket_EscPos::ajustes(); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Activar', 'multisede-pos' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="escpos_activo" value="1" <?php checked( $esc['activo'] ); ?> />
								<?php esc_html_e( 'Mostrar el botón de impresión por RawBT en el ticket', 'multisede-pos' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="escpos_columnas"><?php esc_html_e( 'Ancho en caracteres', 'multisede-pos' ); ?></label></th>
						<td>
							<input type="number" name="escpos_columnas" id="escpos_columnas" min="24" max="64" value="<?php echo esc_attr( $esc['columnas'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'En papel de 80 mm suelen entrar 48, pero el área imprimible real de algunos equipos deja 42. Imprime una regla de prueba desde RawBT y cuenta dónde corta: de este número depende que no se recorten los nombres de los productos.', 'multisede-pos' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="escpos_codepage"><?php esc_html_e( 'Página de códigos', 'multisede-pos' ); ?></label></th>
						<td>
							<select name="escpos_codepage" id="escpos_codepage">
								<?php foreach ( MSP_Ticket_EscPos::paginas_codigos() as $clave => $datos ) : ?>
									<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $esc['codepage'], $clave ); ?>>
										<?php echo esc_html( $datos[0] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Decide si la Ñ y las tildes salen bien. Si en el papel ves caracteres raros, prueba otra: es lo primero que hay que ajustar.', 'multisede-pos' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Comportamiento', 'multisede-pos' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="escpos_cortar" value="1" <?php checked( $esc['cortar'] ); ?> />
								<?php esc_html_e( 'Cortar el papel al terminar', 'multisede-pos' ); ?>
							</label><br>
							<label>
								<input type="checkbox" name="escpos_qr_nativo" value="1" <?php checked( $esc['qr_nativo'] ); ?> />
								<?php esc_html_e( 'Imprimir el QR con el comando propio de la impresora', 'multisede-pos' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Sale más nítido y más rápido que mandarlo como imagen. Si tu impresora no lo soporta, saldrá un QR en blanco: apágalo y el ticket se imprime sin él (imprime entonces por el navegador para el QR).', 'multisede-pos' ); ?>
							</p>
							<label>
								<input type="checkbox" name="escpos_auto" value="1" <?php checked( $esc['auto'] ); ?> />
								<?php esc_html_e( 'Imprimir solo al terminar una venta en el POS', 'multisede-pos' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Un clic menos por venta en mostrador. El ticket se abre y manda la impresión sin esperar.', 'multisede-pos' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="escpos_copias"><?php esc_html_e( 'Copias', 'multisede-pos' ); ?></label></th>
						<td>
							<input type="number" name="escpos_copias" id="escpos_copias" min="1" max="3" value="<?php echo esc_attr( $esc['copias'] ); ?>" />
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Consulta del RUC', 'multisede-pos' ); ?></h2>
				<p class="description" style="max-width:44em">
					<?php esc_html_e( 'Rellena sola la razón social al teclear el RUC, en el mostrador y en el checkout. SUNAT compara ese nombre con su padrón, así que un dedazo se convierte en una factura observada con la venta ya cobrada. Es una ayuda, no un requisito: si el padrón no contesta en dos segundos, el campo se queda editable y se escribe a mano.', 'multisede-pos' ); ?>
				</p>

				<?php $aj_ruc = MSP_Ruc::ajustes(); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Autocompletar', 'multisede-pos' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ruc_consulta_activa" value="1" <?php checked( $aj_ruc['activa'] ); ?> />
								<?php esc_html_e( 'Buscar la razón social al completar el RUC', 'multisede-pos' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ruc_consulta_endpoint"><?php esc_html_e( 'Padrón', 'multisede-pos' ); ?></label></th>
						<td>
							<input type="url" class="large-text code" name="ruc_consulta_endpoint" id="ruc_consulta_endpoint"
								value="<?php echo esc_attr( $aj_ruc['endpoint'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'El {ruc} de la dirección se reemplaza por el número. Por defecto se usa un padrón público y gratuito, sin registro. Aquí se cambia por uno de pago o por una copia propia del padrón de SUNAT sin tocar nada más.', 'multisede-pos' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Guardar ajustes', 'multisede-pos' ) ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Otras empresas emisoras', 'multisede-pos' ); ?></h2>
			<p class="description" style="max-width:44em">
				<?php esc_html_e( 'Para operar varias empresas (RUC) en la misma instalación. Cada sede emite con la empresa que se le asigne, con su propia serie y su propio certificado. La empresa de arriba es la principal y no hace falta añadirla aquí.', 'multisede-pos' ); ?>
				<br><strong><?php esc_html_e( 'Cada RUC necesita su propio certificado digital y su propio usuario SOL:', 'multisede-pos' ); ?></strong>
				<?php esc_html_e( 'el certificado de una empresa no puede firmar los comprobantes de otra.', 'multisede-pos' ); ?>
			</p>

			<?php $otros = array_filter( MSP_Emisor::emisores(), function ( $e ) { return empty( $e['principal'] ); } ); ?>

			<?php if ( $otros ) : ?>
				<table class="widefat striped" style="max-width:60em;margin-bottom:1em">
					<thead>
						<tr>
							<th><?php esc_html_e( 'RUC', 'multisede-pos' ); ?></th>
							<th><?php esc_html_e( 'Razón social', 'multisede-pos' ); ?></th>
							<th><?php esc_html_e( 'Usuario SOL', 'multisede-pos' ); ?></th>
							<th><?php esc_html_e( 'Certificado', 'multisede-pos' ); ?></th>
							<th><?php esc_html_e( 'Sedes', 'multisede-pos' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $otros as $ruc => $e ) : ?>
							<?php
							$cert  = MSP_Emisor::ruta_certificado( $ruc );
							$sedes = $this->sedes_del_emisor( $ruc );
							?>
							<tr>
								<td><code><?php echo esc_html( $ruc ); ?></code></td>
								<td><?php echo esc_html( $e['razon_social'] ); ?></td>
								<td><?php echo esc_html( $e['sol_usuario'] ); ?></td>
								<td>
									<?php if ( $cert ) : ?>
										<span style="color:#008a20">✅ <?php echo esc_html( basename( $cert ) ); ?></span>
									<?php else : ?>
										<span style="color:#b32d2e">⚠️ <?php esc_html_e( 'sin certificado', 'multisede-pos' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( count( $sedes ) ); ?></td>
								<td>
									<form method="post" onsubmit="return confirm('<?php esc_attr_e( '¿Eliminar esta empresa emisora?', 'multisede-pos' ); ?>')">
										<?php wp_nonce_field( 'msp_facturacion', 'msp_fact_nonce' ); ?>
										<input type="hidden" name="msp_fact_action" value="borrar_emisor" />
										<input type="hidden" name="ruc_emisor" value="<?php echo esc_attr( $ruc ); ?>" />
										<button type="submit" class="button-link-delete button-link"><?php esc_html_e( 'Eliminar', 'multisede-pos' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'msp_facturacion', 'msp_fact_nonce' ); ?>
				<input type="hidden" name="msp_fact_action" value="guardar_emisor" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ruc_emisor"><?php esc_html_e( 'RUC y razón social', 'multisede-pos' ); ?></label></th>
						<td>
							<input type="text" name="ruc_emisor" id="ruc_emisor" maxlength="11" placeholder="RUC" />
							<input type="text" class="regular-text" name="emisor_razon_social" placeholder="<?php esc_attr_e( 'Razón social', 'multisede-pos' ); ?>" />
							<p class="description"><?php esc_html_e( 'Si el RUC ya está en la lista, se actualizan sus datos.', 'multisede-pos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Clave SOL', 'multisede-pos' ); ?></th>
						<td>
							<input type="text" name="emisor_sol_usuario" placeholder="<?php esc_attr_e( 'usuario secundario', 'multisede-pos' ); ?>" />
							<input type="password" name="emisor_sol_clave" placeholder="<?php esc_attr_e( 'clave', 'multisede-pos' ); ?>" autocomplete="new-password" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Domicilio fiscal', 'multisede-pos' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="emisor_direccion" placeholder="<?php esc_attr_e( 'Dirección', 'multisede-pos' ); ?>" /><br>
							<input type="text" name="emisor_ubigeo" placeholder="<?php esc_attr_e( 'Ubigeo', 'multisede-pos' ); ?>" />
							<input type="text" name="emisor_departamento" placeholder="<?php esc_attr_e( 'Departamento', 'multisede-pos' ); ?>" />
							<input type="text" name="emisor_provincia" placeholder="<?php esc_attr_e( 'Provincia', 'multisede-pos' ); ?>" />
							<input type="text" name="emisor_distrito" placeholder="<?php esc_attr_e( 'Distrito', 'multisede-pos' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Certificado', 'multisede-pos' ); ?></th>
						<td>
							<p class="description" style="max-width:44em">
								<?php
								printf(
									/* translators: %s: nombre de la constante. */
									esc_html__( 'El certificado NO se sube por aquí: es una clave privada de firma y en la base de datos viajaría en cada copia de seguridad. Define %s en wp-config.php apuntando a una carpeta fuera del webroot y deja dentro un archivo por empresa, nombrado con su RUC.', 'multisede-pos' ),
									'<code>MSP_CERT_DIR</code>'
								);
								?>
								<br><code>define( 'MSP_CERT_DIR', dirname( __FILE__ ) . '/../private/certs-msp' );</code>
								<br><?php esc_html_e( 'Y dentro: 20526693320.pem, 10426013393.pem…', 'multisede-pos' ); ?>
								<br><strong><?php esc_html_e( 'El plugin compone el nombre desde el RUC', 'multisede-pos' ); ?></strong>
								<?php esc_html_e( 'a propósito: sin campo de ruta, nadie puede apuntar una sede al certificado de la otra empresa, que es el error que de verdad ocurre.', 'multisede-pos' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Guardar empresa emisora', 'multisede-pos' ), 'secondary' ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Probar las credenciales SOL', 'multisede-pos' ); ?></h2>
			<p>
				<?php esc_html_e( 'Comprueba que el usuario y la clave sirven, sin emitir nada: se envía a propósito un archivo que no es un comprobante, así que SUNAT solo puede objetar el contenido. No se consume numeración.', 'multisede-pos' ); ?>
				<br><strong><?php esc_html_e( 'Solo sirve con el entorno en producción.', 'multisede-pos' ); ?></strong>
				<?php esc_html_e( 'El sandbox acepta cualquier usuario y clave, incluso inventados, así que ahí esta prueba no demuestra nada.', 'multisede-pos' ); ?>
				<br><?php esc_html_e( 'El día del estreno, hazla DOS veces: primero con la clave mal escrita a propósito (debe decir que son incorrectas) y luego con la buena. La primera comprueba que el detector funciona; la segunda, que las credenciales sirven.', 'multisede-pos' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'msp_facturacion', 'msp_fact_nonce' ); ?>
				<input type="hidden" name="msp_fact_action" value="credenciales" />
				<?php submit_button( __( 'Probar credenciales', 'multisede-pos' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Emitir una boleta de prueba', 'multisede-pos' ); ?></h2>
			<p><?php esc_html_e( 'Reserva un correlativo real de la serie de esa sede y lo envía a SUNAT. Úsalo solo en beta.', 'multisede-pos' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'msp_facturacion', 'msp_fact_nonce' ); ?>
				<input type="hidden" name="msp_fact_action" value="probar" />
				<select name="sede_prueba">
					<?php foreach ( $sedes as $sede ) : ?>
						<?php $serie = MSP_Comprobante::serie_de_sede( $sede->ID ); ?>
						<option value="<?php echo esc_attr( $sede->ID ); ?>" <?php disabled( ! $serie ); ?>>
							<?php echo esc_html( $sede->post_title . ( $serie ? " ({$serie})" : __( ' — sin serie', 'multisede-pos' ) ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input type="number" step="0.01" min="0.10" name="total_prueba" value="15.00" style="width:100px" />
				<?php submit_button( __( 'Emitir prueba', 'multisede-pos' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
