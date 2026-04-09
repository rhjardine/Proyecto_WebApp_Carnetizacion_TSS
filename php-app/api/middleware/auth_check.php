<?php
/**
 * auth_check.php — Middleware: Autenticación + RBAC (Zero Trust) [SCI-TSS]
 * ==========================================================================
 * Incluir al INICIO de cualquier endpoint PHP protegido:
 *   require_once __DIR__ . '/../middleware/auth_check.php';
 *
 * GARANTÍAS:
 *  1. Sesión activa         → HTTP 401 si no autenticado.
 *  2. CSRF token válido     → HTTP 403 en métodos mutantes sin token.
 *  3. RBAC por rol efectivo → HTTP 403 si CONSULTA intenta escritura.
 *  4. rol_temporal tiene PRECEDENCIA sobre rol permanente (Zero Trust).
 *
 * EXPORTA $authUser[] con:
 *   id, username, rol, rol_temporal, rol_efectivo, nombre
 *
 * USO EN CONTROLADORES:
 *   if ($authUser['rol_efectivo'] !== 'ADMIN') { ... bloquear ... }
 */
require_once __DIR__ . '/../../includes/db_mysql.php';

// ── Session Hardening ────────────────────────────────────────────────────────
$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');

// ── 1. VERIFICAR SESIÓN ACTIVA ────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado. Por favor inicie sesión.']);
    exit;
}

// ── 2. CALCULAR ROL EFECTIVO (Zero Trust) ────────────────────────────────────
// Jerarquía: ADMIN > COORD > ANALISTA > USUARIO > CONSULTA
// Si hay un rol_temporal activo (delegado por un ADMIN), este tiene
// PRECEDENCIA ABSOLUTA sobre el rol base permanente.
$rolBase = $_SESSION['role'] ?? 'CONSULTA';
$rolTemporal = $_SESSION['rol_temporal'] ?? null;
$rolEfectivo = $rolTemporal ?: $rolBase;   // Temporal gana si existe

$method = strtoupper($_SERVER['REQUEST_METHOD']);
$metodosMutantes = ['POST', 'PATCH', 'PUT', 'DELETE'];
$esMutante = in_array($method, $metodosMutantes, true);

// ── 3. RBAC: BLOQUEAR ROL CONSULTA EN ESCRITURA (HTTP 403) ───────────────────
// El rol CONSULTA es estrictamente Solo Lectura. Cualquier intento de
// operación de escritura (POST/PUT/PATCH/DELETE) se rechaza aquí,
// independientemente del endpoint destino. Este es el control Double-Lock:
// capa Backend del modelo Zero Trust.
if ($esMutante && $rolEfectivo === 'CONSULTA') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado. El rol CONSULTA no tiene permisos de escritura.',
        'rol_efectivo' => $rolEfectivo,
    ]);
    exit;
}

// ── 4. VALIDACIÓN CSRF ────────────────────────────────────────────────────────
// Solo en métodos mutantes. GET es idempotente y no requiere CSRF.
// El token llega en el header HTTP: X-CSRF-Token (enviado por api.js).
if ($esMutante) {
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (empty($headerToken) || empty($sessionToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF token ausente.']);
        exit;
    }
    // hash_equals() previene timing attacks al comparar strings de longitud igual
    if (!hash_equals($sessionToken, $headerToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF token inválido.']);
        exit;
    }
}

// ── 5. CONTEXTO DE USUARIO AUTENTICADO ────────────────────────────────────────
// Disponible como $authUser en todos los controladores que incluyan este archivo.
// REGLA: Siempre evaluar $authUser['rol_efectivo'], nunca $_SESSION['role'] directamente.
$authUser = [
    'id' => (int) $_SESSION['user_id'],
    'username' => $_SESSION['username'] ?? '',
    'nombre' => $_SESSION['nombre'] ?? '',
    'rol' => $rolBase,
    'rol_temporal' => $rolTemporal,
    'rol_efectivo' => $rolEfectivo,   // ← usar SIEMPRE este para permisos
];
