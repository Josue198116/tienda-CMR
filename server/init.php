<?php
// Inicialización idempotente: crea tablas/vistas/procedures si faltan e inserta >=100 registros por tabla si están vacías.
function init(PDO $p): void {
  $n = (int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
        AND table_name IN ('categorias','clientes','usuarios','productos','pedidos','detalle_pedido','ventas','inventario')")->fetchColumn();
  if ($n < 8) {
    foreach (preg_split('/^-- split\s*$/m', file_get_contents(__DIR__ . '/schema.sql')) as $s)
      if (trim($s)) $p->exec($s);
  }
  seed($p);
}

function seed(PDO $p): void {
  $cnt = fn($t) => (int)$p->query("SELECT COUNT(*) FROM $t")->fetchColumn();
  mt_srand(42);
  $pick = fn($a) => $a[mt_rand(0, count($a) - 1)];
  $fecha = function () {
    $ahora = time();
    $t = mt_rand(1, 100) <= 20 ? mt_rand(strtotime(date('Y-m-01')), $ahora) : $ahora - mt_rand(0, 360 * 86400);
    return date('Y-m-d H:i:s', $t);
  };

  if (!$cnt('categorias')) {
    $a = ['Electrónica','Hogar','Oficina','Deportes','Juguetes','Belleza','Mascotas','Jardín','Cocina','Papelería'];
    $b = ['Básico','Premium','Económico','Importado','Ecológico','Portátil','Profesional','Infantil','Clásico','Smart'];
    $st = $p->prepare('INSERT INTO categorias(nombre,descripcion) VALUES(?,?)');
    foreach ($a as $x) foreach ($b as $y) $st->execute(["$x $y", "Línea $y de $x"]);
  }
  if (!$cnt('clientes')) {
    $nom = ['Ana','Luis','María','Carlos','Sofía','Jorge','Lucía','Pedro','Valeria','Diego','Elena','Andrés','Camila','Ricardo','Paola'];
    $ape = ['García','Hernández','López','Martínez','Pérez','González','Ramírez','Torres','Flores','Díaz','Cruz','Morales','Reyes','Ortiz','Castillo','Vargas','Ruiz','Mendoza','Aguilar','Chávez'];
    $ciu = ['Cancún','Playa del Carmen','Mérida','CDMX','Guadalajara','Monterrey','Puebla','Tulum'];
    $st = $p->prepare('INSERT INTO clientes(nombre,email,telefono,ciudad,creado_en) VALUES(?,?,?,?,?)');
    for ($i = 1; $i <= 100; $i++)
      $st->execute([$nom[$i % 15] . ' ' . $ape[($i * 7) % 20] . ' ' . $ape[($i * 3) % 20], "cliente$i@ejemplo.com", '998' . mt_rand(1000000, 9999999), $pick($ciu), $fecha()]);
  }
  // Correos basados en el nombre (corrige los tipo cliente1@ejemplo.com, una sola vez)
  $viejos = $p->query("SELECT id,nombre FROM clientes WHERE email REGEXP '^[a-z]+[0-9]+@'")->fetchAll();
  if ($viejos) {
    $usados = array_fill_keys($p->query('SELECT email FROM clientes')->fetchAll(PDO::FETCH_COLUMN), 1);
    $up = $p->prepare('UPDATE clientes SET email=? WHERE id=?');
    foreach ($viejos as $c) $up->execute([emailDe($c['nombre'], $usados), $c['id']]);
  }

  if (!$cnt('usuarios')) {
    $h = password_hash('admin123', PASSWORD_DEFAULT);
    $st = $p->prepare('INSERT INTO usuarios(usuario,nombre,rol,password_hash) VALUES(?,?,?,?)');
    $st->execute(['manager', 'Manager', 'administrador', $h]);
    for ($i = 2; $i <= 100; $i++) $st->execute(["vendedor$i", "Vendedor $i", 'vendedor', $h]);
  }
  if (!$cnt('productos')) {
    $n = ['Audífonos','Cargador','Mouse','Teclado','Cable HDMI','Bocina','Lámpara','Mochila','Termo','Libreta','Balón','Cafetera','Licuadora','Webcam','Soporte'];
    $m = ['Pro','Mini','Plus','Lite','Max','Eco','Smart','Duo','Neo','Air'];
    $st = $p->prepare('INSERT INTO productos(categoria_id,sku,nombre,precio,stock,activo) VALUES(?,?,?,?,?,1)');
    $i = 0;
    foreach ($n as $x) foreach ($m as $y) {
      $i++;
      $stock = mt_rand(1, 10) === 1 ? mt_rand(0, 5) : mt_rand(20, 200); // ~10% con stock bajo
      $st->execute([mt_rand(1, 100), 'SKU-' . str_pad($i, 4, '0', STR_PAD_LEFT), "$x $y", mt_rand(5000, 250000) / 100, $stock]);
    }
  }
  if (!$cnt('pedidos')) {
    $p->beginTransaction();
    $st = $p->prepare('INSERT INTO pedidos(cliente_id,fecha,estado) VALUES(?,?,?)');
    for ($i = 1; $i <= 300; $i++) {
      $r = mt_rand(1, 100);
      $e = $r <= 60 ? 'completado' : ($r <= 70 ? 'enviado' : ($r <= 80 ? 'pagado' : ($r <= 90 ? 'pendiente' : 'cancelado')));
      $st->execute([mt_rand(1, 100), $fecha(), $e]);
    }
    $precios = $p->query('SELECT id,precio FROM productos')->fetchAll(PDO::FETCH_KEY_PAIR);
    $ids = array_keys($precios);
    $st = $p->prepare('INSERT INTO detalle_pedido(pedido_id,producto_id,cantidad,precio_unitario) VALUES(?,?,?,?)');
    for ($i = 1; $i <= 300; $i++) {
      shuffle($ids);
      foreach (array_slice($ids, 0, mt_rand(1, 4)) as $pid) $st->execute([$i, $pid, mt_rand(1, 5), $precios[$pid]]);
    }
    $p->commit();
  }
  if (!$cnt('ventas')) {
    $p->exec("INSERT INTO ventas(pedido_id,fecha,total,metodo_pago)
      SELECT p.id, p.fecha, SUM(d.cantidad*d.precio_unitario), ELT(1+MOD(p.id,3),'efectivo','tarjeta','transferencia')
      FROM pedidos p JOIN detalle_pedido d ON d.pedido_id = p.id
      WHERE p.estado IN ('pagado','enviado','completado') GROUP BY p.id, p.fecha");
  }
  if (!$cnt('inventario')) {
    $st = $p->prepare('INSERT INTO inventario(producto_id,tipo,cantidad,fecha,nota) VALUES(?,?,?,?,?)');
    for ($i = 1; $i <= 200; $i++) $st->execute([mt_rand(1, 150), $pick(['entrada','salida','ajuste']), mt_rand(1, 50), $fecha(), 'Movimiento inicial']);
  }
}

function slug(string $s): string {
  $s = strtr(mb_strtolower($s, 'UTF-8'), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
  return preg_replace('/[^a-z0-9]/', '', $s);
}
function emailDe(string $nombre, array &$usados): string {
  $p = preg_split('/\s+/', trim($nombre));
  $base = slug($p[0]) . '.' . slug($p[1] ?? 'cliente');
  $dom = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com.mx'][abs(crc32($base)) % 4];
  $e = "$base@$dom"; $n = 1;
  while (isset($usados[$e])) { $n++; $e = "$base$n@$dom"; }
  $usados[$e] = 1;
  return $e;
}
