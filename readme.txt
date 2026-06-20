=== Multisede POS ===
Contributors: lucumaagency
Tags: woocommerce, inventario, multi-almacen, pos, caja
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inventario por sede, recojo en tienda, POS de mostrador y caja chica para WooCommerce.

== Description ==

Multisede POS extiende WooCommerce para gestionar varias tiendas físicas y la tienda virtual:

* Stock independiente por sede.
* Recojo en tienda (sin delivery por web).
* Punto de venta de mostrador que genera pedidos WooCommerce.
* Caja chica con apertura, movimientos y arqueo por sede y turno.

Requiere WooCommerce activo.

== Installation ==

1. Sube la carpeta `multisede-pos` a `/wp-content/plugins/`.
2. Activa el plugin desde el menú "Plugins".
3. Configura tus sedes en el nuevo menú "Sedes".

== Changelog ==

= 0.4.0 =
* Fase 4: POS de mostrador. Pantalla de punto de venta con búsqueda de productos por AJAX, ticket, métodos de pago y cálculo de vuelto en efectivo. Genera un pedido de WooCommerce completado, descuenta stock de la sede del cajero y repone el stock si la venta se anula. Validación de stock y permisos por sede.

= 0.3.0 =
* Fase 3: recojo en tienda. Selección de sede de recojo en el checkout, reserva de stock al pagar, acción "Marcar como recogido" que descuenta el stock físico, liberación de reserva en cancelaciones y visualización de la sede en el pedido. El stock disponible de Woo pasa a ser stock físico menos reservado.

= 0.2.0 =
* Fase 2: inventario multi-sede. Stock por sede en la ficha de producto, sincronización del stock global de WooCommerce (suma de sedes), descuento/restitución por pedido y columna "Stock por sede" en el listado de productos.

= 0.1.0 =
* Fase 1: esqueleto del plugin, CPT Sedes, roles (Gerente de sede / Cajero) y creación de tablas.
