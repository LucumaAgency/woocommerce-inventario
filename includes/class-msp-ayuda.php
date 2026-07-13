<?php
/**
 * Página de ayuda: cómo se usa el sistema en el día a día.
 *
 * El contenido se adapta al rol: el cajero ve lo suyo (caja y POS) y el
 * gerente/administrador ve además inventario, pedidos web y configuración.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual de uso dentro del propio panel.
 */
class MSP_Ayuda {

	const PAGE = 'msp-ayuda';

	/**
	 * Engancha hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'registrar_pagina' ) );
		add_filter( 'plugin_action_links_' . MSP_PLUGIN_BASENAME, array( $this, 'enlace_plugin' ) );
	}

	/**
	 * Registra la página de ayuda.
	 */
	public function registrar_pagina() {
		add_menu_page(
			__( 'Ayuda de Multisede POS', 'multisede-pos' ),
			__( 'Ayuda', 'multisede-pos' ),
			'msp_ver_stock',
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-editor-help',
			59
		);
	}

	/**
	 * Enlace a la ayuda desde el listado de plugins.
	 *
	 * @param array $enlaces Enlaces de acción.
	 * @return array
	 */
	public function enlace_plugin( $enlaces ) {
		$enlaces[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' .
			esc_html__( 'Ayuda', 'multisede-pos' ) . '</a>';
		return $enlaces;
	}

	/**
	 * URL del listado de pedidos (cambia según WooCommerce use HPOS o no).
	 *
	 * @return string
	 */
	public static function url_pedidos() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
			\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders' );
		}
		return admin_url( 'edit.php?post_type=shop_order' );
	}

	/* ---------------------------------------------------------------------
	 * Vista
	 * ------------------------------------------------------------------- */

	/**
	 * Renderiza la ayuda.
	 */
	public function render() {
		$es_admin   = current_user_can( 'manage_options' );
		$es_gerente = current_user_can( 'msp_gestionar_stock' );
		$mis_sedes  = MSP_Roles::sedes_de_usuario( get_current_user_id() );
		?>
		<div class="wrap msp-ayuda">
			<h1><?php esc_html_e( 'Cómo usar Multisede POS', 'multisede-pos' ); ?></h1>

			<style>
				.msp-ayuda .msp-card{background:#fff;border:1px solid #dcdcde;border-left:4px solid #1C8E80;border-radius:8px;padding:18px 22px;margin:0 0 16px;max-width:900px}
				.msp-ayuda .msp-card h2{margin-top:0}
				.msp-ayuda ol,.msp-ayuda ul{margin-left:18px}
				.msp-ayuda li{margin-bottom:6px}
				.msp-ayuda .msp-nota{background:#fcf9e8;border-left-color:#dba617}
				.msp-ayuda .msp-concepto{background:#f6f7f7;border-left-color:#787c82}
				.msp-ayuda code{background:#f0f0f1;padding:2px 5px;border-radius:3px}
			</style>

			<?php $this->aviso_sin_sede( $es_admin, $mis_sedes ); ?>

			<div class="msp-card msp-concepto">
				<h2><?php esc_html_e( 'Lo único que hay que entender', 'multisede-pos' ); ?></h2>
				<p><?php esc_html_e( 'Cada tienda (sede) tiene su propio stock. Un mismo producto puede tener 5 unidades en una sede y 0 en otra: son inventarios separados.', 'multisede-pos' ); ?></p>
				<p><?php esc_html_e( 'De ahí salen tres números que verás en todas partes:', 'multisede-pos' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Stock', 'multisede-pos' ); ?></strong>: <?php esc_html_e( 'lo que hay físicamente en la repisa de esa tienda.', 'multisede-pos' ); ?></li>
					<li><strong><?php esc_html_e( 'Reservado', 'multisede-pos' ); ?></strong>: <?php esc_html_e( 'unidades de un pedido web ya pagado que el cliente todavía no ha venido a recoger. Siguen en la tienda, pero tienen dueño: no se pueden vender a otra persona.', 'multisede-pos' ); ?></li>
					<li><strong><?php esc_html_e( 'Disponible', 'multisede-pos' ); ?></strong>: <?php esc_html_e( 'stock menos reservado. Es lo que realmente se puede vender hoy, y es lo que el POS deja cobrar.', 'multisede-pos' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'Si el POS te dice que no hay stock de algo que ves en la repisa, casi siempre es porque está reservado para un pedido web.', 'multisede-pos' ); ?></p>
			</div>

			<?php if ( current_user_can( 'msp_gestionar_caja' ) ) : ?>
				<div class="msp-card">
					<h2><?php esc_html_e( '1. Abrir la caja (al empezar el turno)', 'multisede-pos' ); ?></h2>
					<ol>
						<li><?php esc_html_e( 'Cuenta el efectivo con el que arrancas el día.', 'multisede-pos' ); ?></li>
						<li><?php
							printf(
								/* translators: %s: enlace al menú Caja. */
								esc_html__( 'Entra en %s, elige tu sede y escribe ese monto en "Abrir caja".', 'multisede-pos' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=' . MSP_Caja::PAGE ) ) . '"><strong>' . esc_html__( 'Caja', 'multisede-pos' ) . '</strong></a>'
							);
						?></li>
					</ol>
					<p><?php esc_html_e( 'A partir de ahí, todo el efectivo que entre y salga en tu turno queda registrado. Sin caja abierta el POS igual vende, pero el efectivo de esas ventas no se registra en ninguna caja y el arqueo del día no cuadrará.', 'multisede-pos' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( current_user_can( 'msp_usar_pos' ) ) : ?>
				<div class="msp-card">
					<h2><?php esc_html_e( '2. Vender en mostrador (POS)', 'multisede-pos' ); ?></h2>
					<ol>
						<li><?php
							printf(
								/* translators: %s: enlace al menú POS. */
								esc_html__( 'Entra en %s y confirma arriba que la sede es la tuya.', 'multisede-pos' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=' . MSP_POS::PAGE ) ) . '"><strong>' . esc_html__( 'POS', 'multisede-pos' ) . '</strong></a>'
							);
						?></li>
						<li><?php esc_html_e( 'Busca el producto por nombre o SKU y haz clic para añadirlo al ticket. Si el producto tiene tallas, colores o similares, aparecerá una línea por cada variante: elige la correcta.', 'multisede-pos' ); ?></li>
						<li><?php esc_html_e( 'Ajusta las cantidades en el ticket. Los productos sin disponible aparecen en gris y no se pueden añadir.', 'multisede-pos' ); ?></li>
						<li><?php esc_html_e( 'Elige el método de pago. Si es efectivo, escribe cuánto te dio el cliente y el sistema te calcula el vuelto.', 'multisede-pos' ); ?></li>
						<li><?php esc_html_e( 'Pulsa "Cobrar".', 'multisede-pos' ); ?></li>
					</ol>
					<p><?php esc_html_e( 'Al cobrar pasan tres cosas: se crea el pedido en WooCommerce como completado, el stock baja en tu sede y, si fue en efectivo, el monto entra a tu caja.', 'multisede-pos' ); ?></p>
					<p><strong><?php esc_html_e( '¿Te equivocaste?', 'multisede-pos' ); ?></strong> <?php esc_html_e( 'No se corrige desde el POS: hay que anular el pedido. Busca el pedido en WooCommerce y ponlo en "Cancelado" o "Reembolsado". El stock vuelve a la sede y, si fue en efectivo, se descuenta de la caja automáticamente.', 'multisede-pos' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="msp-card">
				<h2><?php esc_html_e( '3. Entregar un pedido web (recojo en tienda)', 'multisede-pos' ); ?></h2>
				<p><?php esc_html_e( 'El cliente compra en la web eligiendo tu tienda y ya pagó. Sus unidades quedan reservadas en tu sede: siguen contando como stock, pero nadie más puede comprarlas.', 'multisede-pos' ); ?></p>
				<ol>
					<li><?php
						printf(
							/* translators: %s: enlace a los pedidos de WooCommerce. */
							esc_html__( 'Cuando el cliente venga, abre su pedido en %s.', 'multisede-pos' ),
							'<a href="' . esc_url( self::url_pedidos() ) . '"><strong>' . esc_html__( 'WooCommerce → Pedidos', 'multisede-pos' ) . '</strong></a>'
						);
					?></li>
					<li><?php esc_html_e( 'Comprueba arriba a la derecha que la sede de recojo es la tuya y que dice "pendiente de recojo".', 'multisede-pos' ); ?></li>
					<li><?php esc_html_e( 'Entrégale la mercadería y elige la acción "Marcar como recogido (Multisede)".', 'multisede-pos' ); ?></li>
				</ol>
				<p><?php esc_html_e( 'Ahí el stock baja de verdad y la reserva se cierra. Mientras no lo marques, el sistema sigue creyendo que la mercadería está en la tienda.', 'multisede-pos' ); ?></p>
				<p><?php esc_html_e( 'Si el cliente nunca viene y se cancela o reembolsa el pedido, la reserva se libera sola y las unidades vuelven a estar a la venta.', 'multisede-pos' ); ?></p>
				<p><em><?php esc_html_e( 'El pedido web ya viene pagado por la web: no se cobra en la caja de la tienda, así que no toques la caja al entregarlo.', 'multisede-pos' ); ?></em></p>
			</div>

			<?php if ( current_user_can( 'msp_ver_stock' ) ) : ?>
				<div class="msp-card">
					<h2><?php esc_html_e( '4. Revisar y ajustar el inventario', 'multisede-pos' ); ?></h2>
					<p><?php
						printf(
							/* translators: %s: enlace al menú Inventario. */
							esc_html__( 'En %s ves, para la sede que elijas, el stock, lo reservado y lo disponible de cada producto.', 'multisede-pos' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=' . MSP_Inventario::PAGE ) ) . '"><strong>' . esc_html__( 'Inventario', 'multisede-pos' ) . '</strong></a>'
						);
					?></p>
					<?php if ( $es_gerente ) : ?>
						<p><?php esc_html_e( 'Para corregir el inventario, escribe el número real de unidades en la columna "Stock en la sede" y guarda. Ojo: se escribe el total que hay, no lo que entró. Si tenías 4 y llegan 6, se escribe 10.', 'multisede-pos' ); ?></p>
						<p><?php esc_html_e( 'Hazlo también cuando encuentres una diferencia al contar la repisa: el sistema no adivina las mermas ni los productos rotos.', 'multisede-pos' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Puedes consultarlo, pero no ajustarlo: los cambios de inventario los hace el gerente de la sede.', 'multisede-pos' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( current_user_can( 'msp_gestionar_caja' ) ) : ?>
				<div class="msp-card">
					<h2><?php esc_html_e( '5. Cerrar la caja (al terminar el turno)', 'multisede-pos' ); ?></h2>
					<ol>
						<li><?php esc_html_e( 'Registra antes cualquier movimiento suelto: un gasto pagado del cajón es un "egreso", plata que metiste al cajón es un "ingreso".', 'multisede-pos' ); ?></li>
						<li><?php esc_html_e( 'Mira el "Efectivo esperado": es lo que el sistema calcula que debería haber (apertura + ventas en efectivo + ingresos − egresos).', 'multisede-pos' ); ?></li>
						<li><?php esc_html_e( 'Cuenta el cajón de verdad y escribe ese monto en "Efectivo contado".', 'multisede-pos' ); ?></li>
						<li><?php esc_html_e( 'Pulsa "Cerrar caja".', 'multisede-pos' ); ?></li>
					</ol>
					<p><?php esc_html_e( 'La diferencia entre lo contado y lo esperado queda guardada en el arqueo. No pasa nada por que exista: lo importante es que quede registrada, no que dé cero.', 'multisede-pos' ); ?></p>
					<p><?php esc_html_e( 'Las ventas con tarjeta o Yape/Plin no entran en el efectivo esperado, porque ese dinero no está en el cajón.', 'multisede-pos' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $es_admin ) : ?>
				<div class="msp-card msp-nota">
					<h2><?php esc_html_e( 'Solo para el administrador: dejarlo listo', 'multisede-pos' ); ?></h2>
					<ol>
						<li><?php
							printf(
								/* translators: %s: enlace al listado de sedes. */
								esc_html__( 'Crea las tiendas en %s. Marca en cada una si vende en mostrador (tendrá POS), si surte pedidos web con recojo, o ambas.', 'multisede-pos' ),
								'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . MSP_Sedes::CPT ) ) . '"><strong>' . esc_html__( 'Sedes', 'multisede-pos' ) . '</strong></a>'
							);
						?></li>
						<li><?php
							printf(
								/* translators: %s: enlace al listado de usuarios. */
								esc_html__( 'Crea a cada persona su usuario en %s con el rol "Cajero" o "Gerente de sede", y en su perfil marca las sedes donde trabaja. Sin sedes marcadas no verá ni el POS ni la Caja.', 'multisede-pos' ),
								'<a href="' . esc_url( admin_url( 'users.php' ) ) . '"><strong>' . esc_html__( 'Usuarios', 'multisede-pos' ) . '</strong></a>'
							);
						?></li>
						<li><?php esc_html_e( 'Carga el stock inicial de cada sede desde Inventario.', 'multisede-pos' ); ?></li>
						<li><?php esc_html_e( 'Activa "Recogida local" en WooCommerce → Ajustes → Envío y desactiva los demás métodos si solo hay recojo.', 'multisede-pos' ); ?></li>
						<li><?php
							printf(
								/* translators: %s: shortcode. */
								esc_html__( 'Coloca el selector de tienda en la web con el shortcode %s (el cliente debe elegir tienda antes de comprar).', 'multisede-pos' ),
								'<code>[msp_selector_sede]</code>'
							);
						?></li>
					</ol>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MSP_Wizard::PAGE . '&step=1' ) ); ?>" class="button">
							<?php esc_html_e( 'Volver a abrir el asistente de configuración', 'multisede-pos' ); ?>
						</a>
					</p>
				</div>

				<div class="msp-card msp-nota">
					<h2><?php esc_html_e( 'Diferencias que suelen confundir', 'multisede-pos' ); ?></h2>
					<ul>
						<li><?php esc_html_e( 'El stock de WooCommerce que ves en la ficha del producto es solo un espejo: la suma de lo disponible en todas las sedes. El número que manda es el de cada sede.', 'multisede-pos' ); ?></li>
						<li><?php esc_html_e( 'En un producto con variantes (tallas, colores) el stock se carga dentro de cada variación, no en el producto padre.', 'multisede-pos' ); ?></li>
						<li><?php esc_html_e( 'Una venta del POS baja el stock en el acto. Una venta web solo lo reserva, y baja cuando se marca como recogida.', 'multisede-pos' ); ?></li>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Avisa a quien no tiene sedes asignadas (no podrá hacer nada).
	 *
	 * @param bool  $es_admin  Si es administrador.
	 * @param int[] $mis_sedes Sedes del usuario.
	 */
	private function aviso_sin_sede( $es_admin, $mis_sedes ) {
		if ( $es_admin || ! empty( $mis_sedes ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'Todavía no tienes ninguna sede asignada, así que el POS y la Caja te aparecerán vacíos. Pide a un administrador que marque tu tienda en tu perfil de usuario.', 'multisede-pos' );
		echo '</p></div>';
	}
}
