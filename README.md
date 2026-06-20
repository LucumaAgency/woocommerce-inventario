# Multisede POS

Plugin de WordPress que extiende **WooCommerce** para operar varias tiendas físicas + la tienda virtual:

- **Inventario por sede** — stock independiente por cada tienda.
- **Recojo en tienda** — sin delivery por web; el cliente compra y recoge en la sede elegida.
- **POS de mostrador** — cobro presencial que genera pedidos WooCommerce.
- **Caja chica** — apertura, ingresos, egresos y arqueo por sede y turno.

> Se apoya en WooCommerce (productos, pedidos, pagos, Local Pickup). No lo reemplaza.

## Requisitos

- WordPress 6.0+
- PHP 7.4+
- WooCommerce 7.0+

## Estado del desarrollo

| Fase | Módulo | Estado |
|---|---|---|
| 1 | Esqueleto + Sedes + Roles | ✅ Implementado |
| 2 | Inventario multi-sede | ✅ Implementado |
| 3 | Recojo en tienda | ✅ Implementado |
| 4 | POS de mostrador | ✅ Implementado |
| 5 | Caja chica | ⏳ Pendiente |

La Fase 1 ya crea: el menú **Sedes**, los roles **Gerente de sede** y **Cajero**, y las tablas `msp_stock`, `msp_caja_sesiones` y `msp_caja_movimientos` (listas para las fases siguientes).

## Instalación

1. Copiar la carpeta `multisede-pos` a `wp-content/plugins/` (o instalar vía Git Updater, ver abajo).
2. Activar **Multisede POS** desde *Plugins*.
3. Aparece el menú **Sedes**: crear las tiendas físicas y marcar una como *tienda virtual*.

## Despliegue GitHub → WordPress (Git Updater)

El archivo principal incluye las cabeceras `GitHub Plugin URI` y `Primary Branch`. Pasos:

1. Crear el repo en GitHub y editar en `multisede-pos.php` las líneas:
   - `Plugin URI:` y `GitHub Plugin URI:` → poner tu usuario/repo real.
2. Instalar el plugin **Git Updater** en el WordPress.
3. Conectar el repositorio; a partir de ahí cada *release/tag* se ofrece como actualización desde el panel de WP.

Para producción se puede migrar a un *deploy* por GitHub Actions (SFTP/SSH).

## Estructura

```
multisede-pos/
├── multisede-pos.php          # archivo principal
├── uninstall.php
├── includes/
│   ├── class-msp-plugin.php       # bootstrap
│   ├── class-msp-activator.php    # tablas + roles
│   ├── class-msp-deactivator.php
│   ├── class-msp-sedes.php        # CPT sede
│   └── class-msp-roles.php
└── languages/
```
