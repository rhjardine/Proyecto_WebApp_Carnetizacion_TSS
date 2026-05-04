<?php
/**
 * bootstrap.php — SCI-TSS Unified Gateway
 * =========================================
 * Incluir al inicio de todos los endpoints de la API.
 * Provee: constantes, conexión PDO, CORS, sesión segura, validación CSRF global
 *         y funciones sendResponse + logAction.
 */

// ── 1. Entorno y constantes ──────────────────────────
require_once __DIR__ . '/config/config_fixed.php';

// ── 2. Conexión PDO, CORS y helpers (db.php ya incluye cors_fixed.php) ─
require_once __DIR__ . '/config/db.php';

// ── 3. Clase de seguridad RBAC ────────────────────────
require_once __DIR__ . '/middleware/RBAC.php';

// ── 4. Sesión segura ──────────────────────────────────
Security::startSecureSession();

// ── 5. Funciones auxiliares (garantizadas) ─────────────
if (!function_exists('sendResponse')) {
    function sendResponse($success, $message = '', $data = null, $code = 200)
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        $res = ['success' => $success, 'message' => $message];
        if ($data !== null) {
            if (is_array($data) && (isset($data['data']) || isset($data['meta']))) {
                $res = array_merge($res, $data);
            } else {
                $res['data'] = $data;
            }
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('logAction')) {
    function logAction(PDO $db, ?int $userId, string $accion, array $detalles = []): void
    {
        Security::logAudit($db, $userId, $accion, null, null, null, $detalles);
    }
}

// ── 6. Protección CSRF (excepto login/logout/csrf) ──────
if (!defined('BYPASS_CSRF')) {
    if (isset($_SERVER['REQUEST_METHOD']) && in_array(strtoupper($_SERVER['REQUEST_METHOD']), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
        if (empty($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
            sendResponse(false, 'Token CSRF inválido o ausente.', null, 403);
        }
    }
}