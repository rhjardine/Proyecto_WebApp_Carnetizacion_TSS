<?php
/**
 * db_mysql.php — Conexión centralizada a MySQL (InnoDB) usando PDO
 * ================================================================
 * Sistema de Carnetización Inteligente (SCI-TSS)
 * Motor: MySQL 8.x | Esquema: carnetizacion_tss
 * ================================================================
 * SEGURIDAD:
 *  - Credenciales cargadas desde variable de entorno o constantes.
 *  - PDO con prepared statements (ATTR_EMULATE_PREPARES = false).
 *  - Charset utf8mb4 forzado en DSN para prevenir ataques por encoding.
 *  - NEVER exponer este archivo vía web (protegido por .htaccess).
 * ================================================================
 */

// ── CONFIGURACIÓN DE CONEXIÓN ────────────────────────────────
require_once __DIR__ . '/config.php';

/**
 * getDB() — Singleton de conexión PDO a MySQL.
 *
 * Patrón Singleton: una única instancia PDO por ejecución PHP
 * (evita reconexiones costosas en scripts con múltiples consultas).
 *
 * @return PDO Instancia configurada y lista para prepared statements.
 * @throws RuntimeException Si la conexión falla (el error ya fue enviado al cliente).
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        // DSN incluye charset para prevenir ataques de encoding (MySQL charset injection)
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $opciones = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,   // Excepciones en lugar de error silencioso
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,          // Arrays asociativos por defecto
            PDO::ATTR_EMULATE_PREPARES => false,                     // Prepared statements nativos del motor (más seguro)
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci", // Forzar charset en sesión
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
        } catch (PDOException $e) {
            // Log interno del servidor (NUNCA exponer detalles al cliente)
            error_log('[SCI-TSS DB ERROR] ' . $e->getMessage());

            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Error de conexión a la base de datos. Contacte al administrador.',
            ]);
            exit;
        }
    }

    return $pdo;
}

/**
 * sendResponse() — Envía una respuesta JSON uniforme al cliente.
 *
 * @param bool        $success Indica si la operación fue exitosa.
 * @param string      $message Mensaje descriptivo de la operación.
 * @param mixed|null  $data    Payload de datos (array, objeto, null).
 * @param int         $code    Código HTTP de respuesta (default 200).
 */
function sendResponse(bool $success, string $message = '', $data = null, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');

    $body = [
        'success' => $success,
        'message' => $message,
    ];

    // Solo incluir 'data' si no es null (evita confusión en el cliente)
    if ($data !== null) {
        // Si $data tiene claves 'data' y 'meta', aplanar al nivel raíz
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
 * logAction() — Registra una operación sensible en auditoria_logs.
 *
 * DISEÑO NO BLOQUEANTE: si el log falla, la operación principal continúa.
 * Esto garantiza que errores en el log de auditoría no interrumpan
 * transacciones críticas (ej: guardar un empleado).
 *
 * @param PDO      $db       Instancia PDO activa.
 * @param int|null $userId   ID del usuario de sesión que ejecutó la acción.
 * @param string   $accion   Código de operación (ej: 'EMPLEADO_CREADO').
 * @param array    $detalles Datos contextuales adicionales (se serializan a JSON).
 */
function logAction(PDO $db, ?int $userId, string $accion, array $detalles = []): void
{
    try {
        // Enriquecer automáticamente con metadata de red
        $detalles['_ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
        $detalles['_userAgent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $detalles['_timestamp'] = date('c');  // ISO 8601

        $stmt = $db->prepare(
            'INSERT INTO auditoria_logs
                (usuario_id, accion, detalles, direccion_ip, agente_usuario, creado_el)
             VALUES
                (:usuario_id, :accion, :detalles, :ip, :ua, NOW())'
        );

        $stmt->execute([
            ':usuario_id' => $userId,
            ':accion' => strtoupper(substr($accion, 0, 50)),
            ':detalles' => json_encode($detalles, JSON_UNESCAPED_UNICODE),
            ':ip' => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);

    } catch (Exception $e) {
        // Fallo de auditoría: loguear en error_log del servidor, NO interrumpir.
        error_log('[SCI-TSS AUDIT FAIL] accion=' . $accion . ' | error=' . $e->getMessage());
    }
}
