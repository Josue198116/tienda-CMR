-- Esquema normalizado (3FN). Cada bloque se separa con la línea "-- split" (lo ejecuta init.php).
CREATE TABLE IF NOT EXISTS categorias(
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL UNIQUE,
  descripcion VARCHAR(255)
) ENGINE=InnoDB
-- split
CREATE TABLE IF NOT EXISTS clientes(
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  telefono VARCHAR(20),
  ciudad VARCHAR(60),
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB
-- split
CREATE TABLE IF NOT EXISTS usuarios(
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(40) NOT NULL UNIQUE,
  nombre VARCHAR(100) NOT NULL,
  rol ENUM('administrador','vendedor') NOT NULL DEFAULT 'vendedor',
  password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB
-- split
CREATE TABLE IF NOT EXISTS productos(
  id INT AUTO_INCREMENT PRIMARY KEY,
  categoria_id INT NOT NULL,
  sku VARCHAR(20) NOT NULL UNIQUE,
  nombre VARCHAR(120) NOT NULL,
  precio DECIMAL(10,2) NOT NULL CHECK (precio >= 0),
  stock INT NOT NULL DEFAULT 0 CHECK (stock >= 0),
  activo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id)
) ENGINE=InnoDB
-- split
CREATE TABLE IF NOT EXISTS pedidos(
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  fecha DATETIME NOT NULL,
  estado ENUM('pendiente','pagado','enviado','completado','cancelado') NOT NULL DEFAULT 'pendiente',
  FOREIGN KEY (cliente_id) REFERENCES clientes(id),
  INDEX idx_pedidos_fecha (fecha)
) ENGINE=InnoDB
-- split
CREATE TABLE IF NOT EXISTS detalle_pedido(
  id INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id INT NOT NULL,
  producto_id INT NOT NULL,
  cantidad INT NOT NULL CHECK (cantidad > 0),
  precio_unitario DECIMAL(10,2) NOT NULL,
  UNIQUE (pedido_id, producto_id),
  FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB
-- split
CREATE TABLE IF NOT EXISTS ventas(
  id INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id INT NOT NULL UNIQUE,
  fecha DATETIME NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  metodo_pago ENUM('efectivo','tarjeta','transferencia') NOT NULL,
  FOREIGN KEY (pedido_id) REFERENCES pedidos(id),
  INDEX idx_ventas_fecha (fecha)
) ENGINE=InnoDB
-- split
CREATE TABLE IF NOT EXISTS inventario(
  id INT AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NOT NULL,
  tipo ENUM('entrada','salida','ajuste') NOT NULL,
  cantidad INT NOT NULL,
  fecha DATETIME NOT NULL,
  nota VARCHAR(120),
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB
-- split
CREATE OR REPLACE VIEW v_productos AS
SELECT p.id, p.categoria_id, c.nombre AS categoria, p.sku, p.nombre, p.precio, p.stock, p.activo
FROM productos p JOIN categorias c ON c.id = p.categoria_id
-- split
CREATE OR REPLACE VIEW v_pedidos AS
SELECT p.id, p.fecha, c.nombre AS cliente, p.estado, COALESCE(SUM(d.cantidad*d.precio_unitario),0) AS total
FROM pedidos p JOIN clientes c ON c.id = p.cliente_id LEFT JOIN detalle_pedido d ON d.pedido_id = p.id
GROUP BY p.id, p.fecha, c.nombre, p.estado
-- split
CREATE OR REPLACE VIEW v_ventas AS
SELECT v.id, v.pedido_id, v.fecha, c.nombre AS cliente, v.total, v.metodo_pago
FROM ventas v JOIN pedidos p ON p.id = v.pedido_id JOIN clientes c ON c.id = p.cliente_id
-- split
CREATE OR REPLACE VIEW v_inventario AS
SELECT i.id, i.fecha, pr.nombre AS producto, i.tipo, i.cantidad, i.nota
FROM inventario i JOIN productos pr ON pr.id = i.producto_id
-- split
CREATE OR REPLACE VIEW v_top_productos AS
SELECT pr.id, pr.nombre, SUM(d.cantidad) AS unidades, SUM(d.cantidad*d.precio_unitario) AS ingresos
FROM productos pr JOIN detalle_pedido d ON d.producto_id = pr.id JOIN pedidos p ON p.id = d.pedido_id
WHERE p.estado <> 'cancelado' GROUP BY pr.id, pr.nombre
-- split
CREATE OR REPLACE VIEW v_stock_bajo AS
SELECT id, sku, nombre, stock FROM productos WHERE activo = 1 AND stock <= 5
-- split
DROP PROCEDURE IF EXISTS sp_crear_pedido
-- split
CREATE PROCEDURE sp_crear_pedido(IN p_cliente INT, IN p_producto INT, IN p_cant INT)
BEGIN
  DECLARE v_stock INT; DECLARE v_precio DECIMAL(10,2); DECLARE v_id INT;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  START TRANSACTION;
  SELECT stock, precio INTO v_stock, v_precio FROM productos WHERE id = p_producto AND activo = 1 FOR UPDATE;
  IF v_stock IS NULL OR v_stock < p_cant THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock insuficiente o producto inactivo';
  END IF;
  INSERT INTO pedidos(cliente_id, fecha, estado) VALUES (p_cliente, NOW(), 'pendiente');
  SET v_id = LAST_INSERT_ID();
  INSERT INTO detalle_pedido(pedido_id, producto_id, cantidad, precio_unitario) VALUES (v_id, p_producto, p_cant, v_precio);
  UPDATE productos SET stock = stock - p_cant WHERE id = p_producto;
  INSERT INTO inventario(producto_id, tipo, cantidad, fecha, nota) VALUES (p_producto, 'salida', p_cant, NOW(), CONCAT('Pedido #', v_id));
  COMMIT;
  SELECT v_id AS pedido_id;
END
-- split
DROP PROCEDURE IF EXISTS sp_registrar_venta
-- split
CREATE PROCEDURE sp_registrar_venta(IN p_pedido INT, IN p_metodo VARCHAR(20))
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  START TRANSACTION;
  INSERT INTO ventas(pedido_id, fecha, total, metodo_pago)
    SELECT p_pedido, NOW(), SUM(cantidad*precio_unitario), p_metodo FROM detalle_pedido WHERE pedido_id = p_pedido;
  UPDATE pedidos SET estado = 'pagado' WHERE id = p_pedido;
  COMMIT;
END
-- split
DROP PROCEDURE IF EXISTS sp_ventas_por_mes
-- split
CREATE PROCEDURE sp_ventas_por_mes()
SELECT DATE_FORMAT(fecha,'%Y-%m') AS mes, COUNT(*) AS ventas, SUM(total) AS total
FROM ventas WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
GROUP BY DATE_FORMAT(fecha,'%Y-%m') ORDER BY mes
-- split
DROP PROCEDURE IF EXISTS sp_top_productos
-- split
CREATE PROCEDURE sp_top_productos(IN p_n INT)
SELECT * FROM v_top_productos ORDER BY unidades DESC LIMIT p_n
