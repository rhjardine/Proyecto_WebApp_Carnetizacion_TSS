<?php
/**
 * db.php — Conexión centralizada a PostgreSQL usando PDO
 */

// Configuración de PostgreSQL Local (XAMPP)
$host = 'localhost';
$port = '5432';
$dbname = 'carnetizacion_db';
$user = 'postgres';
$password = 'admin123'; // Cambia esto por tu contraseña de PostgreSQL local


try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    // En producción coordinar con un log de errores, aquí enviamos JSON de error
    header('Content-Type: application/json', true, 500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión a la base de datos: ' . $e->getMessage()
    ]);
    exit;
}

/**
 * Función auxiliar para enviar respuestas JSON uniformes
 */
function sendResponse($success, $message = '', $data = null, $code = 200)
{
    header('Content-Type: application/json', true, $code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}
