<?php
/**
 * check_db.php — Herramienta de Diagnóstico del Sistema
 * =======================================================
 * Ejecutar: http://localhost/php-app/check_db.php
 * Verifica que las tablas obligatorias existan y que la conexión PDO funcione.
 * NO USA SESIONES PARA EVITAR ERRORES SECUNDARIOS.
 */

header('Content-Type: application/json; charset=utf-8');

// Configuración de DB hardcodeada para no depender de otros scripts en diagnóstico
$envPath = __DIR__ . '/.env';
$dbName = 'carnetizacion_tss';
$dbUser = 'root';
$dbPass = '';

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos(trim($line), '#') !== 0) {
            list($key, $val) = explode('=', $line, 2);
            if (trim($key) === 'DB_NAME')
                $dbName = trim($val);
            if (trim($key) === 'DB_USER')
                $dbUser = trim($val);
            if (trim($key) === 'DB_PASS')
                $dbPass = trim($val);
        }
    }
}

try {
    $dsn = "mysql:host=127.0.0.1;port=3306;dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $requiredTables = ['usuarios', 'empleados', 'gerencias', 'roles', 'permisos', 'auditoria_logs'];
    $status = [];
    $allOk = true;

    foreach ($requiredTables as $table) {
        try {
            $stmt = $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            $status[$table] = "OK (Existe)";
        } catch (PDOException $e) {
            $status[$table] = "ERROR (No existe o inaccesible)";
            $allOk = false;
        }
    }

    echo json_encode([
        'success' => $allOk,
        'message' => $allOk ? 'Base de datos operativa.' : 'Faltan tablas críticas.',
        'database' => $dbName,
        'tables' => $status
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fallo de conexión PDO: ' . $e->getMessage(),
        'database' => $dbName
    ], JSON_PRETTY_PRINT);
}
