# Documentación — Multisede POS

Plugin de WordPress que extiende **WooCommerce** para operar varias tiendas físicas + la tienda virtual: inventario por sede, recojo en tienda, punto de venta de mostrador y caja chica.

- **Repositorio:** `LucumaAgency/woocommerce-inventario`
- **Versión actual:** 1.19.0
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
│   ├── class-msp-comprobante.php  # Reserva de correlativos y acceso a la tabla
│   ├── class-msp-emisor.php       # Motor Greenter: XML, firma y envio a SUNAT
│   ├── class-msp-cola.php         # Cuando se emite: encolado, reintentos, alarma
│   ├── class-msp-comprobantes.php # Pantalla Comprobantes (gerencia)
│   ├── class-msp-facturacion.php  # Ajustes de facturacion y emision de prueba
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

**`wp_msp_comprobantes`** — comprobantes electrónicos SUNAT (boletas). Añadida en v1.7.0 (Fase 1 de facturación). El correlativo se reserva con un `INSERT` que el índice `UNIQUE (entorno, serie, correlativo)` rechaza si otro cajero se adelantó, y se reintenta con el siguiente: no puede repetir ni saltar números (mismo principio que `MSP_Stock::descontar_si_hay`). **Nunca se calcula con `SELECT MAX()+1` sin la red del índice único.**

| Columna | Descripción |
|---|---|
| `id` | PK |
| `pedido_id` | Pedido Woo asociado (POS o web) |
| `sede_id` | Sede emisora |
| `tipo` | `boleta` (reservado sitio para `factura`, `nota_credito`) |
| `entorno` | `beta` / `produccion`. Cada entorno numera por separado: las pruebas no gastan correlativos reales (v1.9.1) |
| `serie`, `correlativo` | Número del comprobante — **UNIQUE (entorno, serie, correlativo)** |
| `cliente_tipo_doc`, `cliente_num_doc`, `cliente_nombre` | DNI si el monto lo exige (> S/ 700) |
| `total`, `igv` | Importes declarados |
| `estado` | `pendiente` / `enviado` / `aceptado` / `rechazado` / `anulado` |
| `intentos`, `ultimo_error`, `proximo_intento`, `alertado_at` | Cola, reintentos y alarma (Fase 3) |
| `hash`, `xml_path`, `cdr_path`, `pdf_url` | Documentos a conservar por ley (Fase 2/5) |
| `emitido_at`, `enviado_at` | Fechas (el plazo de 7 días se cuenta desde `emitido_at`) |

### Custom Post Type: `msp_sede`

Una entrada por tienda, con metadatos:

| Meta | Significado |
|---|---|
| `_msp_direccion`, `_msp_horario` | Datos de contacto |
| `_msp_vende_web` | Surte pedidos web con recojo |
| `_msp_vende_mostrador` | Tiene POS |
| `_msp_es_virtual` | Es la tienda virtual (no física) |
| `_msp_activa` | Sede activa |
| `_msp_serie_boleta` | Serie de boleta electrónica de la sede (ej. `B001`). Única por sede. Añadida en v1.7.0 |

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
| `msp_anular_ventas` | Anular una venta del turno abierto. Suelta a propósito: se le puede quitar al Cajero y dejarla solo al Gerente, porque anular ventas en efectivo es la vía clásica de fuga de caja |
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

### MSP_Pruebas (v1.16.0)
`Caja → Pruebas`, **solo fuera de producción** y con `manage_options`. Existe porque probar el módulo a mano se iba en dos cosas ajenas al sistema: ir a *Acciones programadas* a empujar la cola, y montar la situación previa de cada caso.

- **Procesar todo ahora** — envía los comprobantes pendientes, agrupa las bajas marcadas y consulta los tickets de los resúmenes, en el acto. **También sirve en producción** cuando algo se atasca, sin entrar a la pantalla de acciones de WooCommerce.
- **Generadores de escenario** — venta con boleta aceptada, venta atascada (enciende el simulador solo durante el envío y lo devuelve a como estaba), venta fechada ayer (mueve `emitido_at`, que es lo que agrupa las bajas) y producto con 1 unidad.

