<?php
/**
 * api/config/db.php — Conexión PDO, CORS y helpers de respuesta API
 * ==============================================================================
 * NOTA ARQUITECTÓNICA: loadEnv() y todas las constantes de configuración están
 * definidas en config_fixed.php, el cual se carga ANTES que este archivo en el
 * orden de bootstrap. No se re-declaran aquí para evitar conflictos de orden.
 */

// CORS (depende de las constantes ya definidas en config_fixed.php)
require_once __DIR__ . '/cors.php';

/**
 * getDB() — Singleton de conexión PDO a MySQL.
 */
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE " . DB_CHARSET . "_unicode_ci"
            ]);
        } catch (PDOException $e) {
            // PARCHE DE DEVOPS: Mostrar detalles para fácil diagnóstico en despliegue.
            $debugMode = defined('APP_DEBUG') ? APP_DEBUG : true;
            
            $msg = $debugMode 
                ? 'Error BD: ' . $e->getMessage() . ' | Host: ' . DB_HOST . ':' . DB_PORT . ' | BD: ' . DB_NAME . ' | Usr: ' . DB_USER
                : 'Error de conexión a la base de datos. Verifique sus credenciales.';
                
            error_log('[DB CONNECTION FAILED] ' . $e->getMessage());
            sendResponse(false, $msg, null, 500);
        }
    }
    return $pdo;
}

/**
 * sendResponse() — Utilidad para respuestas API uniformes JSON.
 */
function sendResponse(bool $success, string $message = '', $data = null, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    $body = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        if (is_array($data) && (isset($data['data']) || isset($data['meta']))) {
            $body = array_merge($body, $data);
        } else {
            $body['data'] = $data;
        }
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * logAction() — Registro de auditoría centralizado.
 */
function logAction(PDO $db, ?int $userId, string $accion, array $detalles = []): void
{
    try {
        $detalles['_ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
        $detalles['_userAgent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $detalles['_timestamp'] = date('c');

        $stmt = $db->prepare('INSERT INTO auditoria_logs (usuario_id, accion, detalles, direccion_ip, agente_usuario, creado_el)
                              VALUES (:uid, :acc, :det, :ip, :ua, NOW())');
        $stmt->execute([
            ':uid' => $userId,
            ':acc' => strtoupper(substr($accion, 0, 50)),
            ':det' => json_encode($detalles, JSON_UNESCAPED_UNICODE),
            ':ip' => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    } catch (Exception $e) {
        error_log('[AUDIT LOG FAIL] ' . $e->getMessage());
    }
}
