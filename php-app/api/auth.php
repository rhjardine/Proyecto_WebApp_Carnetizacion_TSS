<?php
/**
 * api/auth.php — Autenticación con password_verify, bloqueo, sesión PHP y RBAC
 * =============================================================================
 * SCI-TSS v2.0 — Punto de entrada principal para login desde el frontend.
 *
 * Usa la tabla `usuarios` con columnas reales del esquema migrado:
 *   username, password_hash, full_name, rol, rol_temporal, bloqueado, intentos_fallidos
 *
 * Al login exitoso, persiste en $_SESSION los datos requeridos por auth_check.php.
 */
require_once __DIR__ . '/../includes/db_mysql.php';
$pdo = getDB();

// ── CORS ──────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Sesión PHP (necesaria para auth_check.php) ────────────────────────────────
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

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    sendResponse(false, 'Método no permitido', null, 405);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    sendResponse(false, 'Credenciales incompletas', null, 400);
    exit;
}

try {
    // ── Consulta con columnas reales del esquema MySQL migrado ────────────────
    $stmt = $pdo->prepare(
        "SELECT id, username, password_hash, full_name,
                rol AS role, rol_temporal AS temporary_role,
                bloqueado AS is_locked, intentos_fallidos AS failed_attempts
         FROM usuarios WHERE username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        sendResponse(false, 'Usuario no encontrado', ['locked' => false], 401);
        exit;
    }

    // ── Verificar bloqueo ─────────────────────────────────────────────────────
    if ($user['is_locked']) {
        sendResponse(
            false,
            'Cuenta bloqueada por seguridad. Contacte al administrador.',
            ['locked' => true],
            403
        );
        exit;
    }

    // ── Validar contraseña ────────────────────────────────────────────────────
    $storedHash = $user['password_hash'];
    $isHashedBcrypt = (strlen($storedHash) >= 60 && str_starts_with($storedHash, '$2'));
    $isValid = $isHashedBcrypt
        ? password_verify($password, $storedHash)
        : ($password === $storedHash);

    if ($isValid) {
        // ── Login exitoso ─────────────────────────────────────────────────────
        $pdo->prepare("UPDATE usuarios SET intentos_fallidos = 0 WHERE id = ?")
            ->execute([$user['id']]);

        // Prevención de Session Fixation
        session_regenerate_id(true);

        // Persistir en $_SESSION (requerido por auth_check.php para RBAC)
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['rol_temporal'] = $user['temporary_role'] ?: null;
        $_SESSION['nombre'] = $user['full_name'];

        // CSRF token (una sola vez por sesión)
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // Rol efectivo: temporal tiene precedencia sobre base
        $rolEfectivo = $user['temporary_role'] ?: $user['role'];

        sendResponse(true, 'Login exitoso', [
            'id' => $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
            'temporary_role' => $user['temporary_role'],
            'effective_role' => $rolEfectivo,
            'csrf_token' => $_SESSION['csrf_token'],
        ]);

    } else {
        // ── Login fallido: incrementar intentos ───────────────────────────────
        $newAttempts = $user['failed_attempts'] + 1;
        $isLocked = ($newAttempts >= 3);

        $pdo->prepare("UPDATE usuarios SET intentos_fallidos = ?, bloqueado = ? WHERE id = ?")
            ->execute([$newAttempts, $isLocked ? 1 : 0, $user['id']]);

        if ($isLocked) {
            sendResponse(
                false,
                'Cuenta bloqueada tras 3 intentos fallidos. Contacte al administrador.',
                ['locked' => true],
                403
            );
        } else {
            $remaining = 3 - $newAttempts;
            sendResponse(
                false,
                "Contraseña incorrecta. Intentos restantes: {$remaining}",
                ['locked' => false],
                401
            );
        }
    }

} catch (Exception $e) {
    sendResponse(false, 'Error de autenticación: ' . $e->getMessage(), null, 500);
}
