<?php
/**
 * api/auth.php — Autenticación con password_verify, bloqueo y CORS
 */
require_once __DIR__ . '/../includes/db.php';

// ── CORS headers (needed for php -S local dev server) ────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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
    // 1. Buscar usuario
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        sendResponse(false, 'Usuario no encontrado', ['attempts' => 0, 'locked' => false], 401);
        exit;
    }

    // 2. Verificar bloqueo
    if ($user['is_locked']) {
        sendResponse(false, 'Cuenta bloqueada por seguridad. Contacte al administrador.', ['locked' => true], 403);
        exit;
    }

    // 3. Validar password usando password_verify() (bcrypt compatible)
    // Si el hash almacenado es texto plano (migración), comparar directo también
    $storedHash = $user['password_hash'];
    $isHashedBcrypt = (strlen($storedHash) >= 60 && str_starts_with($storedHash, '$2'));
    $isValid = $isHashedBcrypt
        ? password_verify($password, $storedHash)
        : ($password === $storedHash);

    if ($isValid) {
        // Éxito: Resetear intentos fallidos
        $pdo->prepare("UPDATE users SET failed_attempts = 0, updated_at = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        // No devolver campos sensibles
        unset($user['password_hash']);

        // Calcular rol efectivo
        $user['effective_role'] = $user['temporary_role'] ?: $user['role'];

        sendResponse(true, 'Login exitoso', $user);
    } else {
        // Fallo: Incrementar intentos
        $newAttempts = $user['failed_attempts'] + 1;
        $isLocked = ($newAttempts >= 3);

        $pdo->prepare("UPDATE users SET failed_attempts = ?, is_locked = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$newAttempts, $isLocked ? 'true' : 'false', $user['id']]);

        if ($isLocked) {
            sendResponse(false, 'Cuenta bloqueada tras 3 intentos fallidos. Contacte al administrador.', ['attempts' => $newAttempts, 'locked' => true], 403);
        } else {
            $remaining = 3 - $newAttempts;
            sendResponse(false, "Contraseña incorrecta. Intentos restantes: {$remaining}", ['attempts' => $newAttempts, 'locked' => false], 401);
        }
    }

} catch (Exception $e) {
    sendResponse(false, 'Error de autenticación: ' . $e->getMessage(), null, 500);
}
