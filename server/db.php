<?php
// Conexión PDO. Crea la BD "tienda" si no existe y ejecuta init() (tablas, vistas, procedures y datos de prueba).
function db(): PDO {
  static $pdo;
  if ($pdo) return $pdo;
  $c = require __DIR__ . '/config.php';
  $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
  $srv = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'], $opt);
  $srv->exec("CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], $opt);
  require_once __DIR__ . '/init.php';
  init($pdo);
  return $pdo;
}
