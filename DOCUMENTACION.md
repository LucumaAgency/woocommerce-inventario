# Documentación — Multisede POS

Plugin de WordPress que extiende **WooCommerce** para operar varias tiendas físicas + la tienda virtual: inventario por sede, recojo en tienda, punto de venta de mostrador y caja chica.

- **Repositorio:** `LucumaAgency/woocommerce-inventario`
- **Versión actual:** 1.5.0
- **Despliegue:** GitHub → WordPress vía Git Updater
- **Requisitos:** WordPress 6.0+, PHP 7.4+, WooCommerce 7.0+

---

## 1. Índice

1. Visión general
2. Conceptos clave (modelo de stock)
3. Arquitectura y archivos
4. Modelo de datos (tablas y metadatos)
5. Roles y permisos
6. Los módulos en detalle
7. Flujos de operación (día a día)
8. Instalación y configuración
9. Shortcodes y puntos de extensión (hooks)
10. Historial de versiones
11. Limitaciones conocidas y mejoras futuras

---

## 2. Visión general

WooCommerce de fábrica maneja **una sola bodega**: un producto tiene un número de stock y punto. Este plugin añade una capa de **multi-sede**:

- Cada **tienda** (sede) tiene su **propio stock** por producto.
- La tienda online opera **por sede**: el cliente elige una tienda y solo ve y compra el stock de esa sede (recojo en tienda, sin delivery).
- Un **POS de mostrador** permite vender presencialmente en cada sede generando pedidos de WooCommerce.
- La **caja chica** controla el efectivo por sede y turno, con arqueo al cierre.

Lo que se **reutiliza** de WooCommerce: catálogo, pedidos, clientes, pagos y el método de envío "Recogida local". Lo que **aporta** el plugin: sedes, stock por sede, compra por tienda, POS y caja.

---

## 3. Conceptos clave — el modelo de stock

Tres niveles de stock que conviene distinguir:

| Concepto | Significado |
|---|---|
| **Stock físico** | Unidades reales en la repisa de una sede. |
| **Reservado** | Unidades de un pedido web pagado pero aún no recogido. Siguen físicamente en la tienda pero ya no se pueden vender a otro. |
| **Disponible** | `stock físico − reservado`. Es lo que se ofrece para vender. |

Regla central:

> **Lo que ve el cliente en la web = disponible de la sede que eligió** (no la suma de todas).

Movimiento del stock según el canal:

```
VENTA EN MOSTRADOR (POS):
  cobrar  →  el stock físico baja en el acto  →  pedido "completado"
                                              →  el efectivo entra a la caja

VENTA WEB CON RECOJO:
  pagar           →  se RESERVA en la sede (el físico no baja todavía)
  marcar recogido →  el stock físico baja      →  reserva cerrada
  (cancelación)   →  la reserva se libera, nada se pierde
```

---

## 4. Arquitectura y archivos

```
multisede-pos/
├── multisede-pos.php              # Archivo principal: cabeceras, constantes, carga de clases, arranque
├── uninstall.php                  # Borra tablas, roles y metadatos al desinstalar
├── readme.txt                     # Readme estándar de WordPress (changelog)
├── README.md                      # Resumen para GitHub
├── DOCUMENTACION.md               # Este documento
├── includes/
│   ├── class-msp-plugin.php       # Bootstrap: instancia y arranca cada módulo
│   ├── class-msp-activator.php    # Crea tablas (dbDelta) y roles al activar
│   ├── class-msp-deactivator.php  # Limpieza al desactivar (flush rewrite)
│   ├── class-msp-roles.php        # Roles y capacidades; relación usuario↔sede
│   ├── class-msp-sedes.php        # CPT "sede" + metabox + columnas
│   ├── class-msp-stock.php        # Inventario por sede (tabla wp_msp_stock) + sync Woo
│   ├── class-msp-recojo.php       # Sede de recojo en checkout + reserva de stock
│   ├── class-msp-frontend.php     # Compra por tienda: stock visible y validación por sede
│   ├── class-msp-pos.php          # Punto de venta de mostrador (AJAX)
│   ├── class-msp-caja.php         # Caja chica: sesiones, movimientos, arqueo
│   ├── class-msp-inventario.php   # Pantalla de stock por sede (ajuste sin tocar el catálogo)
│   ├── class-msp-wizard.php       # Asistente de configuración al activar
│   └── class-msp-ayuda.php        # Manual de uso dentro del panel (por rol)
├── admin/
│   ├── css/pos.css                # Estilos del POS
│   └── js/pos.js                  # Lógica del POS (búsqueda, ticket, cobro)
└── languages/                     # Traducciones (textdomain: multisede-pos)
```

