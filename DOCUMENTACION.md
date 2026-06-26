# Documentación — Multisede POS

Plugin de WordPress que extiende **WooCommerce** para operar varias tiendas físicas + la tienda virtual: inventario por sede, recojo en tienda, punto de venta de mostrador y caja chica.

- **Repositorio:** `LucumaAgency/woocommerce-inventario`
- **Versión actual:** 1.1.0
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
│   └── class-msp-wizard.php       # Asistente de configuración al activar
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

Cada usuario se asocia a una o más sedes con la meta `_msp_sedes`. El POS y la Caja solo muestran las sedes del usuario (el administrador ve todas).

---

## 7. Los módulos en detalle

### MSP_Sedes
Registra el CPT `msp_sede`, el metabox con los datos de la tienda y las columnas del listado (dirección, canales, estado). Helpers: `obtener_sedes_activas()`, `obtener_sedes_recojo()` (activas + venta web).

### MSP_Stock
Corazón del inventario. API estática principal:
- `get(producto, sede)` / `set(producto, sede, stock)` — leer/fijar stock.
- `ajustar(producto, sede, delta)` — sumar/restar de forma atómica.
- `reservar` / `liberar_reserva` / `confirmar_reserva` — gestión de reservas.
- `disponible_sede(producto, sede)` — físico − reservado.
- `total` / `total_reservado` — sumas globales.
- `sincronizar_woo(producto)` — fija el stock global de Woo = `Σ físico − Σ reservado`.

Añade los campos de stock por sede en la pestaña **Inventario** del producto y una columna "Stock por sede" en el listado. En pedidos con sede **desactiva** la reducción automática de Woo (filtro `woocommerce_can_reduce_order_stock`) para gestionarla el plugin.

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
- Búsqueda de productos por AJAX (nombre o SKU) con stock de la sede.
- Ticket con cantidades, métodos de pago (efectivo, tarjeta, Yape/Plin, otro) y cálculo de vuelto.
- Al cobrar crea un pedido de WooCommerce **completado**, descuenta stock de la sede y dispara el hook `msp_pos_venta_creada`.
- Repone stock si la venta se cancela/reembolsa.

### MSP_Caja
- Página **Caja** (capacidad `msp_gestionar_caja`).
- Apertura por sede y cajero, registro de ingresos/egresos.
- Registra automáticamente las ventas POS en efectivo (vía `msp_pos_venta_creada`).
- Cierre con **arqueo**: esperado (`apertura + ingresos + ventas − egresos`) vs contado → diferencia.
- Reporte de cierres recientes por sede.

### MSP_Wizard
Asistente que aparece al activar: bienvenida (chequea WooCommerce), alta de sedes y guía de recojo. Marca la configuración como completada.

---

## 8. Flujos de operación (día a día)

### A. Abrir la tienda (Cajero)
Menú **Caja** → cuenta el efectivo inicial → **Abrir caja** con el monto de apertura.

### B. Venta en mostrador (Cajero)
Menú **POS** → confirma sede → busca productos → arma el ticket → elige método de pago (si efectivo, ve el vuelto) → **Cobrar**. Se crea el pedido, baja el stock de la sede y el efectivo entra a la caja.

### C. Venta web con recojo (Cliente + Tienda)
El cliente elige su **tienda**, ve solo ese stock, compra y paga → se **reserva** en la sede. Cuando recoge, alguien abre el pedido y pulsa **"Marcar como recogido"** → baja el stock físico.

### D. Reponer/ajustar inventario (Gerente/Admin)
Producto → pestaña **Inventario** → cambia el número de la sede → guardar. La columna "Stock por sede" da la vista general.

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
1. Corre el **wizard** (aparece al activar) y crea tus sedes.
2. Asigna a cada usuario su rol (Gerente/Cajero) y sus sedes (`_msp_sedes`).
3. Carga el **stock por sede** en cada producto (pestaña Inventario).
4. Activa **"Recogida local"** en WooCommerce → Ajustes → Envío.
5. Coloca el selector de tienda con `[msp_selector_sede]` donde quieras (header, menú, página de tienda).

---

## 10. Shortcodes y puntos de extensión

### Shortcode
- `[msp_selector_sede]` — selector de tienda para el frontend.

### Hooks (actions)
- `msp_pos_venta_creada( $order, $metodo, $sede_id )` — se dispara al crear una venta en el POS. Lo usa la caja chica para registrar el efectivo; se puede usar para integraciones (facturación, etc.).

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

---

## 12. Limitaciones conocidas y mejoras futuras

- **Checkout de bloques:** la integración actual usa el **checkout/tienda clásicos** (incluye constructores como Bricks, que usan el checkout clásico). El checkout de **bloques** (React/Store API) requiere integración adicional pendiente.
- **Variaciones en el POS:** el POS maneja productos **simples**; las variaciones quedan para una iteración futura.
- **Banner automático en temas con layout propio:** el banner se engancha a `woocommerce_before_main_content`; en temas/constructores que no lo disparen, colocar el selector con el shortcode manualmente.
- **Otras ideas:** reportes consolidados entre sedes, impresión de ticket, lector de código de barras por hardware, traslados de stock entre sedes.