En producción **ni se registra el menú**: un botón que fabrica ventas falsas no debe estar al alcance de nadie en la tienda real.

### MSP_Entregas (v1.13.0)
`Entregas`, menú propio, con `msp_usar_pos`. Lista los pedidos **web pendientes de recojo** de las sedes del usuario, con una sola acción: **Entregar**.

Existe porque marcar un pedido como recogido se hacía desde Pedidos de WooCommerce, que exige `edit_shop_orders`, y el rol Cajero no la tiene: **no podía entregar nada**, y la Ayuda le documentaba ese flujo paso a paso. Darle `edit_shop_orders` habría sido peor: vería los pedidos de todas las tiendas y podría editar precios y estados.

Comprueba la sede del pedido **también al ejecutar la acción**, no solo al listar: sin eso, cambiar el número en la URL entregaría el pedido de otra tienda.

### MSP_Cajas_Abiertas (v1.12.0)
`Caja → Cajas abiertas`, con `msp_ver_reportes`. Lista los turnos **abiertos ahora mismo** en todas las sedes, con cajero, hora de apertura, tiempo transcurrido, monto de apertura, ventas en efectivo, ingresos/egresos y el **efectivo esperado** en cada cajón, más el total en mostrador.

Existe porque la pantalla de Caja es **personal** (consulta la sesión del usuario que la mira, también si es admin) y el historial solo muestra turnos **ya cerrados**: no había forma de supervisar lo que está pasando ahora. Un descuadre se aclara mejor mientras la persona sigue en el mostrador que al día siguiente.

Reutiliza `MSP_Caja::totales()` y `MSP_Caja::esperado()` en vez de recalcular. Excluye las cajas de **práctica** del asistente: no son dinero real y mezclarlas haría dudar de la cifra.

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

### MSP_Comprobante (Fase 1 de boletas — v1.7.0)
Capa que emitirá los comprobantes electrónicos SUNAT. El POS y la web **no hablan con SUNAT**: hablan con esta clase. En la v1.7.0 solo está la **base de datos**: asigna serie + correlativo y guarda la fila como `pendiente`. La firma y el envío (driver **Greenter**, contra el sandbox de SUNAT) llegan en la Fase 2.

- `serie_de_sede( $sede_id )` / `serie_valida( $serie )` — la serie (ej. `B001`) vive en el meta `_msp_serie_boleta` de cada sede. Formato SUNAT: empieza con `B` y 4 caracteres.
- `serie_en_uso( $serie, $excluir_sede_id )` — impide que dos sedes compartan serie (se pisarían el correlativo). Se valida al guardar la sede; si choca, la serie no se guarda y aparece un aviso.
- `reservar( $datos )` — reserva atómica del siguiente correlativo y crea el comprobante. Devuelve la fila o un `WP_Error`.
- `obtener()`, `obtener_por_pedido()`, `numero()` — lectura y número legible (`B001-00000042`).

- `listar()`, `contar_por_estado()`, `pendientes_de_reintento()`, `atascados()` — consultas que alimentan la pantalla de Comprobantes y la cola (v1.9.0). Las dos últimas filtran por el **entorno activo**.
- `entorno_actual()` — `'beta'` o `'produccion'`. Desde la v1.9.1 el entorno forma parte de la clave única, así que **cada entorno lleva su propia numeración**: las pruebas ya no gastan correlativos de la serie real, y la primera boleta de producción sale como `-00000001` aunque antes se hayan hecho veinte pruebas.
- `LIMITE_DNI` (700) — importe a partir del cual SUNAT exige identificar al comprador, con **documento y nombre** (v1.9.3). Es constante y no ajuste: es la norma, no una preferencia de la tienda.

