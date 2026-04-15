<?php
/**
 * api/auth/force-password-change.php
 * Endpoint para que los usuarios roten su contraseña cuando ha expirado
 * o el sistema se los requiere.
 */

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../config/db.php';

$isSecure = ENFORCE_HTTPS || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$currentPass = $body['current_password'] ?? '';
$newPass = $body['new_password'] ?? '';

if (empty($currentPass) || empty($newPass)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Contraseña actual y nueva contraseña son requeridas.']);
    exit;
}

if (strlen($newPass) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'La nueva contraseña debe tener al menos 6 caracteres.']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT clave_hash FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPass, $user['clave_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'La contraseña actual es incorrecta.']);
        exit;
    }

    $hash = password_hash($newPass, PASSWORD_BCRYPT);
    $updateStmt = $db->prepare(
        "UPDATE usuarios 
         SET clave_hash = ?, requiere_cambio_clave = 0, clave_ultima_rotacion = CURRENT_DATE, actualizado_el = NOW() 
         WHERE id = ?"
    );
    $updateStmt->execute([$hash, $_SESSION['user_id']]);

    // Opcional: registrar en log la acción de rotación de clave
    $logStmt = $db->prepare("INSERT INTO auditoria_logs (usuario_id, accion, detalles, direccion_ip, agente_usuario) VALUES (?, 'CONTRASENA_ROTADA', '{}', ?, ?)");
    $logStmt->execute([
        $_SESSION['user_id'],
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);

    echo json_encode(['success' => true, 'message' => 'Contraseña rotada exitosamente. Por favor asigne su nueva contraseña la próxima vez.']);

} catch (Exception $e) {
    error_log('[SCI-TSS force-password-change] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.']);
}
