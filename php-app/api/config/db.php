<?php
/**
 * api/config/db.php — Única Fuente de Verdad para Conexión, Configuración y Seguridad
 * ==============================================================================
 * Centraliza: Carga de entorno, Constantes Globales, Seguridad CORS y Conexión PDO.
 */

// 1. CARGA DE ENTORNO (.env) --------------------------------------------------
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
            $_ENV[trim($name)] = trim($value);
            putenv(trim($name) . '=' . trim($value));
        }
    }
}
loadEnv(__DIR__ . '/../../.env');

// 2. CONSTANTES GLOBALES -------------------------------------------------------
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

if (!defined('ENFORCE_CSRF'))
    define('ENFORCE_CSRF', filter_var(getenv('ENFORCE_CSRF') ?: true, FILTER_VALIDATE_BOOLEAN));
if (!defined('ENFORCE_HTTPS'))
    define('ENFORCE_HTTPS', filter_var(getenv('ENFORCE_HTTPS') ?: false, FILTER_VALIDATE_BOOLEAN));
if (!defined('SESSION_LIFETIME'))
    define('SESSION_LIFETIME', (int) (getenv('SESSION_LIFETIME') ?: 14400));
if (!defined('PASS_ROTATION_DAYS'))
    define('PASS_ROTATION_DAYS', (int) (getenv('PASS_ROTATION_DAYS') ?: 90));

if (!defined('APP_NAME'))
    define('APP_NAME', 'SCI-TSS');
if (!defined('APP_VERSION'))
    define('APP_VERSION', '2.6.0');
if (!defined('UPLOAD_DIR'))
    define('UPLOAD_DIR', __DIR__ . '/../../uploads/');

// 3. SEGURIDAD CORS (Solo para peticiones Web) --------------------------------
if (PHP_SAPI !== 'cli') {
    $allowedOrigins = ['http://localhost', 'http://localhost:80', 'http://127.0.0.1', 'http://localhost:3000', 'http://127.0.0.1:3000'];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-CSRF-Token');
    header('Vary: Origin');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

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
            error_log('[DB CONNECTION FAILED] ' . $e->getMessage());
            sendResponse(false, 'Error de conexión a la base de datos.', null, 500);
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