### MSP_Emisor (Fase 2 de boletas — v1.8.0)
Motor de emisión: arma el XML UBL con **Greenter**, lo firma con el certificado PEM y lo envía por SOAP al web service de SUNAT. Recibe un comprobante ya reservado y lo lleva hasta el CDR; **no decide cuándo emitir** (eso es la cola).

- `probar_credenciales()` — comprueba el usuario y la clave SOL **sin emitir**. Envía a propósito un archivo que no es un comprobante: SUNAT valida primero quién eres y después qué le mandas, así que una queja sobre el contenido significa que la autenticación pasó. Seguro incluso en producción. **En beta devuelve siempre «no concluyente»**: el sandbox acepta cualquier credencial, comprobado con usuario y clave inventados.
- `emitir( $comprobante_id )` — firma, envía, guarda XML y CDR, y deja el comprobante en `aceptado`, `rechazado` o `error`.
- `ajustes()` / `es_produccion()` — opción `msp_facturacion`: entorno, credenciales SOL, datos del emisor y el interruptor `emision_automatica`.
- `codigo_local( $sede_id )` — código de establecimiento del meta `_msp_codigo_anexo` de la sede, o **`0000`** (domicilio fiscal) si no tiene. saraih emite con `0000`: decidió no declarar sus tiendas como anexos.

### MSP_Cola (Fase 3 de boletas — v1.9.0)
Decide **cuándo** se emite. Regla del módulo: **la emisión nunca ocurre dentro del cobro**. El cajero cierra la venta contra la caja, no contra SUNAT; si el web service tarda o está caído, es problema de la cola.

- `al_vender_en_pos()` — engancha `msp_pos_venta_creada`: reserva el correlativo (local y rápido) y programa el envío.
- `encolar_pedido( $order, $sede_id )` — idempotente: si el pedido ya tiene comprobante no reserva otro. Un segundo comprobante por la misma venta sería un duplicado ante SUNAT.
- `procesar( $id )` — emite y decide: aceptado → fin; **rechazado → no se reintenta solo** (un rechazo es un dato mal puesto, reintentarlo da el mismo rechazo); error pasajero → reintento con espera creciente (2 min, 10 min, 30 min, 1 h, 3 h, 6 h, 12 h, 24 h).
- `barrido()` — cada hora: rescata lo que se quedó sin acción programada (red de seguridad si Action Scheduler pierde una acción) y dispara la alarma.
- `alarma()` — correo al administrador por los comprobantes sin aceptar tras **2 días**. Una sola vez por comprobante (`alertado_at`): un recordatorio diario acaba en spam.
- Usa **Action Scheduler** (viene con WooCommerce) y cae a WP-Cron si no está. La diferencia importa: WP-Cron depende de visitas, y una tienda cerrada de noche no las tiene.

### MSP_Comprobantes (pantalla, v1.9.0)
`Caja → Comprobantes`, con capacidad `msp_ver_reportes`. Listado con filtros por estado, tienda y número; reintento manual; descarga del XML firmado y del CDR (servidos desde PHP, con la ruta anclada a la carpeta del módulo). Sin esta pantalla la cola sería una caja negra.

### MSP_Baja y MSP_Resumen (Fase 4 de boletas — v1.10.0)
Una boleta no se borra: se comunica su **baja** listándola en un **resumen diario** con estado `3` (1 = informar, 2 = corregir, 3 = anular). Sin esto, anular una venta devuelve stock y efectivo pero **para SUNAT la boleta sigue siendo válida**, y saraih pagaría IGV de una venta que no ocurrió.