Prefijo de código: `msp_` / `MSP_`. Textdomain: `multisede-pos`.

---

## 5. Modelo de datos

### Tablas propias (creadas con dbDelta al activar)

**`wp_msp_stock`** — stock por producto y sede:

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | BIGINT | PK |
| `producto_id` | BIGINT | ID de producto/variación de Woo |
| `sede_id` | BIGINT | ID del CPT sede |
| `stock` | INT | Existencias físicas en la sede |
| `stock_reservado` | INT | Unidades reservadas (pedidos web pendientes de recojo) |
| `updated_at` | DATETIME | Última actualización |
| | | UNIQUE (producto_id, sede_id) |

**`wp_msp_caja_sesiones`** — turnos de caja:

| Columna | Descripción |
|---|---|
| `id`, `sede_id`, `cajero_id` | Identificación del turno |
| `monto_apertura` | Efectivo inicial |
| `monto_cierre_esperado` | Calculado al cerrar |
| `monto_cierre_contado` | Lo que cuenta el cajero |
| `diferencia` | `contado − esperado` (arqueo) |
| `estado` | `abierta` / `cerrada` |
| `es_practica` | `1` si es la caja de práctica del asistente: se excluye de reportes y de las ventas del POS |
| `abierta_at`, `cerrada_at` | Marcas de tiempo |

**`wp_msp_caja_movimientos`** — entradas y salidas de efectivo:

| Columna | Descripción |
|---|---|
| `id`, `sesion_id` | Pertenencia a un turno |
| `tipo` | `ingreso` / `egreso` / `venta` |
| `concepto` | Descripción |
| `monto` | Importe (positivo) |
| `pedido_id` | Pedido asociado (en ventas POS) |
| `creado_at` | Fecha |

### Custom Post Type: `msp_sede`

Una entrada por tienda, con metadatos:

| Meta | Significado |
|---|---|
| `_msp_direccion`, `_msp_horario` | Datos de contacto |
| `_msp_vende_web` | Surte pedidos web con recojo |
| `_msp_vende_mostrador` | Tiene POS |
| `_msp_es_virtual` | Es la tienda virtual (no física) |
| `_msp_activa` | Sede activa |

### Metadatos en el pedido de WooCommerce

| Meta | Significado |
|---|---|
| `_msp_sede_id` | Sede que surte/cobra el pedido |
| `_msp_origen` | `web` (recojo) / `pos` (mostrador) |
| `_msp_recogido` | `0` / `1` |
| `_msp_reserva_estado` | `reservado` / `recogido` / `liberado` |
| `_msp_pos_metodo` | Método de pago en el POS |
| `_msp_cajero_id` | Cajero que registró la venta POS |
| `_msp_stock_aplicado` | `1` si el stock ya se descontó físicamente |

### Metadato de usuario

| Meta | Significado |
|---|---|
| `_msp_sedes` | Array de IDs de sede asignadas al usuario |

---

## 6. Roles y permisos

Roles creados por el plugin (además del Administrador, que recibe todo):

| Capacidad | Gerente de sede | Cajero |
|---|:--:|:--:|
| `msp_ver_stock` | ✅ | ✅ |
| `msp_gestionar_stock` | ✅ | ❌ |
| `msp_usar_pos` | ✅ | ✅ |
| `msp_gestionar_caja` | ✅ | ✅ |
| `msp_ver_reportes` | ✅ | ❌ |
| `msp_gestionar_sedes` | ❌ (solo admin) | ❌ |

Las capacidades no son decorativas: el CPT `msp_sede` exige `msp_gestionar_sedes` (por eso el gerente no ve el menú Sedes), la pantalla de Inventario exige `msp_ver_stock` para mirar y `msp_gestionar_stock` para ajustar, y el historial de arqueos de la Caja solo lo ve quien tenga `msp_ver_reportes`.

Como el gerente ajusta el stock desde la pantalla de **Inventario** del plugin, no necesita permisos sobre el catálogo de WooCommerce: no puede tocar precios ni publicar productos.

### Asignación usuario ↔ sede

Cada usuario se asocia a una o más sedes con la meta `_msp_sedes`, que se edita en **dos sitios**:

- **Usuarios → editar usuario** → sección "Multisede POS" → casillas de sedes (solo un administrador la ve).
- **Paso 3 del asistente**, que asigna a todo el personal de golpe.

