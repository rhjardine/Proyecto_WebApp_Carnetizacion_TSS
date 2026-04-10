<?php
/**
 * generar_hashes.php — Genera hashes bcrypt verificados para el seed
 * ===================================================================
 * Ejecutar UNA VEZ para generar los hashes e insertarlos en seed_mysql.sql.
 * Luego ELIMINAR este archivo.
 *
 * Uso: Abrir en el navegador o ejecutar con:
 *   C:\xampp\php\php.exe generar_hashes.php
 */
header('Content-Type: text/plain; charset=utf-8');

$passwords = [
    'admin' => 'admin123',
    'coordinador' => 'coord123',
    'analista' => 'analista123',
    'usuario' => 'usuario123',
    'consulta' => 'consulta123',
];

echo "=== HASHES BCRYPT VERIFICADOS ===\n\n";

foreach ($passwords as $user => $pass) {
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $verify = password_verify($pass, $hash) ? '✅ VERIFICADO' : '❌ FALLO';
    echo "Usuario: {$user}\n";
    echo "Clave:   {$pass}\n";
    echo "Hash:    {$hash}\n";
    echo "Estado:  {$verify}\n\n";
}

echo "=== SQL INSERT ===\n\n";
echo "INSERT INTO usuarios (usuario, clave_hash, nombre_completo, rol, bloqueado, intentos_fallidos)\nVALUES\n";

$entries = [];
$names = [
    'admin' => 'Administrador Principal SCI-TSS',
    'coordinador' => 'Coordinador de Carnetizacion',
    'analista' => 'Analista de Datos',
    'usuario' => 'Usuario Operativo',
    'consulta' => 'Usuario Solo Consulta',
];
$roles = [
    'admin' => 'ADMIN',
    'coordinador' => 'COORD',
    'analista' => 'ANALISTA',
    'usuario' => 'USUARIO',
    'consulta' => 'CONSULTA',
];

foreach ($passwords as $user => $pass) {
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $name = $names[$user];
    $role = $roles[$user];
    $entries[] = "    ('{$user}',\n     '{$hash}',\n     '{$name}', '{$role}', 0, 0)";
}

echo implode(",\n\n", $entries) . "\n";
echo "ON DUPLICATE KEY UPDATE\n";
echo "    bloqueado = 0,\n";
echo "    intentos_fallidos = 0;\n";
