<?php
/**
 * api/auth/login.php — Endpoint de Login SCI-TSS (MySQL)
 * =======================================================
 * CORRECCIÓN v2.1: Columnas alineadas al esquema MySQL en español.
 * Redirige internamente a api/auth.php para evitar duplicación de lógica.
 *
 * Este archivo se mantiene por compatibilidad con rutas legacy.
 * La lógica real está en api/auth.php.
 */
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../config/db.php';

// ── Configuración de sesión segura ───────────────────────────
$isSecure = ENFORCE_HTTPS || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Strict',
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

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$username = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Usuario y contraseña son requeridos.']);
    exit;
}

try {
    $db = getDB();

    // CORRECCIÓN CRÍTICA: columnas reales del esquema MySQL en español
    $stmt = $db->prepare(
        "SELECT
            id,
            usuario,
            clave_hash,
            nombre_completo,
            rol,
            rol_temporal,
            bloqueado,
            intentos_fallidos,
            requiere_cambio_clave,
            clave_ultima_rotacion
         FROM usuarios
         WHERE usuario = ?
         LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Credenciales inválidas.']);
        exit;
    }

    if ((int) $user['bloqueado'] === 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Cuenta bloqueada. Contacte al administrador.',
        ]);
        exit;
    }

    $storedHash = $user['clave_hash'];
    $esBcrypt = strlen($storedHash) >= 60 && str_starts_with($storedHash, '$2');

    if (!$esBcrypt) {
        error_log("[SCI-TSS SECURITY] Usuario {$username} tiene hash no-bcrypt en BD.");
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error de seguridad en credenciales.']);
        exit;
    }

    $passwordOk = password_verify($password, $storedHash);

    if (!$passwordOk) {
        $newAttempts = (int) $user['intentos_fallidos'] + 1;
        $bloquear = $newAttempts >= 3 ? 1 : 0;
        $db->prepare(
            "UPDATE usuarios SET intentos_fallidos = ?, bloqueado = ?, actualizado_el = NOW() WHERE id = ?"
        )->execute([$newAttempts, $bloquear, $user['id']]);

        http_response_code(401);
        $msg = $bloquear
            ? 'Cuenta bloqueada tras 3 intentos fallidos. Contacte al administrador.'
            : 'Contraseña incorrecta. Intentos restantes: ' . (3 - $newAttempts) . '.';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    // Login exitoso
    $db->prepare("UPDATE usuarios SET intentos_fallidos = 0, actualizado_el = NOW() WHERE id = ?")
        ->execute([$user['id']]);

    session_regenerate_id(true);

    $rolEfectivo = $user['rol_temporal'] ?: $user['rol'];

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['usuario'];
    $_SESSION['role'] = $user['rol'];
    $_SESSION['rol_temporal'] = $user['rol_temporal'] ?: null;
    $_SESSION['nombre'] = $user['nombre_completo'];

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $diffDays = (time() - strtotime($user['clave_ultima_rotacion'])) / 86400;
    $passwordExpired = $diffDays > PASS_ROTATION_DAYS;
    $mustChange = (int) $user['requiere_cambio_clave'] === 1 || $passwordExpired;

    echo json_encode([
        'success' => true,
        'message' => 'Login exitoso.',
        'csrf_token' => $_SESSION['csrf_token'],
        'data' => [
            'id' => (int) $user['id'],
            'username' => $user['usuario'],
            'full_name' => $user['nombre_completo'],
            'role' => $user['rol'],
            'temporary_role' => $user['rol_temporal'],
            'effective_role' => $rolEfectivo,
            'requires_password_change' => $mustChange
        ],
    ]);

} catch (Exception $e) {
    error_log('[SCI-TSS login.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.']);
}