El listado de Usuarios muestra una columna **Sedes**, que marca en rojo "Sin asignar" a quien todavía no tiene ninguna. El POS, la Caja y el Inventario solo muestran las sedes del usuario; el administrador ve todas.

> Sin sedes asignadas, un cajero entra al POS y no ve ninguna sede: no puede vender. Es el paso que más se olvida al dar de alta a alguien.

### Cambios de roles entre versiones

`register_activation_hook` no se dispara al **actualizar** el plugin (Git Updater), así que las capacidades no se refrescarían solas. Por eso `MSP_Roles::ROLES_VERSION` se compara contra la opción `msp_roles_version` en `init` (prioridad 1, solo en el admin y fuera de AJAX): si cambió, los roles se recrean. Va antes de que se registre el CPT y se construya el menú, porque si no, una capacidad nueva se evaluaría con los roles viejos y la pantalla desaparecería durante esa primera carga.

**Al tocar las capacidades de un rol hay que subir esa constante**, o los usuarios existentes se quedarán con las capacidades viejas.

Lo mismo vale para el **esquema de la base de datos**: `MSP_Activator::migrar_db()` compara `MSP_Activator::DB_VERSION` contra la opción `msp_db_version` y vuelve a pasar `dbDelta` si cambió. **Al añadir o cambiar una columna hay que subir `DB_VERSION`**, o la columna nueva no llegará a las instalaciones ya existentes (solo a las que se activen desde cero).

---

## 7. Los módulos en detalle

### MSP_Sedes
Registra el CPT `msp_sede`, el metabox con los datos de la tienda y las columnas del listado (dirección, canales, estado). Helpers: `obtener_sedes_activas()`, `obtener_sedes_recojo()` (activas + venta web).

### MSP_Stock
Corazón del inventario. API estática principal:
- `get(producto, sede)` / `set(producto, sede, stock)` — leer/fijar stock.
- `ajustar(producto, sede, delta)` — sumar/restar de forma atómica.
- `descontar_si_hay(producto, sede, cantidad)` — descuenta solo si hay disponible; devuelve `false` si no. Condición y descuento van en la misma sentencia SQL, así que evita la sobreventa entre cajeros simultáneos.
- `reservar` / `liberar_reserva` / `confirmar_reserva` — gestión de reservas.
- `disponible_sede(producto, sede)` — físico − reservado.
- `disponible_producto(WC_Product, sede)` — igual, pero en un producto variable suma el disponible de sus variaciones.
- `total` / `total_reservado` — sumas globales.
- `sincronizar_woo(producto)` — fija el stock global de Woo = `Σ físico − Σ reservado`.

Añade los campos de stock por sede en la pestaña **Inventario** del producto (en los variables, dentro de **cada variación**, que es donde vive su stock) y una columna "Stock por sede" en el listado, que en los variables muestra la suma de sus variaciones. En pedidos con sede **desactiva** la reducción automática de Woo (filtro `woocommerce_can_reduce_order_stock`) para gestionarla el plugin.

### MSP_Recojo
- Inyecta el campo **sede de recojo** en el checkout clásico (si hay tienda elegida, queda fija).
- Guarda la sede en el pedido y **reserva** el stock al procesarse.
- Acción de pedido **"Marcar como recogido"** → descuenta físico y cierra la reserva.
- **Libera** la reserva si el pedido se cancela/reembolsa.
- Muestra la sede en el pedido (admin y cliente).

### MSP_Frontend (compra por tienda)
- Guarda la **sede activa** en la sesión de WooCommerce.
- Selector de tienda: shortcode `[msp_selector_sede]` + banner automático en tienda/producto/carrito/checkout.
- Filtra el stock visible, el estado en/sin stock y el texto de disponibilidad para reflejar **solo la sede elegida**.
- **Valida** al agregar al carrito y revalida el carrito antes del checkout.

### MSP_POS
- Página **POS** (capacidad `msp_usar_pos`).
- Búsqueda de productos por AJAX (nombre o SKU) con el **disponible** de la sede. Los productos variables se despliegan en sus variaciones.
- Ticket con cantidades, métodos de pago (efectivo, tarjeta, Yape/Plin, otro) y cálculo de vuelto.
- Al cobrar descuenta el stock de forma **atómica y condicional** (`descontar_si_hay`), crea un pedido de WooCommerce **completado** y dispara `msp_pos_venta_creada`. Si el stock se agotó entre la búsqueda y el cobro, el cobro falla y se devuelve lo ya descontado.
- Repone stock y dispara `msp_pos_venta_anulada` si la venta se cancela/reembolsa.

