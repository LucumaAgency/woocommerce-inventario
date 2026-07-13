=== Multisede POS ===
Contributors: lucumaagency
Tags: woocommerce, inventario, multi-almacen, pos, caja
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.5.0
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

= 1.5.0 =
* Lenguaje de tienda en vez de jerga contable: donde decía "arqueo" ahora dice "cuadre". "Cerrar caja (cuadre)", "Cierres de caja", "la caja cuadró".
* El resultado del cierre se dice en claro. En vez de una "Diferencia: -S/ 2.00" que hay que interpretar, la tabla y el asistente dicen "Cuadró", "Faltaron S/ 2.00" o "Sobraron S/ 2.00".
* La ayuda indica dónde encontrar el historial: la tabla "Cierres de caja", al final de la pantalla de Caja (solo la ven gerentes y administradores).

= 1.4.0 =
* Nuevo paso 5 del asistente: "Practicar caja". Guía un turno completo de verdad (abrir la caja, registrar un movimiento y cerrarla con arqueo) explicando cada paso, para entender qué es el efectivo esperado y qué significa la diferencia.
* La caja de práctica está aislada: queda marcada como tal, no aparece en el historial de arqueos, no recibe el efectivo de las ventas del POS, no bloquea la apertura de la caja real del turno y se puede borrar de un clic.
* El esquema de la base de datos ahora se actualiza solo al actualizar el plugin (antes solo se aplicaba al activarlo, así que una columna nueva no llegaba a las instalaciones existentes).

= 1.3.0 =
* Asignación de sedes a los usuarios: casillas en el perfil de cada usuario, columna "Sedes" en el listado de usuarios y un paso nuevo en el asistente para asignar a todo el personal de golpe. Sin esto, el POS y la Caja solo funcionaban para administradores.
* Roles funcionales: el menú Sedes exige "msp_gestionar_sedes" (solo admin), el historial de arqueos exige "msp_ver_reportes" (el cajero ya no lo ve) y los roles se refrescan solos al actualizar el plugin.
* Nueva pantalla "Inventario": el gerente ve y ajusta el stock, lo reservado y lo disponible de su sede, con buscador y soporte de variaciones, sin necesidad de permisos sobre el catálogo de WooCommerce.
* Nueva página "Ayuda": manual de uso dentro del panel, filtrado por rol, con los flujos del día a día (abrir caja, vender en mostrador, entregar un pedido web, ajustar inventario, cerrar caja con arqueo).
* El asistente pasa a cuatro pasos, termina en la Ayuda y ya no muestra texto de fases pendientes que hace tiempo se construyeron.

= 1.2.0 =
* El POS vende contra el stock disponible (físico menos reservado): ya no puede vender unidades apartadas para un pedido web pendiente de recojo.
* El descuento de stock al cobrar es atómico: dos cajeros vendiendo la última unidad a la vez ya no pueden sobrevender.
* Anular (cancelar o reembolsar) una venta POS en efectivo devuelve el dinero a la caja como egreso, de modo que el arqueo ya no queda descuadrado.
* Productos variables: stock por sede en cada variación, venta de variaciones en el POS y disponibilidad correcta en la web.
* El buscador del POS solo consulta el stock de las sedes asignadas al usuario.

= 1.1.0 =
* Compra por tienda: el cliente elige una sede y la web muestra y respeta solo el stock de esa sede. Incluye selector de tienda (shortcode [msp_selector_sede] y banner en las páginas de tienda), disponibilidad y estado de stock por sede, validación al agregar al carrito y en el checkout, y sede de recojo fijada a la tienda elegida.

= 1.0.0 =
* Fase 5: caja chica. Apertura de caja por sede y cajero, registro de ingresos y egresos, registro automático de ventas POS en efectivo, cierre con arqueo (esperado vs contado) y reporte de cierres recientes. Con esta fase el plugin queda funcionalmente completo.

= 0.4.0 =
* Fase 4: POS de mostrador. Pantalla de punto de venta con búsqueda de productos por AJAX, ticket, métodos de pago y cálculo de vuelto en efectivo. Genera un pedido de WooCommerce completado, descuenta stock de la sede del cajero y repone el stock si la venta se anula. Validación de stock y permisos por sede.

= 0.3.0 =
* Fase 3: recojo en tienda. Selección de sede de recojo en el checkout, reserva de stock al pagar, acción "Marcar como recogido" que descuenta el stock físico, liberación de reserva en cancelaciones y visualización de la sede en el pedido. El stock disponible de Woo pasa a ser stock físico menos reservado.

= 0.2.0 =
* Fase 2: inventario multi-sede. Stock por sede en la ficha de producto, sincronización del stock global de WooCommerce (suma de sedes), descuento/restitución por pedido y columna "Stock por sede" en el listado de productos.

= 0.1.0 =
* Fase 1: esqueleto del plugin, CPT Sedes, roles (Gerente de sede / Cajero) y creación de tablas.
