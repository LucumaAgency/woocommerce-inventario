<?php
/**
 * Cajas abiertas ahora mismo: la vista de gerencia que faltaba.
 *
 * La pantalla de Caja es **personal**: siempre consulta la sesión del usuario
 * que la mira, también si es administrador. Y el historial de cierres solo
 * muestra turnos **ya cerrados**. Resultado: nadie podía ver qué cajas están
 * abiertas en este momento, de quién, con cuánto y desde cuándo.
 *
 * Para supervisar una tienda es justo lo que hace falta: un descuadre se aclara
 * mejor mientras la persona sigue en el mostrador que al día siguiente, cuando
 * ya nadie se acuerda de aquel billete de cien.
 *
 * Es el hallazgo I de `AVANCE.md` §4 quinquies.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listado de turnos de caja abiertos, por sede.
 */
class MSP_Cajas_Abiertas {

	const PAGE = 'msp-cajas-abiertas';

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'registrar_pagina' ) );
	}

	/**
	 * Registra la pantalla bajo Caja.
	 */
	public function registrar_pagina() {
		add_submenu_page(
			'msp-caja',
			__( 'Cajas abiertas', 'multisede-pos' ),
			__( 'Cajas abiertas', 'multisede-pos' ),
			'msp_ver_reportes',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Turnos abiertos, de todas las sedes.
	 *
	 * Excluye las cajas de **práctica** del asistente: no son dinero real y
	 * mezclarlas aquí haría dudar de la cifra que se supone que hay en el cajón.
	 *
	 * @return array
	 */
	public static function abiertas() {
		global $wpdb;

		$tabla = MSP_Caja::tabla_sesiones();

		return (array) $wpdb->get_results(
			"SELECT * FROM {$tabla}
			 WHERE estado = 'abierta' AND es_practica = 0
			 ORDER BY sede_id ASC, abierta_at ASC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Tiempo transcurrido desde la apertura, en palabras.
	 *
	 * @param string $desde Fecha de apertura (hora local).
	 * @return string
	 */
	private function abierta_desde_hace( $desde ) {
		$inicio = strtotime( $desde );
		$ahora  = strtotime( current_time( 'mysql' ) );

		if ( ! $inicio || $ahora <= $inicio ) {
			return __( 'recién abierta', 'multisede-pos' );
		}

		return sprintf(
			/* translators: %s: tiempo transcurrido, ej. "3 horas". */
			__( 'hace %s', 'multisede-pos' ),
			human_time_diff( $inicio, $ahora )
		);
	}

	/**
	 * Pinta la pantalla.
	 */
	public function render() {
		$sesiones = self::abiertas();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cajas abiertas', 'multisede-pos' ); ?></h1>
			<p class="description" style="max-width:70ch">
				<?php esc_html_e( 'Turnos de caja abiertos en este momento, en todas las tiendas. El efectivo esperado es lo que debería haber en el cajón ahora mismo: apertura + ingresos + ventas en efectivo − egresos.', 'multisede-pos' ); ?>
			</p>

			<?php if ( ! $sesiones ) : ?>
				<div class="notice notice-info inline" style="margin-top:16px"><p>
					<?php esc_html_e( 'Ahora mismo no hay ninguna caja abierta.', 'multisede-pos' ); ?>
				</p></div>
				</div>
				<?php
				return;
			endif;
			?>

			<table class="widefat striped" style="margin-top:16px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Tienda', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Cajero', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Abierta', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Apertura', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Ventas en efectivo', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Ingresos / egresos', 'multisede-pos' ); ?></th>
						<th><?php esc_html_e( 'Debería haber en el cajón', 'multisede-pos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$total_esperado = 0.0;
					foreach ( $sesiones as $s ) :
						$totales  = MSP_Caja::totales( $s->id );
						$esperado = MSP_Caja::esperado( $s );
						$total_esperado += $esperado;
						$cajero    = get_userdata( (int) $s->cajero_id );
						?>
						<tr>
							<td><strong><?php echo esc_html( get_the_title( (int) $s->sede_id ) ); ?></strong></td>
							<td><?php echo esc_html( $cajero ? $cajero->display_name : __( 'usuario borrado', 'multisede-pos' ) ); ?></td>
							<td>
								<?php echo esc_html( $this->abierta_desde_hace( $s->abierta_at ) ); ?><br>
								<small><?php echo esc_html( $s->abierta_at ); ?></small>
							</td>
							<td><?php echo wp_kses_post( wc_price( (float) $s->monto_apertura ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $totales['ventas'] ) ); ?></td>
							<td>
								+<?php echo wp_kses_post( wc_price( $totales['ingresos'] ) ); ?>
								/ −<?php echo wp_kses_post( wc_price( $totales['egresos'] ) ); ?>
							</td>
							<td><strong><?php echo wp_kses_post( wc_price( $esperado ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th colspan="6" style="text-align:right">
							<?php
							printf(
								/* translators: %d: número de cajas abiertas. */
								esc_html__( 'Efectivo en mostrador ahora (%d cajas)', 'multisede-pos' ),
								count( $sesiones )
							);
							?>
						</th>
						<th><?php echo wp_kses_post( wc_price( $total_esperado ) ); ?></th>
					</tr>
				</tfoot>
			</table>

			<p class="description" style="margin-top:12px">
				<?php esc_html_e( 'Las cajas de práctica del asistente no aparecen aquí: no son dinero real.', 'multisede-pos' ); ?>
			</p>
		</div>
		<?php
	}
}
