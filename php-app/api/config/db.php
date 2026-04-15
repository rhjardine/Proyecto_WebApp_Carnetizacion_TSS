<?php
/**
 * api/config/db.php — Única Fuente de Verdad para Conexión DB (MySQL Singleton)
 * ===========================================================================
 * Centraliza la conexión, auditoría y respuestas de API.
 */

// ── CONFIGURACIÓN BÁSICA (Carga .env si existe) ───────────────────────────────
function loadEnv($path)
{
    if (!file_exists($path))
        return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0)
            continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            putenv(trim($name) . '=' . trim($value));
        }
    }
}
loadEnv(__DIR__ . '/../../includes/config.php'); // Cargar constantes si aún dependen de ahí temporalmente
loadEnv(__DIR__ . '/../../.env'); // Prioridad .env

// Valores por defecto (MySQL)
if (!defined('DB_HOST'))
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_PORT'))
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
if (!defined('DB_NAME'))
    define('DB_NAME', getenv('DB_NAME') ?: 'carnetizacion_tss');
if (!defined('DB_USER'))
    define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS'))
    define('DB_PASS', getenv('DB_PASS') ?: '');
if (!defined('DB_CHARSET'))
    define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

/**
 * getDB() — Singleton de conexión PDO.
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
            error_log('[DB CONNECTION FAILED] ' . $e->getMessage());
            sendResponse(false, 'Error de conexión a la base de datos.', null, 500);
        }
    }
    return $pdo;
}

/**
 * sendResponse() — Utilidad para respuestas API uniformes.
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
 * logAction() — Auditoría centralizada.
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
