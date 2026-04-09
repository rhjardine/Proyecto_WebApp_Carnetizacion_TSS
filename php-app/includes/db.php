<?php
/**
 * db.php — Conexión centralizada a MySQL (InnoDB) usando PDO
 * ==========================================================
 * Sistema de Carnetización Inteligente (SCI-TSS)
 * Esquema: carnetizacion_tss | Motor: MySQL 8.x via XAMPP
 *
 * MIGRACIÓN: Actualizado de PostgreSQL a MySQL para XAMPP en pre-producción.
 *
 * SEGURIDAD:
 *  - Credenciales: cargar desde .env en producción (no hardcodear).
 *  - charset=utf8mb4 en DSN: previene ataques de encoding en MySQL.
 *  - PDO con prepared statements nativos (ATTR_EMULATE_PREPARES = false).
 *  - NEVER exponer este archivo vía web (protegido por .htaccess).
 */

// ── CONFIGURACIÓN (cambiar en producción) ────────────────────
$host    = getenv('DB_HOST')    ?: 'localhost';
$port    = getenv('DB_PORT')    ?: '3306';
$dbname  = getenv('DB_NAME')    ?: 'carnetizacion_tss';
$user    = getenv('DB_USER')    ?: 'root';
$password= getenv('DB_PASS')    ?: '';         // ⚠️ Cambiar en producción
$charset = 'utf8mb4';

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
} catch (PDOException $e) {
    error_log('[SCI-TSS DB] ' . $e->getMessage());
    header('Content-Type: application/json', true, 500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión a la base de datos. Verifique que MySQL esté activo en XAMPP.',
    ]);
    exit;
}

/**
 * sendResponse() — Respuesta JSON uniforme para todos los endpoints API.
 *
 * @param bool        $success   Resultado de la operación.
 * @param string      $message   Mensaje descriptivo.
 * @param mixed|null  $data      Payload de datos (se aplana si tiene claves 'data'/'meta').
 * @param int         $code      Código HTTP de respuesta.
 */
function sendResponse(bool $success, string $message = '', $data = null, int $code = 200): void
{
    header('Content-Type: application/json; charset=utf-8', true, $code);

    $body = ['success' => $success, 'message' => $message];

    if ($data !== null) {
        if (is_array($data) && (array_key_exists('data', $data) || array_key_exists('meta', $data))) {
            $body = array_merge($body, $data);
        } else {
            $body['data'] = $data;
        }
    }

    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * logAction() — Registra operaciones sensibles en auditoria_logs.
 * No bloqueante: un fallo en el log no interrumpe la operación principal.
 */
function logAction(PDO $pdo, ?int $userId, string $accion, array $detalles = []): void
{
    try {
        $detalles['_ip']        = $_SERVER['REMOTE_ADDR']     ?? null;
        $detalles['_userAgent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $detalles['_timestamp'] = date('c');

        $stmt = $pdo->prepare(
            "INSERT INTO auditoria_logs
                (usuario_id, accion, detalles, direccion_ip, agente_usuario, creado_el)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $userId,
            strtoupper(substr($accion, 0, 50)),
            json_encode($detalles, JSON_UNESCAPED_UNICODE),
            substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    } catch (Exception $e) {
        error_log('[SCI-TSS AUDIT FAIL] ' . $accion . ' → ' . $e->getMessage());
    }
}
