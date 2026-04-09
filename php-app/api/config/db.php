<?php
/**
 * Database Configuration — Sistema de Carnetización TSS
 * Uses PDO with PostgreSQL for secure, typed queries.
 * NEVER expose this file via web server (protected by .htaccess).
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'carnetizacion_db');
define('DB_USER', 'postgres');
define('DB_PASS', 'postgres'); // Change in production

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                DB_HOST, DB_PORT, DB_NAME
            );
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
            exit;
        }
    }
    return $pdo;
}

/**
 * logAction — Registra una acción de auditoría en la tabla audit_log.
 *
 * @param PDO        $db      Instancia de conexión PDO (de getDB())
 * @param int|null   $userId  ID del usuario de sesión ($_SESSION['user_id'])
 * @param string     $action  Acción ejecutada (ej. 'EMPLOYEE_CREATED')
 * @param array      $details Datos contextuales como arreglo asociativo (se guarda como JSON)
 *
 * El log es NO BLOQUEANTE: si falla, no interrumpe la operación principal.
 * Los detalles son útiles para auditoría:  quién, qué objeto, qué valores cambiaron.
 */
function logAction(PDO $db, ?int $userId, string $action, array $details = []): void {
    try {
        // Añadimos timestamp ISO8601 y IP en los detalles automáticamente
        $details['_ip']        = $_SERVER['REMOTE_ADDR']      ?? null;
        $details['_userAgent'] = $_SERVER['HTTP_USER_AGENT']   ?? null;
        $details['_timestamp'] = date('c');                   // ISO 8601

        $stmt = $db->prepare(
            'INSERT INTO audit_log (action, details, user_id, created_at)
             VALUES (:action, :details, :user_id, NOW())'
        );
        $stmt->execute([
            ':action'  => strtoupper(substr($action, 0, 100)),
            ':details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            ':user_id' => $userId,
        ]);
    } catch (Exception $e) {
        // El fallo de auditoría no debe romper la operación principal.
        // Loguear en el error_log del servidor para revisión técnica.
        error_log('[AUDIT LOG FAILED] action=' . $action . ' error=' . $e->getMessage());
    }
}
