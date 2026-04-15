<?php
/**
 * SCI-TSS Authentication Endpoint
 * ===============================
 * Refactored to use Security class (Middleware/RBAC.php)
 * Cumple con el estándar de Hardening y protección contra fuerza bruta.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/RBAC.php';

// Configuración de respuesta estricta JSON
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

// Lectura de inputs sanitizada
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$username = trim($body['username'] ?? '');
$password = (string) ($body['password'] ?? '');

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Credenciales incompletas.']);
    exit;
}

try {
    $pdo = getDB();

    // Delegación al núcleo de seguridad (RBAC.php)
    $result = Security::loginUser($pdo, $username, $password);

    // Ajuste de código HTTP según el resultado de seguridad
    if (!$result['success']) {
        $httpCode = isset($result['code']) ? $result['code'] : 401;
        http_response_code($httpCode);
    } else {
        http_response_code(200);
    }

    echo json_encode($result);

} catch (Exception $e) {
    // Registro de error crítico sin exponer detalles al cliente (OWASP)
    error_log('[SECURITY CRITICAL] Fallo en endpoint de login: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de seguridad interno.']);
}