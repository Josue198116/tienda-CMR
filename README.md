# Tienda CRM (PHP + MariaDB/MySQL + Bootstrap + AJAX)

CRM mínimo con la base de datos `tienda`: tablas normalizadas (3FN), vistas, stored procedures, JOINs,
API REST en JSON y una interfaz minimalista en tonos azules.

## Estructura
```
tienda-crm/
├─ server/
│  ├─ config.php   ← credenciales de la BD
│  ├─ db.php       ← conexión PDO (crea la BD si no existe)
│  ├─ init.php     ← crea tablas/vistas/procedures y siembra ≥100 registros por tabla (idempotente)
│  └─ schema.sql   ← DDL: tablas, vistas y stored procedures
└─ public/
   ├─ api.php      ← API REST
   ├─ index.html · app.js · style.css   ← interfaz (Bootstrap + fetch/AJAX + Chart.js)
```

## Requisitos
- PHP 8.0+ con la extensión `pdo_mysql` (ya viene en XAMPP).
- MariaDB 10.2+ o MySQL 8.0.16+ encendido (XAMPP → Start en MySQL).
- Internet para cargar Bootstrap, iconos y Chart.js desde CDN.

## Ejecutar en VS Code
1. Abre la carpeta `tienda-crm` en VS Code (Archivo → Abrir carpeta).
2. Revisa `server/config.php` (por defecto: root, sin contraseña, 127.0.0.1:3306).
3. En la terminal de VS Code: `php -S localhost:8000 -t public`
   (o Terminal → Ejecutar tarea → "Iniciar Tienda CRM").
4. Abre https://specification-planner-ips-northwest.trycloudflare.com/tienda-crm/public/
   - Con XAMPP también puedes copiar la carpeta a `htdocs` y abrir `https://specification-planner-ips-northwest.trycloudflare.com/tienda-crm/public/

La primera carga crea la base `tienda`, las tablas, vistas y procedures, e inserta datos de prueba
(100 categorías, 100 clientes, 100 usuarios, 150 productos, 300 pedidos, ~700 detalles, ~210 ventas, 200 movimientos).
Para reiniciar: `DROP DATABASE tienda;` y recarga la página.

## Normalización
- Catálogos separados (`categorias`, `clientes`, `usuarios`, `productos`); sin datos repetidos.
- `pedidos` (encabezado) y `detalle_pedido` (líneas, con `precio_unitario` histórico) resuelven la relación N:M pedido–producto.
- `ventas` referencia al pedido (1:1) y `inventario` guarda los movimientos de stock.
- Llaves foráneas, UNIQUE, CHECK e índices por fecha.

## Stored procedures y vistas
| Objeto | Uso |
|---|---|
| `sp_crear_pedido(cliente, producto, cantidad)` | Valida stock, crea pedido + detalle, descuenta stock y registra movimiento (transacción) |
| `sp_registrar_venta(pedido, metodo)` | Calcula el total desde el detalle y marca el pedido como pagado |
| `sp_ventas_por_mes()` | Ventas de los últimos 12 meses |
| `sp_top_productos(n)` | Los n productos más vendidos |
| `v_productos`, `v_pedidos`, `v_ventas`, `v_inventario`, `v_top_productos`, `v_stock_bajo` | Vistas con JOINs para listados y reportes |

## API (`public/api.php?r=...`)
| Método | Ruta | Descripción |
|---|---|---|
| GET | `?r=dashboard` | KPIs, ventas por mes y top 5 productos |
| GET | `?r=reportes` | Ventas por mes, top categorías, top clientes |
| GET | `?r=lab` / `?r=lab&n=3` | Lista / ejecuta consultas de ejemplo (JOIN, HAVING, subconsulta, vistas, CALL) |
| GET | `?r={clientes,categorias,productos,pedidos,ventas,inventario}&q=texto&page=1` | Listado paginado con búsqueda |
| POST | `?r={clientes,categorias,productos}` | Crear (JSON) |
| PUT | `?r={clientes,categorias,productos}&id=N` | Actualizar (JSON) |
| DELETE | `?r={clientes,categorias,productos}&id=N` | Eliminar |
| POST | `?r=pedidos` `{cliente_id, producto_id, cantidad}` | Llama a `sp_crear_pedido` |
| POST | `?r=ventas` `{pedido_id, metodo_pago}` | Llama a `sp_registrar_venta` |
| POST | `?r=inventario` `{producto_id, tipo, cantidad, nota}` | Registra movimiento y ajusta stock |

Todas las consultas usan sentencias preparadas (PDO). Errores en JSON con código HTTP adecuado (400, 404, 409, 500).