### MSP_Caja
- Página **Caja** (capacidad `msp_gestionar_caja`).
- Apertura por sede y cajero, registro de ingresos/egresos.
- Registra automáticamente las ventas POS en efectivo (vía `msp_pos_venta_creada`).
- Cierre con **arqueo**: esperado (`apertura + ingresos + ventas − egresos`) vs contado → diferencia.
- Reporte de cierres recientes por sede.

### MSP_Inventario
Pantalla **Inventario** (capacidad `msp_ver_stock`; ajustar requiere `msp_gestionar_stock`). Muestra, para la sede elegida, el stock, lo reservado y lo disponible de cada producto, con buscador por nombre o SKU y paginación. Los productos variables se listan con una fila por variación. El ajuste es **absoluto** (se escribe el total que hay, no lo que entró) y sincroniza el espejo de Woo. Existe para que el gerente gestione inventario sin permisos sobre el catálogo.

### MSP_Wizard
Asistente que aparece al activar, en cinco pasos: bienvenida (chequea WooCommerce), alta de sedes, **asignación del personal a sus sedes**, recojo en tienda y **práctica de un turno de caja**. Al finalizar lleva a la página de Ayuda, desde donde se puede volver a abrir.

El paso 5 guía un turno **real** (abrir caja → registrar un movimiento → cerrar con arqueo) usando las mismas funciones de `MSP_Caja` que la caja de verdad, pero sobre una sesión marcada con `es_practica = 1`. Esa marca la aísla por completo:

- **No aparece** en el historial de arqueos (`tabla_reportes` filtra `es_practica = 0`).
- **No recibe** el efectivo de las ventas del POS: `sesion_abierta()` — que es la que consulta el POS al registrar una venta en efectivo — solo devuelve sesiones reales. Sin esto, una caja de práctica olvidada abierta se habría tragado la recaudación del turno.
- **No bloquea** la apertura de la caja real: el chequeo de "ya hay una caja abierta" compara contra el mismo tipo de sesión.
- Se puede **borrar** con `descartar_practica()`, que solo toca sesiones con `es_practica = 1` del propio usuario, así que nunca puede destruir un arqueo real.

### MSP_Ayuda
Página **Ayuda** (capacidad `msp_ver_stock`), el manual de uso dentro del propio panel. Explica el modelo de stock (físico / reservado / disponible) y los flujos del día a día: abrir caja, vender en el POS, entregar un pedido web, ajustar inventario y cerrar caja con arqueo. El contenido se filtra por capacidades, así que cada rol solo ve lo que le toca, y el administrador ve además la checklist de puesta en marcha. Avisa a quien no tenga sedes asignadas.

---

## 8. Flujos de operación (día a día)

### A. Abrir la tienda (Cajero)
Menú **Caja** → cuenta el efectivo inicial → **Abrir caja** con el monto de apertura.

### B. Venta en mostrador (Cajero)
Menú **POS** → confirma sede → busca productos → arma el ticket → elige método de pago (si efectivo, ve el vuelto) → **Cobrar**. Se crea el pedido, baja el stock de la sede y el efectivo entra a la caja.

### C. Venta web con recojo (Cliente + Tienda)
El cliente elige su **tienda**, ve solo ese stock, compra y paga → se **reserva** en la sede. Cuando recoge, alguien abre el pedido y pulsa **"Marcar como recogido"** → baja el stock físico.

### D. Reponer/ajustar inventario (Gerente/Admin)
Menú **Inventario** → elige la sede → busca el producto → escribe el total real de unidades → **Guardar cambios**. El ajuste es absoluto: si había 4 y llegan 6, se escribe 10.

El administrador también puede hacerlo desde la ficha del producto (pestaña **Inventario** de Woo, y dentro de cada variación en los productos variables).

### E. Cierre de caja (Cajero)
Menú **Caja** → ve el efectivo esperado → cuenta el cajón → escribe el **contado** → **Cerrar caja**. Queda el arqueo con la diferencia y se guarda en el reporte.

---

## 9. Instalación y configuración

### Instalar vía Git Updater
1. Instala el plugin **Git Updater** en el WordPress.
2. Conecta el repositorio `LucumaAgency/woocommerce-inventario` (rama `main`). Si el repo es privado, Git Updater pedirá un token de GitHub con lectura.
3. Instala y activa **Multisede POS** (requiere WooCommerce activo).
4. Las nuevas versiones aparecen como actualización cuando se publica un tag/release.

