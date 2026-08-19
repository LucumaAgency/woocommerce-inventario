<?php
/**
 * Pantalla "Comprobantes": qué se emitió, qué falta y qué se atascó.
 *
 * Fase 3. Es la pantalla de gerencia del módulo de boletas. Sin ella la cola
 * sería una caja negra: nadie sabría que una venta de hace tres días todavía no
 * le consta a SUNAT hasta que SUNAT lo preguntara.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listado, reintento manual y descarga de XML/CDR.
 */
class MSP_Comprobantes {

	const PAGE = 'msp-comprobantes';

	/** Filas por página. */
	const POR_PAGINA = 30;

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'registrar_pagina' ) );
		add_action( 'admin_init', array( $this, 'procesar' ) );
	}

	/**
	 * Registra la pantalla bajo Caja.
	 */
	public function registrar_pagina() {
		add_submenu_page(
			'msp-caja',
			__( 'Comprobantes electrónicos', 'multisede-pos' ),
			__( 'Comprobantes', 'multisede-pos' ),
			'msp_ver_reportes',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Reintento manual y descarga de archivos.
	 */
	public function procesar() {
		if ( ! isset( $_GET['msp_comp_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'msp_ver_reportes' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'multisede-pos' ) );
		}

		$accion = sanitize_key( wp_unslash( $_GET['msp_comp_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = isset( $_GET['comprobante'] ) ? absint( wp_unslash( $_GET['comprobante'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'msp_comp_' . $accion . '_' . $id );

		if ( 'descargar' === $accion ) {
			$this->descargar( $id, isset( $_GET['tipo'] ) ? sanitize_key( wp_unslash( $_GET['tipo'] ) ) : 'xml' );
			exit;
		}

		$aviso = '';

		if ( 'rehacer_baja' === $accion ) {
			$c = MSP_Comprobante::obtener( $id );
			if ( ! $c ) {
				$aviso = __( 'Ese comprobante no existe.', 'multisede-pos' );
			} elseif ( 'rechazada' !== $c['baja_estado'] ) {
				$aviso = __( 'Esa baja no está rechazada.', 'multisede-pos' );
			} else {
				// Vuelve a la cola de bajas para entrar en un resumen NUEVO. El
				// anterior queda como estaba: un resumen rechazado por SUNAT no
				// se reenvía con el mismo número, se sustituye por otro.
				MSP_Comprobante::actualizar(
					$id,
					array(
						'baja_estado' => 'pendiente',
						'resumen_id'  => 0,
					)
				);
				$aviso = __( 'La baja vuelve a la cola. Pulsa "Procesar todo ahora" en Pruebas, o espera al barrido.', 'multisede-pos' );
			}
		} elseif ( 'reintentar' === $accion ) {
			$c = MSP_Comprobante::obtener( $id );
			if ( ! $c ) {
				$aviso = __( 'Ese comprobante no existe.', 'multisede-pos' );
			} elseif ( 'aceptado' === $c['estado'] ) {
				$aviso = __( 'Ya está aceptado: no hay nada que reintentar.', 'multisede-pos' );
			} elseif ( $c['entorno'] !== MSP_Comprobante::entorno_actual() ) {
				// Se comprueba ANTES de tocar el estado: si no, la fila pasaría a
				// "En cola" para nada y quien mirara la pantalla no entendería por
				// qué su reintento no hizo nada.
				$aviso = sprintf(
					/* translators: 1: entorno del comprobante, 2: entorno activo. */
					__( 'No se envía: ese comprobante es de %1$s y el sitio está ahora en %2$s. Emitirlo desde aquí sería mandar a SUNAT un documento que pertenece a otro entorno.', 'multisede-pos' ),
					strtoupper( $c['entorno'] ),
					strtoupper( MSP_Comprobante::entorno_actual() )
				);
			} else {
				// Un rechazado vuelve a "pendiente" antes de reintentar: si no,
				// MSP_Cola::procesar lo daría por caso cerrado y no lo enviaría.
				MSP_Comprobante::actualizar( $id, array( 'estado' => 'pendiente' ) );
				MSP_Cola::procesar( $id );
				$actual = MSP_Comprobante::obtener( $id );
				$aviso  = sprintf(
					/* translators: 1: número, 2: estado. */
					__( 'Comprobante %1$s → %2$s', 'multisede-pos' ),
					MSP_Comprobante::numero( $actual ),
					strtoupper( $actual['estado'] )
				);
			}
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
	 * Entrega el XML firmado o el CDR de un comprobante.
	 *
	 * Los archivos viven fuera del alcance del navegador a propósito (uploads
	 * con .htaccess), así que se sirven desde aquí, comprobando permiso.
	 *
	 * @param int    $id   ID del comprobante.
	 * @param string $tipo 'xml' o 'cdr'.
	 */
	private function descargar( $id, $tipo ) {
		$c = MSP_Comprobante::obtener( $id );
		if ( ! $c ) {
			wp_die( esc_html__( 'Ese comprobante no existe.', 'multisede-pos' ) );
		}

		$ruta = 'cdr' === $tipo ? $c['cdr_path'] : $c['xml_path'];
		if ( ! $ruta || ! file_exists( $ruta ) ) {
			wp_die( esc_html__( 'El archivo no está disponible.', 'multisede-pos' ) );
		}

		// La ruta viene de la base de datos, no del navegador, pero se ancla
		// igual a la carpeta del módulo: si alguien la manipulara, no debe
		// poder leer wp-config.php con ella.
		$base = MSP_Emisor::carpeta_archivos();
		if ( is_wp_error( $base ) || 0 !== strpos( realpath( $ruta ), realpath( $base ) ) ) {
			wp_die( esc_html__( 'Ruta de archivo no permitida.', 'multisede-pos' ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( 'cdr' === $tipo ? 'application/zip' : 'application/xml' ) );
		header( 'Content-Disposition: attachment; filename="' . basename( $ruta ) . '"' );
		header( 'Content-Length: ' . filesize( $ruta ) );
		readfile( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
	}

	/**
	 * Etiqueta y color de cada estado.
	 *
	 * @param string $estado Estado guardado.
	 * @return array {texto, color}
	 */
	private function pinta_estado( $estado ) {
		$mapa = array(
			'pendiente' => array( __( 'En cola', 'multisede-pos' ), '#996800' ),
			'aceptado'  => array( __( 'Aceptado', 'multisede-pos' ), '#1a7f37' ),
			'error'     => array( __( 'Reintentando', 'multisede-pos' ), '#996800' ),
			'rechazado' => array( __( 'Rechazado', 'multisede-pos' ), '#b32d2e' ),
		);
		return isset( $mapa[ $estado ] ) ? $mapa[ $estado ] : array( $estado, '#50575e' );
	}

	/**
	 * Etiqueta del estado de baja de un comprobante.
	 *
	 * @param string $baja Estado guardado.
	 * @return array {texto, color} o array vacío si no aplica.
	 */
	private function pinta_baja( $baja ) {
		$mapa = array(
			'pendiente'      => array( __( 'Baja pendiente', 'multisede-pos' ), '#996800' ),
			'enviada'        => array( __( 'Baja enviada', 'multisede-pos' ), '#996800' ),
			'anulado'        => array( __( 'ANULADO ante SUNAT', 'multisede-pos' ), '#50575e' ),
			'rechazada'      => array( __( 'SUNAT rechazó la baja', 'multisede-pos' ), '#b32d2e' ),
			'fuera_de_plazo' => array( __( 'Fuera de plazo: requiere nota de crédito', 'multisede-pos' ), '#b32d2e' ),
			'no_aplica'      => array( __( 'Anulada antes de emitirse', 'multisede-pos' ), '#50575e' ),
		);
		return isset( $mapa[ $baja ] ) ? $mapa[ $baja ] : array();
	}

	/**
	 * URL de una acción con su nonce.
	 *
	 * @param string $accion Acción.
	 * @param int    $id     Comprobante.
	 * @param array  $extra  Parámetros extra.
	 * @return string
	 */
	private function url_accion( $accion, $id, $extra = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page'            => self::PAGE,
					'msp_comp_action' => $accion,
					'comprobante'     => (int) $id,
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
		return wp_nonce_url( $url, 'msp_comp_' . $accion . '_' . (int) $id );
	}

	/**
	 * Pinta la pantalla.
	 */
	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$estado  = isset( $_GET['estado'] ) ? sanitize_key( wp_unslash( $_GET['estado'] ) ) : '';
		$sede_id = isset( $_GET['sede'] ) ? absint( wp_unslash( $_GET['sede'] ) ) : 0;
		$buscar  = isset( $_GET['buscar'] ) ? sanitize_text_field( wp_unslash( $_GET['buscar'] ) ) : '';
		$pagina  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$aviso   = isset( $_GET['aviso'] ) ? sanitize_text_field( wp_unslash( $_GET['aviso'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Por defecto se ve SOLO el entorno activo. Mezclar pruebas y boletas
		// reales en la misma lista es la forma más fácil de que alguien lea un
		// número de prueba como si fuera una venta.
		$entorno = isset( $_GET['entorno'] ) ? sanitize_key( wp_unslash( $_GET['entorno'] ) ) : MSP_Comprobante::entorno_actual(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'todos' === $entorno ) {
			$entorno = '';
		}

		$datos = MSP_Comprobante::listar(
			array(
				'estado'  => $estado,
				'sede_id' => $sede_id,
				'entorno' => $entorno,
				'buscar'  => $buscar,
				'por_pag' => self::POR_PAGINA,
				'pagina'  => $pagina,
			)
		);

		$conteo = MSP_Comprobante::contar_por_estado( $entorno );
		$sedes  = MSP_Sedes::obtener_sedes_activas();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Comprobantes electrónicos', 'multisede-pos' ); ?></h1>

			<p>
				<?php if ( '' === $entorno ) : ?>
					<strong><?php esc_html_e( 'Viendo: todos los entornos.', 'multisede-pos' ); ?></strong>
				<?php elseif ( 'produccion' === $entorno ) : ?>
					<strong><?php esc_html_e( 'Viendo: comprobantes REALES (producción).', 'multisede-pos' ); ?></strong>
				<?php else : ?>
					<strong><?php esc_html_e( 'Viendo: comprobantes de PRUEBA (beta).', 'multisede-pos' ); ?></strong>
					<?php esc_html_e( 'No existen para SUNAT y llevan su propia numeración.', 'multisede-pos' ); ?>
				<?php endif; ?>
				<?php
				$otros = array(
					'beta'       => __( 'ver pruebas', 'multisede-pos' ),
					'produccion' => __( 'ver reales', 'multisede-pos' ),
					'todos'      => __( 'ver todos', 'multisede-pos' ),
				);
				foreach ( $otros as $clave => $texto ) :
					if ( $clave === $entorno || ( 'todos' === $clave && '' === $entorno ) ) {
						continue;
					}
					?>
					· <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'entorno' => $clave ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $texto ); ?></a>
				<?php endforeach; ?>
			</p>

			<?php if ( $aviso ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $aviso ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! MSP_Cola::activa() ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'La emisión automática está apagada: las ventas del POS no generan boleta todavía.', 'multisede-pos' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MSP_Facturacion::PAGE ) ); ?>"><?php esc_html_e( 'Ajustes de facturación', 'multisede-pos' ); ?></a>
				</p></div>
			<?php endif; ?>

			<?php if ( ! empty( $conteo['rechazado'] ) || ! empty( $conteo['error'] ) ) : ?>
				<div class="notice notice-error"><p>
					<?php
					printf(
						/* translators: 1: rechazados, 2: con error. */
						esc_html__( 'Atención: %1$d rechazado(s) y %2$d con error. Mientras no estén aceptados, esas ventas no le constan a SUNAT.', 'multisede-pos' ),
						(int) ( isset( $conteo['rechazado'] ) ? $conteo['rechazado'] : 0 ),
						(int) ( isset( $conteo['error'] ) ? $conteo['error'] : 0 )
					);
					?>
				</p></div>
			<?php endif; ?>

			<ul class="subsubsub">
				<?php
				$filtros = array(
					''          => __( 'Todos', 'multisede-pos' ),
					'pendiente' => __( 'En cola', 'multisede-pos' ),
					'aceptado'  => __( 'Aceptados', 'multisede-pos' ),
					'error'     => __( 'Reintentando', 'multisede-pos' ),
					'rechazado' => __( 'Rechazados', 'multisede-pos' ),
				);
				$ultimo  = array_key_last( $filtros );
				foreach ( $filtros as $clave => $texto ) :
					$n = '' === $clave ? array_sum( $conteo ) : ( isset( $conteo[ $clave ] ) ? $conteo[ $clave ] : 0 );
					?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'estado' => $clave, 'entorno' => $entorno ? $entorno : 'todos' ), admin_url( 'admin.php' ) ) ); ?>"
							class="<?php echo $estado === $clave ? 'current' : ''; ?>">
							<?php echo esc_html( $texto ); ?> <span class="count">(<?php echo (int) $n; ?>)</span>
						</a><?php echo $clave === $ultimo ? '' : ' |'; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="get" style="margin:12px 0;clear:both">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<input type="hidden" name="estado" value="<?php echo esc_attr( $estado ); ?>" />
				<input type="hidden" name="entorno" value="<?php echo esc_attr( $entorno ? $entorno : 'todos' ); ?>" />
				<select name="sede">
					<option value="0"><?php esc_html_e( 'Todas las tiendas', 'multisede-pos' ); ?></option>
					<?php foreach ( $sedes as $sede ) : ?>
						<option value="<?php echo esc_attr( $sede->ID ); ?>" <?php selected( $sede_id, $sede->ID ); ?>>
							<?php echo esc_html( $sede->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="buscar" value="<?php echo esc_attr( $buscar ); ?>" placeholder="<?php esc_attr_e( 'B001-00000042 o nº de pedido', 'multisede-pos' ); ?>" />
				<?php submit_button( __( 'Filtrar', 'multisede-pos' ), 'secondary', '', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Comprobante', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Tienda', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Cliente', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Total', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Emitido', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Acciones', 'multisede-pos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $datos['filas'] ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'Todavía no hay comprobantes con ese filtro.', 'multisede-pos' ); ?></td></tr>
					<?php endif; ?>

					<?php foreach ( $datos['filas'] as $c ) : ?>
						<?php list( $texto_estado, $color ) = $this->pinta_estado( $c['estado'] ); ?>
						<tr>
							<td>
								<strong><?php echo esc_html( MSP_Comprobante::numero( $c ) ); ?></strong>
								<?php if ( 'produccion' !== $c['entorno'] ) : ?>
									<span style="background:#f0f0f1;color:#50575e;border-radius:3px;padding:1px 5px;font-size:11px"><?php esc_html_e( 'prueba', 'multisede-pos' ); ?></span>
								<?php endif; ?>
								<?php if ( $c['pedido_id'] ) : ?>
									<br><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $c['pedido_id'] . '&action=edit' ) ); ?>">
										<?php printf( '#%d', (int) $c['pedido_id'] ); ?>
									</a>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( get_the_title( (int) $c['sede_id'] ) ); ?></td>
							<td>
								<?php echo esc_html( $c['cliente_nombre'] ); ?>
								<?php if ( $c['cliente_num_doc'] ) : ?>
									<br><small><?php echo esc_html( $c['cliente_num_doc'] ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( 'S/ ' . number_format( (float) $c['total'], 2 ) ); ?></td>
							<td><?php echo esc_html( $c['emitido_at'] ); ?></td>
							<td>
								<span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600">
									<?php echo esc_html( $texto_estado ); ?>
								</span>
								<?php if ( (int) $c['intentos'] > 1 ) : ?>
									<br><small><?php
										printf(
											/* translators: %d: número de intentos. */
											esc_html__( '%d intentos', 'multisede-pos' ),
											(int) $c['intentos']
										);
									?></small>
								<?php endif; ?>
								<?php if ( $c['ultimo_error'] ) : ?>
									<br><small style="color:#b32d2e"><?php echo esc_html( $c['ultimo_error'] ); ?></small>
								<?php endif; ?>
								<?php
							$baja = $this->pinta_baja( $c['baja_estado'] );
							if ( $baja ) :
								?>
								<br><span style="color:<?php echo esc_attr( $baja[1] ); ?>;font-weight:600">
									<?php echo esc_html( $baja[0] ); ?>
								</span>
							<?php endif; ?>
							<?php if ( ! empty( $c['proximo_intento'] ) ) : ?>
									<br><small><?php
										printf(
											/* translators: %s: fecha y hora. */
											esc_html__( 'Siguiente intento: %s', 'multisede-pos' ),
											esc_html( $c['proximo_intento'] )
										);
									?></small>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 'aceptado' !== $c['estado'] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $this->url_accion( 'reintentar', $c['id'] ) ); ?>">
										<?php esc_html_e( 'Reintentar', 'multisede-pos' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( 'rechazada' === $c['baja_estado'] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $this->url_accion( 'rehacer_baja', $c['id'] ) ); ?>">
										<?php esc_html_e( 'Rehacer baja', 'multisede-pos' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( 'aceptado' === $c['estado'] ) : ?>
									<a class="button button-small button-primary" target="_blank" rel="noopener"
										href="<?php echo esc_url( MSP_Ticket::url( $c['id'] ) ); ?>">
										<?php esc_html_e( 'Ticket', 'multisede-pos' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( $c['xml_path'] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $this->url_accion( 'descargar', $c['id'], array( 'tipo' => 'xml' ) ) ); ?>">XML</a>
								<?php endif; ?>
								<?php if ( $c['cdr_path'] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $this->url_accion( 'descargar', $c['id'], array( 'tipo' => 'cdr' ) ) ); ?>">CDR</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php $this->tabla_resumenes(); ?>

			<?php
			$paginas = (int) ceil( $datos['total'] / self::POR_PAGINA );
			if ( $paginas > 1 ) :
				?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $pagina,
								'total'     => $paginas,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Tabla de resúmenes diarios (las bajas comunicadas a SUNAT).
	 */
	private function tabla_resumenes() {
		if ( ! class_exists( 'MSP_Resumen' ) ) {
			return;
		}

		$resumenes = MSP_Resumen::ultimos( 20 );
		if ( ! $resumenes ) {
			return;
		}

		$mapa = array(
			'pendiente' => array( __( 'Por enviar', 'multisede-pos' ), '#996800' ),
			'enviado'   => array( __( 'Esperando a SUNAT', 'multisede-pos' ), '#996800' ),
			'aceptado'  => array( __( 'Aceptado', 'multisede-pos' ), '#1a7f37' ),
			'rechazado' => array( __( 'Rechazado', 'multisede-pos' ), '#b32d2e' ),
			'error'     => array( __( 'Reintentando', 'multisede-pos' ), '#996800' ),
		);
		?>
		<h2 style="margin-top:32px"><?php esc_html_e( 'Bajas comunicadas a SUNAT', 'multisede-pos' ); ?></h2>
		<p class="description" style="max-width:70ch">
			<?php esc_html_e( 'Una boleta no se borra: se comunica su baja en un resumen diario. El envío es asíncrono, así que un resumen pasa por "esperando a SUNAT" antes de resolverse; es normal.', 'multisede-pos' ); ?>
		</p>
		<table class="widefat striped" style="max-width:900px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Resumen', 'multisede-pos' ); ?></th>
					<th><?php esc_html_e( 'Comprobantes del día', 'multisede-pos' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'multisede-pos' ); ?></th>
					<th><?php esc_html_e( 'CDR', 'multisede-pos' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $resumenes as $r ) : ?>
					<?php $pinta = isset( $mapa[ $r['estado'] ] ) ? $mapa[ $r['estado'] ] : array( $r['estado'], '#50575e' ); ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $r['identificador'] ); ?></strong><br>
							<small><?php echo esc_html( $r['creado_at'] ); ?></small>
						</td>
						<td>
							<?php
							$numeros = array();
							foreach ( MSP_Resumen::comprobantes( (int) $r['id'] ) as $c ) {
								$numeros[] = MSP_Comprobante::numero( $c );
							}
							echo esc_html( $numeros ? implode( ', ', $numeros ) : '—' );
							?>
						</td>
						<td>
							<span style="color:<?php echo esc_attr( $pinta[1] ); ?>;font-weight:600"><?php echo esc_html( $pinta[0] ); ?></span>
							<?php if ( $r['ultimo_error'] ) : ?>
								<br><small style="color:#b32d2e"><?php echo esc_html( $r['ultimo_error'] ); ?></small>
							<?php endif; ?>
							<?php if ( $r['ticket'] ) : ?>
								<br><small><?php
									printf(
										/* translators: %s: número de ticket de SUNAT. */
										esc_html__( 'Ticket %s', 'multisede-pos' ),
										esc_html( $r['ticket'] )
									);
								?></small>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $r['cdr_path'] ) : ?>
								✅
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
