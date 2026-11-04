<?php
// API REST (JSON). Uso: api.php?r=<recurso>[&id=N][&q=texto][&page=N]
header('Content-Type: application/json; charset=utf-8');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/../server/db.php';

function out($d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function rows(PDO $p, string $sql, array $args = []): array {
  $st = $p->prepare($sql); $st->execute($args);
  $r = $st->fetchAll(); $st->closeCursor(); return $r;
}

$R = [
  'clientes'   => ['src' => 'clientes',     'tbl' => 'clientes',   'f' => ['nombre','email','telefono','ciudad'], 'q' => ['nombre','email','ciudad']],
  'categorias' => ['src' => 'categorias',   'tbl' => 'categorias', 'f' => ['nombre','descripcion'],               'q' => ['nombre','descripcion']],
  'productos'  => ['src' => 'v_productos',  'tbl' => 'productos',  'f' => ['categoria_id','sku','nombre','precio','stock','activo'], 'q' => ['sku','nombre','categoria']],
  'pedidos'    => ['src' => 'v_pedidos',    'q' => ['cliente','estado']],
  'ventas'     => ['src' => 'v_ventas',     'q' => ['cliente','metodo_pago']],
  'inventario' => ['src' => 'v_inventario', 'q' => ['producto','tipo']],
];

$LAB = [
  ['INNER JOIN: pedidos con su cliente', "SELECT p.id, c.nombre AS cliente, p.fecha, p.estado FROM pedidos p INNER JOIN clientes c ON c.id = p.cliente_id ORDER BY p.fecha DESC LIMIT 20"],
  ['LEFT JOIN: clientes sin pedidos', "SELECT c.id, c.nombre FROM clientes c LEFT JOIN pedidos p ON p.cliente_id = c.id WHERE p.id IS NULL LIMIT 20"],
  ['GROUP BY + HAVING: ingresos por categoría', "SELECT c.nombre AS categoria, SUM(d.cantidad*d.precio_unitario) AS ingresos FROM categorias c JOIN productos p ON p.categoria_id = c.id JOIN detalle_pedido d ON d.producto_id = p.id GROUP BY c.id, c.nombre HAVING ingresos > 5000 ORDER BY ingresos DESC LIMIT 20"],
  ['Subconsulta: productos sobre el precio promedio', "SELECT nombre, precio FROM productos WHERE precio > (SELECT AVG(precio) FROM productos) ORDER BY precio DESC LIMIT 20"],
  ['Vista: productos más vendidos', "SELECT * FROM v_top_productos ORDER BY unidades DESC LIMIT 10"],
  ['Vista: stock bajo (<= 5)', "SELECT * FROM v_stock_bajo ORDER BY stock LIMIT 20"],
  ['Agregación: ventas por método de pago', "SELECT metodo_pago, COUNT(*) AS ventas, SUM(total) AS total FROM ventas GROUP BY metodo_pago"],
  ['Stored procedure: ventas por mes', "CALL sp_ventas_por_mes()"],
];

try {
  $p = db();
  $r = $_GET['r'] ?? '';
  $m = $_SERVER['REQUEST_METHOD'];
  $in = json_decode(file_get_contents('php://input'), true) ?: [];
  $id = (int)($_GET['id'] ?? 0);
  if ($r === 'login') {
    $u = rows($p, 'SELECT id,usuario,nombre,rol,password_hash FROM usuarios WHERE usuario=?', [trim($in['usuario'] ?? '')])[0] ?? null;
    if (!$u || !password_verify($in['password'] ?? '', $u['password_hash'])) out(['error' => 'Usuario o contraseña incorrectos'], 401);
    session_regenerate_id(true);
    $_SESSION['u'] = ['id' => $u['id'], 'usuario' => $u['usuario'], 'nombre' => $u['nombre'], 'rol' => $u['rol']];
    out($_SESSION['u']);
  }
  if ($r === 'logout') { $_SESSION = []; session_destroy(); out(['ok' => true]); }
  if (empty($_SESSION['u'])) out(['error' => 'Inicia sesión'], 401);
  if ($r === 'me') out($_SESSION['u']);

  if ($r === 'dashboard') {
    $one = fn($s) => $p->query($s)->fetchColumn();
    out([
      'clientes' => (int)$one('SELECT COUNT(*) FROM clientes'),
      'productos' => (int)$one('SELECT COUNT(*) FROM productos WHERE activo=1'),
      'pedidos' => (int)$one('SELECT COUNT(*) FROM pedidos'),
      'ventas_total' => (float)$one('SELECT COALESCE(SUM(total),0) FROM ventas'),
      'ingresos_mes' => (float)$one('SELECT COALESCE(SUM(total),0) FROM ventas WHERE YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE())'),
      'stock_bajo' => (int)$one('SELECT COUNT(*) FROM v_stock_bajo'),
      'meses' => rows($p, 'CALL sp_ventas_por_mes()'),
      'top' => rows($p, 'CALL sp_top_productos(?)', [5]),
    ]);
  }
  if ($r === 'reportes') {
    out([
      'meses' => rows($p, 'CALL sp_ventas_por_mes()'),
      'categorias' => rows($p, "SELECT c.nombre AS categoria, SUM(d.cantidad) AS unidades, SUM(d.cantidad*d.precio_unitario) AS ingresos FROM categorias c JOIN productos pr ON pr.categoria_id=c.id JOIN detalle_pedido d ON d.producto_id=pr.id GROUP BY c.id, c.nombre ORDER BY ingresos DESC LIMIT 10"),
      'clientes' => rows($p, "SELECT c.nombre AS cliente, COUNT(v.id) AS compras, SUM(v.total) AS total FROM clientes c JOIN pedidos pe ON pe.cliente_id=c.id JOIN ventas v ON v.pedido_id=pe.id GROUP BY c.id, c.nombre ORDER BY total DESC LIMIT 10"),
    ]);
  }
  if ($r === 'lab') {
    if (!isset($_GET['n'])) out(array_map(fn($q, $i) => ['n' => $i, 'titulo' => $q[0], 'sql' => $q[1]], $LAB, array_keys($LAB)));
    $q = $LAB[(int)$_GET['n']] ?? out(['error' => 'Consulta no encontrada'], 404);
    out(['sql' => $q[1], 'rows' => rows($p, $q[1])]);
  }
  if ($r === 'opciones') {
    $t = ['categorias' => 'categorias', 'clientes' => 'clientes', 'productos' => 'productos'][$_GET['t'] ?? ''] ?? out(['error' => 'Tabla inválida'], 400);
    out(rows($p, "SELECT id, nombre FROM $t ORDER BY nombre"));
  }

  $c = $R[$r] ?? out(['error' => 'Recurso no encontrado'], 404);

  if ($m === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1)); $size = 10;
    $q = trim($_GET['q'] ?? ''); $where = ''; $args = [];
    if ($q !== '') { $where = 'WHERE CONCAT_WS(" ",' . implode(',', $c['q']) . ') LIKE ?'; $args[] = "%$q%"; }
    $total = (int)rows($p, "SELECT COUNT(*) AS n FROM {$c['src']} $where", $args)[0]['n'];
    $off = ($page - 1) * $size;
    $data = rows($p, "SELECT * FROM {$c['src']} $where ORDER BY id DESC LIMIT $size OFFSET $off", $args);
    out(['data' => $data, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $size))]);
  }

  if ($m === 'POST') {
    if ($r === 'pedidos') out(rows($p, 'CALL sp_crear_pedido(?,?,?)', [(int)$in['cliente_id'], (int)$in['producto_id'], (int)$in['cantidad']])[0], 201);
    if ($r === 'ventas') { rows($p, 'CALL sp_registrar_venta(?,?)', [(int)$in['pedido_id'], $in['metodo_pago']]); out(['ok' => true], 201); }
    if ($r === 'inventario') {
      $mov = ['entrada' => 1, 'salida' => -1, 'ajuste' => 1][$in['tipo']] ?? out(['error' => 'Tipo inválido'], 400);
      $p->beginTransaction();
      $p->prepare('INSERT INTO inventario(producto_id,tipo,cantidad,fecha,nota) VALUES(?,?,?,NOW(),?)')->execute([(int)$in['producto_id'], $in['tipo'], (int)$in['cantidad'], $in['nota'] ?? '']);
      $p->prepare('UPDATE productos SET stock = stock + ? WHERE id = ?')->execute([$mov * (int)$in['cantidad'], (int)$in['producto_id']]);
      $p->commit(); out(['ok' => true], 201);
    }
    $f = $c['f']; $vals = array_map(fn($k) => ($in[$k] ?? '') === '' ? null : $in[$k], $f);
    $p->prepare("INSERT INTO {$c['tbl']}(" . implode(',', $f) . ') VALUES(' . implode(',', array_fill(0, count($f), '?')) . ')')->execute($vals);
    out(['id' => (int)$p->lastInsertId()], 201);
  }

  if ($m === 'PUT' && isset($c['tbl']) && $id) {
    $f = $c['f']; $vals = array_map(fn($k) => ($in[$k] ?? '') === '' ? null : $in[$k], $f); $vals[] = $id;
    $p->prepare("UPDATE {$c['tbl']} SET " . implode(',', array_map(fn($k) => "$k=?", $f)) . ' WHERE id=?')->execute($vals);
    out(['ok' => true]);
  }
  if ($m === 'DELETE' && isset($c['tbl']) && $id) {
    $p->prepare("DELETE FROM {$c['tbl']} WHERE id=?")->execute([$id]);
    out(['ok' => true]);
  }
  out(['error' => 'Método no permitido'], 405);

} catch (PDOException $e) {
  $s = (string)$e->getCode();
  if (isset($p) && $p->inTransaction()) $p->rollBack();
  if ($s === '45000') out(['error' => $e->errorInfo[2] ?? $e->getMessage()], 400);
  if ($s === '23000') out(['error' => 'Registro duplicado o con datos relacionados (no se puede completar la operación).'], 409);
  out(['error' => 'Error de base de datos: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
  out(['error' => $e->getMessage()], 500);
}