### Configuración inicial
El **asistente** (aparece al activar, y se puede reabrir desde la página de Ayuda) guía los cuatro primeros pasos:

1. Crea tus **sedes**.
2. Da de alta a cada persona con el rol **Gerente de sede** o **Cajero** y **asígnale sus sedes** (paso 3 del asistente, o el perfil del usuario). Sin sedes asignadas no verá el POS ni la Caja.
3. Carga el **stock por sede** en el menú **Inventario**.
4. Activa **"Recogida local"** en WooCommerce → Ajustes → Envío.
5. Coloca el selector de tienda con `[msp_selector_sede]` donde quieras (header, menú, página de tienda).

La página **Ayuda** queda siempre disponible en el panel con los flujos del día a día explicados para cada rol.

---

## 10. Shortcodes y puntos de extensión

### Shortcode
- `[msp_selector_sede]` — selector de tienda para el frontend.

### Hooks (actions)
- `msp_pos_venta_creada( $order, $metodo, $sede_id )` — se dispara al crear una venta en el POS. Lo usa la caja chica para registrar el efectivo; se puede usar para integraciones (facturación, etc.).
- `msp_pos_venta_anulada( $order, $sede_id )` — se dispara al cancelar o reembolsar una venta del POS, después de devolver el stock a la sede. Lo usa la caja chica para revertir el efectivo.

### Filtros de WooCommerce intervenidos
- `woocommerce_can_reduce_order_stock` — desactiva la reducción automática en pedidos con sede.
- `woocommerce_product_get_stock_quantity` / `_variation_get_stock_quantity` — stock visible = disponible de la sede.
- `woocommerce_product_is_in_stock`, `woocommerce_get_availability_text` — estado/texto por sede.
- `woocommerce_add_to_cart_validation`, `woocommerce_check_cart_items` — validación por sede.

---

## 11. Historial de versiones

| Versión | Contenido |
|---|---|
| **0.1.0** | Fase 1 — Esqueleto, CPT Sedes, roles, tablas, wizard |
| **0.2.0** | Fase 2 — Inventario multi-sede (stock por sede + sync Woo) |
| **0.3.0** | Fase 3 — Recojo en tienda (sede en checkout + reserva) |
| **0.4.0** | Fase 4 — POS de mostrador |
| **1.0.0** | Fase 5 — Caja chica (plugin funcionalmente completo) |
| **1.1.0** | Compra por tienda (la web opera por sede) |
| **1.2.0** | Stock disponible y descuento atómico en el POS, reversa de caja al anular una venta, soporte de productos variables |
| **1.3.0** | Asignación usuario↔sede, roles funcionales, pantalla de Inventario, asistente ampliado y página de Ayuda |
| **1.4.0** | Paso de práctica de caja en el asistente (turno real, aislado y borrable) y migración automática del esquema al actualizar |
| **1.5.0** | Lenguaje de tienda: "arqueo" → "cuadre", y el resultado del cierre se dice en claro (cuadró / faltaron / sobraron) |

---

## Nota de vocabulario

En el **código** y en esta documentación se usa el término contable: la columna
de la base de datos se llama `diferencia` y los comentarios hablan de arqueo.

En la **interfaz** no. Ahí se habla como en la tienda: "arqueo" es **cuadre**, y
la diferencia no se muestra como un número con signo, sino traducida por
`MSP_Caja::resultado_cuadre( $diferencia )`, que devuelve **"Cuadró"**,
**"Faltaron X"** o **"Sobraron X"** con su color. Si añades una pantalla que
muestre el cierre de una caja, usa ese helper en vez de imprimir la diferencia a
pelo, para no volver a meter jerga en la interfaz.

---

## 12. Limitaciones conocidas y mejoras futuras

- **Checkout de bloques:** la integración actual usa el **checkout/tienda clásicos** (incluye constructores como Bricks, que usan el checkout clásico). El checkout de **bloques** (React/Store API) requiere integración adicional pendiente.
- **Anulación sin caja abierta:** si se anula una venta POS en efectivo cuyo turno ya se cerró y el cajero no tiene otra caja abierta, el egreso no se registra (no se toca un arqueo cerrado): queda una nota en el pedido para ajustarlo a mano.
- **Banner automático en temas con layout propio:** el banner se engancha a `woocommerce_before_main_content`; en temas/constructores que no lo disparen, colocar el selector con el shortcode manualmente.
- **Otras ideas:** reportes consolidados entre sedes, impresión de ticket, lector de código de barras por hardware, traslados de stock entre sedes.
