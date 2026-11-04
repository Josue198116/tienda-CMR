<?php
// Ajusta aquí tus credenciales (XAMPP: root sin contraseña)
return [
  'host' => getenv('DB_HOST') ?: '127.0.0.1',
  'port' => getenv('DB_PORT') ?: 3306,
  'user' => getenv('DB_USER') ?: 'root',
  'pass' => getenv('DB_PASS') ?: '',
  'name' => 'tienda_crm',
];
