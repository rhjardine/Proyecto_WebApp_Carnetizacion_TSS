<?php
/**
 * Auth Guard Middleware
 * Incluir al INICIO de cualquier endpoint PHP protegido.
 *
 * HARDENING:
 * - Cookie flags: HttpOnly, Secure, SameSite=Strict (consistente con login.php)
 * - Validación de token CSRF en métodos de escritura (POST/PATCH/PUT/DELETE)
 * - El token se espera en el header HTTP: X-CSRF-Token
 */
require_once __DIR__ . '/../config/db.php';

// --- Session Hardening: misma configuración que login.php ---
$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isSecure,
    'httponly' => true,
    'samesite' => 'Strict',
]);

session_start();

header('Content-Type: application/json; charset=utf-8');

// --- VERIFICAR SESIÓN ACTIVA ---
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado. Por favor inicie sesión.']);
    exit;
}

// --- VALIDACIÓN CSRF ---
// Solo para métodos que mutan estado (POST, PATCH, PUT, DELETE).
// GET es seguro por naturaleza (idempotente, sin efectos secundarios).
$method = strtoupper($_SERVER['REQUEST_METHOD']);
$mutatingMethods = ['POST', 'PATCH', 'PUT', 'DELETE'];

if (in_array($method, $mutatingMethods, true)) {
    // El token llega en el header HTTP: X-CSRF-Token
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (empty($headerToken) || empty($_SESSION['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF token ausente o inválido.']);
        exit;
    }

    // hash_equals() previene timing attacks al comparar strings
    if (!hash_equals($_SESSION['csrf_token'], $headerToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF token no coincide.']);
        exit;
    }
}

// Usuario autenticado disponible para el endpoint
$authUser = [
    'id'       => $_SESSION['user_id'],
    'username' => $_SESSION['username'],
    'role'     => $_SESSION['role'],
];
