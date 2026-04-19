<?php
/**
 * api/auth/login.php — Endpoint de Autenticación Canónico SCI-TSS
 * ================================================================
 * REMEDIACIÓN v3.0:
 *  - Respuesta unificada con estructura `data` anidada para compatibilidad
 *    con api.js: { success, message, csrf_token, data: { id, username, ... } }
 *  - Eliminada consulta redundante post-loginUser() para obtener rol
 *    (Security::loginUser ya retorna data.role desde usuario_rol JOIN roles)
 *  - Headers de seguridad añadidos
 *  - Manejo correcto de requires_password_change desde sesión PHP
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/RBAC.php';

// Headers de seguridad y contenido
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
}

// Manejo de preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

// Lectura y sanitización del cuerpo
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$username = trim($body['username'] ?? $_POST['username'] ?? '');
$password = (string) ($body['password'] ?? $_POST['password'] ?? '');

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Usuario y contraseña son requeridos.']);
    exit;
}

try {
    $pdo = getDB();

    // Delegar autenticación al motor de seguridad RBAC
    // Security::loginUser retorna: { success, message, csrf_token, data: { id, username, full_name, role, ... } }
    $result = Security::loginUser($pdo, $username, $password);

    if (!$result['success']) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Credenciales inválidas.'
        ]);
        exit;
    }

    // Login exitoso: construir respuesta completa
    // CONTRATO: api.js espera res.data con los campos del usuario
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login exitoso.',
        'csrf_token' => $result['csrf_token'] ?? ($_SESSION['csrf_token'] ?? ''),
        'data' => $result['data'] ?? [
            'id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? $username,
            'full_name' => $_SESSION['nombre'] ?? '',
            'role' => $_SESSION['role'] ?? 'USUARIO',
            'effective_role' => $_SESSION['role'] ?? 'USUARIO',
            'temporary_role' => null,
            'requires_password_change' => false,
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[SCI-TSS SECURITY] Fallo crítico en login: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de seguridad interno.']);
}