- `MSP_Baja::marcar( $order )` — engancha `msp_pos_venta_anulada` y la cancelación/reembolso de pedidos. Tres reglas: si la boleta **no llegó a ser aceptada** no hay baja que comunicar (SUNAT nunca supo de ella); no se marca dos veces; y si pasaron los **7 días** de plazo se marca `fuera_de_plazo`, porque entonces hace falta una nota de crédito.
- `agrupar_y_enviar()` — agrupa las bajas por **fecha de emisión** de los comprobantes, no por la fecha en que se anularon: el resumen informa de los comprobantes de un día concreto. Los comprobantes se enganchan al resumen *antes* del envío, para que un proceso muerto a mitad no los reparta en dos resúmenes.
- `enviar()` / `consultar()` — el ciclo asíncrono: `sendSummary` devuelve un **ticket** y el resultado se pregunta después con `getStatus`. El código `98` de SUNAT significa "en proceso" y se reintenta; el `0` acepta y el `99` rechaza (y un rechazo no se reintenta solo, igual que en las boletas).
- `MSP_Resumen` — tabla `wp_msp_resumenes`, correlativo por día (`RC-20260818-1`) con el mismo patrón de índice único que los correlativos de boleta, y `dias_de_plazo()`.

### MSP_Ticket (Fase 5 de boletas — v1.11.0)
Lo que SUNAT tiene es el XML; lo que el cliente se lleva es el papel. Se sirve por `admin-post.php` (acción `msp_ticket`, con nonce), y puede imprimirlo el **cajero**, que es quien lo entrega.

- `cadena_qr( $c )` — la cadena del QR, compuesta a mano campo por campo porque SUNAT no da helper: `RUC|03|serie|correlativo|IGV|total|fecha|tipoDocCliente|nroDocCliente|hash`. Un campo de más o de menos y el verificador de SUNAT no reconoce el comprobante.
- `qr_svg( $texto )` — QR en **SVG** con `chillerlan/php-qrcode`: no depende de GD ni Imagick (faltan en muchos hostings), imprime nítido a cualquier tamaño y viaja dentro del HTML.
- **Si el comprobante aún no tiene `hash`** (reservado pero no confirmado por SUNAT) el ticket **no pinta el QR** y lo dice: un QR sin el hash de la firma da un código que el verificador no reconoce, y eso es peor que no ponerlo — el cliente creería tener algo comprobable.
- Fuera de producción el ticket lleva impreso *«DOCUMENTO DE PRUEBA — SIN VALOR»*.
- **El PDF lo hace el navegador.** La página está maquetada a 80 mm con `@page size: 80mm auto`; el navegador la manda a la térmica o la guarda como PDF. Meter una librería de PDF —o el binario descontinuado de wkhtmltopdf— sería cargar megas y una dependencia frágil para lo que el navegador ya hace bien.

**Roadmap de facturación** (plan completo: `PLAN-BOLETAS.md` en la carpeta del cliente): F1 base ✓ · F2 Greenter + emisión ✓ · F3 cola/reintentos/pantalla Comprobantes ✓ · F4 anulaciones (resumen diario) ✓ · F5 ticket con QR ✓. **Módulo de facturación completo.**

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
- `msp_pos_venta_anulada( $order, $sede_id )` — se dispara al cancelar o reembolsar una venta del POS, después de devolver el stock a la sede. Lo usa la caja chica para revertir el efectivo. **Será el enganche de la Fase 4** (resumen diario de anulaciones a SUNAT).
- `msp_emitir_comprobante( $comprobante_id )` — acción de fondo que emite un comprobante (Action Scheduler, grupo `multisede-pos`).
- `msp_barrido_comprobantes()` — barrido horario: reintentos pendientes + alarma.

