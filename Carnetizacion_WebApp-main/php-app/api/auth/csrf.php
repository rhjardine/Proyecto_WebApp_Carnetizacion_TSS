<?php
/**
 * GET /api/auth/csrf.php
 * Retorna el CSRF token de la sesión activa.
 * El frontend lo llama al cargar cada página para tener el token actualizado.
 */
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

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autenticado.']);
    exit;
}

// Generar token si por alguna razón no existe aún
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

echo json_encode([
    'success'    => true,
    'csrf_token' => $_SESSION['csrf_token'],
]);
