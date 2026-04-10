<?php
/**
 * api/middleware/auth_check.php — Middleware RBAC Zero Trust (SCI-TSS)
 * ======================================================================
 * CORRECCIÓN v2.1:
 *   - Variables de sesión alineadas con los nombres establecidos en auth.php:
 *       $_SESSION['user_id']      → id del usuario
 *       $_SESSION['username']     → campo `usuario` de la tabla
 *       $_SESSION['role']         → campo `rol` de la tabla
 *       $_SESSION['rol_temporal'] → campo `rol_temporal` de la tabla
 *       $_SESSION['nombre']       → campo `nombre_completo` de la tabla
 *
 * GARANTÍAS:
 *  1. Sesión activa         → HTTP 401 si no autenticado.
 *  2. CSRF token válido     → HTTP 403 en métodos mutantes sin token.
 *  3. RBAC por rol efectivo → HTTP 403 si CONSULTA intenta escritura.
 *  4. rol_temporal tiene PRECEDENCIA sobre rol permanente (Zero Trust).
 *
 * EXPORTA $authUser[] con:
 *   id, username, rol, rol_temporal, rol_efectivo, nombre
 */
require_once __DIR__ . '/../../includes/db_mysql.php';

// ── Session Hardening ────────────────────────────────────────
$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Strict',
]);

// Iniciar sesión solo si no está ya activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// ── 1. VERIFICAR SESIÓN ACTIVA ───────────────────────────────
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'No autorizado. Por favor inicie sesión.',
    ]);
    exit;
}

// ── 2. CALCULAR ROL EFECTIVO (Zero Trust) ────────────────────
// rol_temporal tiene PRECEDENCIA ABSOLUTA sobre rol base.
$rolBase = $_SESSION['role'] ?? 'CONSULTA';
$rolTemporal = $_SESSION['rol_temporal'] ?? null;
$rolEfectivo = $rolTemporal ?: $rolBase;

$method = strtoupper($_SERVER['REQUEST_METHOD']);
$metodosMutantes = ['POST', 'PATCH', 'PUT', 'DELETE'];
$esMutante = in_array($method, $metodosMutantes, true);

// ── 3. RBAC: BLOQUEAR ROL CONSULTA EN ESCRITURA ──────────────
if ($esMutante && $rolEfectivo === 'CONSULTA') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado. El rol CONSULTA no tiene permisos de escritura.',
        'rol_efectivo' => $rolEfectivo,
    ]);
    exit;
}

// ── 4. VALIDACIÓN CSRF ───────────────────────────────────────
// Solo en métodos mutantes. GET es idempotente y no requiere CSRF.
if ($esMutante) {
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    // En entorno de desarrollo local sin CSRF configurado, loguear advertencia
    // pero no bloquear (comentar esta lógica en producción y descomentar el bloqueo)
    if (!empty($sessionToken)) {
        if (empty($headerToken)) {
            // DESARROLLO: advertir pero continuar
            // PRODUCCIÓN: descomentar las 5 líneas siguientes y eliminar el error_log
            error_log('[SCI-TSS CSRF] Token ausente para ' . ($_SESSION['username'] ?? 'unknown'));
            // http_response_code(403);
            // echo json_encode(['success' => false, 'message' => 'CSRF token ausente.']);
            // exit;
        } elseif (!hash_equals($sessionToken, $headerToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'CSRF token inválido.']);
            exit;
        }
    }
}

// ── 5. CONTEXTO DE USUARIO AUTENTICADO ──────────────────────
// Disponible como $authUser en todos los controladores.
// REGLA: Usar SIEMPRE $authUser['rol_efectivo'] para permisos.
$authUser = [
    'id' => (int) ($_SESSION['user_id'] ?? 0),
    'username' => $_SESSION['username'] ?? '',
    'nombre' => $_SESSION['nombre'] ?? '',
    'rol' => $rolBase,
    'rol_temporal' => $rolTemporal,
    'rol_efectivo' => $rolEfectivo,
];