### Filtros propios
- `msp_recojo_hook_checkout` — hook donde se pinta el bloque de recojo + DNI en el checkout clásico. Por defecto `woocommerce_checkout_before_customer_details` (arriba del todo).
- `msp_alarma_destinatario` — correo al que va el aviso de comprobantes atascados (por defecto, el del administrador del sitio).

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
| **1.6.0** | Tabla "Ventas de este turno" en la pantalla de Caja |
| **1.7.0** | **Boletas Fase 1** — tabla `wp_msp_comprobantes`, reserva de correlativo a prueba de carreras, serie de boleta por sede (`_msp_serie_boleta`). Base de facturación electrónica; aún no emite |
| **1.7.1 – 1.7.5** | Correcciones del recorrido de verificación: acceso del personal de tienda al panel, stock que no llegaba a Woo, egresos mayores que el efectivo del cajón, recuperación de capacidades del admin y menú Sedes |
| **1.8.0** | **Boletas Fase 2** — motor de emisión con Greenter: XML UBL, firma, envío SOAP a SUNAT, CDR y conservación de archivos. Pantalla de Facturación con emisión de prueba |
| **1.9.7** | El reintento manual de un comprobante de otro entorno explica por qué no se envía, en vez de dejar la fila en «En cola» sin hacer nada |
| **1.9.6** | Botón **Probar credenciales SOL** sin emitir nada: se envía a propósito un archivo que no es un comprobante, así que SUNAT solo puede objetar el contenido. Distingue «no me identificas» de «tu archivo está mal» sin consumir numeración. En beta avisa de que el resultado no significa nada |
| **1.9.5** | Los fallos previos al envío (certificado ausente o ilegible, Greenter a medias) quedan registrados en el comprobante en vez de dejarlo «En cola» sin motivo, e interruptor **Simular fallo de envío** para poder ejercitar la cola de reintentos: el sandbox de SUNAT no valida la clave SOL, así que un envío nunca falla ahí por credenciales |
| **1.9.4** | El bloque de recojo y el DNI suben al principio del checkout (hook filtrable con `msp_recojo_hook_checkout`), y los archivos conservados se nombran con el correlativo completo |
| **1.9.3** | Sobre S/ 700 la boleta exige **DNI y nombre**, no solo el documento: una boleta de S/ 900 con DNI real a nombre de «CLIENTE VARIOS» es contradictoria y así saldría impresa |
| **1.9.2** | El correlativo va al XML con 8 dígitos (`B001-00000002`, no `B001-2`): el número registrado en SUNAT coincide con el del panel y el del ticket |
| **1.9.1** | Numeración separada por entorno: `entorno` entra en la clave única (`entorno, serie, correlativo`), así las boletas de prueba dejan de gastar números de la serie real. La cola no envía un comprobante de otro entorno. **DB_VERSION 5** |
| **1.9.0** | **Boletas Fase 3** — cola de emisión en segundo plano (Action Scheduler) con reintentos de espera creciente, alarma por correo a los 2 días, pantalla **Comprobantes** y captura de **DNI** en POS y checkout (obligatoria sobre S/ 700). Esquema **DB_VERSION 4** (`proximo_intento`, `alertado_at`) |

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
- **Reembolsos parciales:** el plugin solo reacciona a la anulación **total** de una venta (estados `cancelled` y `refunded`). Un reembolso parcial deja el pedido en `completed`, así que **no devuelve stock a la sede ni saca el efectivo de la caja**. La tabla "Ventas de este turno" marca esos pedidos como "devuelto en parte", pero los totales siguen contando la venta completa, **a propósito**: es lo que la caja registró, y una tabla que contradiga el cuadre confunde más de lo que ayuda. Si saraih hace devoluciones parciales, esto hay que resolverlo.
- **Anulación sin caja abierta:** si se anula una venta POS en efectivo cuyo turno ya se cerró y el cajero no tiene otra caja abierta, el egreso no se registra (no se toca un arqueo cerrado): queda una nota en el pedido para ajustarlo a mano.
- **Banner automático en temas con layout propio:** el banner se engancha a `woocommerce_before_main_content`; en temas/constructores que no lo disparen, colocar el selector con el shortcode manualmente.
- **Otras ideas:** reportes consolidados entre sedes, impresión de ticket, lector de código de barras por hardware, traslados de stock entre sedes.
