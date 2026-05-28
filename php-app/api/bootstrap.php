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

// ── 5. Funciones auxiliares centralizadas en db.php ─────────────
// sendResponse() y logAction() ya están definidas en api/config/db.php.
// No se re-declaran aquí para evitar conflictos de función duplicada.

// ── 6. Protección CSRF (excepto login/logout/csrf) ──────
if (!defined('BYPASS_CSRF')) {
    if (isset($_SERVER['REQUEST_METHOD']) && in_array(strtoupper($_SERVER['REQUEST_METHOD']), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
        if (empty($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
            sendResponse(false, 'Token CSRF inválido o ausente.', null, 403);
        }
    }
}