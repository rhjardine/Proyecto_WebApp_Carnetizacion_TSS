<?php
/**
 * api/auth.php — Autenticación Principal SCI-TSS (MySQL)
 * =======================================================
 * CORRECCIÓN CRÍTICA v2.1:
 *   - Columnas corregidas al esquema MySQL en español:
 *       usuario       (no username)
 *       clave_hash    (no password_hash)
 *       nombre_completo (no full_name)
 *       rol           (no role)
 *       rol_temporal  (no temporary_role)
 *       bloqueado     (no is_locked)
 *       intentos_fallidos (no failed_attempts)
 *   - Se unifica con api/auth/login.php para evitar duplicación.
 *   - Sin credenciales hardcodeadas.
 *
 * ENDPOINT: POST api/auth.php
 * Body JSON: { "username": "admin", "password": "admin123" }
 */

require_once __DIR__ . '/config/db.php';

// ── Gestión de Sesión para Login ─────────────────────────────
$isSecure = ENFORCE_HTTPS || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Strict',
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Método no permitido.', null, 405);
}

// ── Leer y validar el cuerpo de la petición ──────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    sendResponse(false, 'Usuario y contraseña son requeridos.', null, 400);
}

try {
    $db = getDB();

    // ── Consulta con nombres de columna del esquema MySQL en español ──
    // CORRECCIÓN: columnas reales de la tabla `usuarios`:
    //   usuario, clave_hash, nombre_completo, rol, rol_temporal,
    //   bloqueado, intentos_fallidos
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

    // ── Usuario no encontrado ─────────────────────────────────
    if (!$user) {
        // Respuesta genérica para no revelar si el usuario existe
        sendResponse(false, 'Credenciales inválidas.', ['locked' => false], 401);
    }

    // ── Cuenta bloqueada ──────────────────────────────────────
    if ((int) $user['bloqueado'] === 1) {
        sendResponse(
            false,
            'Cuenta bloqueada por seguridad. Contacte al administrador del sistema.',
            ['locked' => true],
            403
        );
    }

    // ── Validar contraseña ────────────────────────────────────
    // Solo soporte para bcrypt ($2y$...) para garantizar seguridad.
    $storedHash = $user['clave_hash'];
    $esBcrypt = strlen($storedHash) >= 60 && str_starts_with($storedHash, '$2');

    if (!$esBcrypt) {
        error_log("[SCI-TSS SECURITY] Usuario {$username} tiene hash no-bcrypt en BD.");
        sendResponse(false, 'Error de seguridad en credenciales. Contacte al administrador.', null, 500);
    }

    $passwordOk = password_verify($password, $storedHash);

    if ($passwordOk) {
        // ── Login exitoso ─────────────────────────────────────

        // Resetear contador de intentos fallidos
        $db->prepare("UPDATE usuarios SET intentos_fallidos = 0 WHERE id = ?")
            ->execute([$user['id']]);

        // Prevenir Session Fixation
        session_regenerate_id(true);

        // Calcular rol efectivo (temporal tiene precedencia absoluta)
        $rolEfectivo = $user['rol_temporal'] ?: $user['rol'];

        // Persistir sesión para auth_check.php
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['usuario'];
        $_SESSION['role'] = $user['rol'];
        $_SESSION['rol_temporal'] = $user['rol_temporal'] ?: null;
        $_SESSION['nombre'] = $user['nombre_completo'];

        // Generar CSRF token una vez por sesión
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // ── Políticas de Contraseña ───────────────────────────
        $diffDays = (time() - strtotime($user['clave_ultima_rotacion'])) / 86400;
        $passwordExpired = $diffDays > PASS_ROTATION_DAYS;
        $mustChange = (int) $user['requiere_cambio_clave'] === 1 || $passwordExpired;

        sendResponse(true, 'Login exitoso.', [
            'id' => (int) $user['id'],
            'username' => $user['usuario'],
            'full_name' => $user['nombre_completo'],
            'role' => $user['rol'],
            'temporary_role' => $user['rol_temporal'],
            'effective_role' => $rolEfectivo,
            'requires_password_change' => $mustChange,
            'csrf_token' => $_SESSION['csrf_token'],
        ]);

    } else {
        // ── Contraseña incorrecta: incrementar intentos ───────
        $newAttempts = (int) $user['intentos_fallidos'] + 1;
        $bloquear = $newAttempts >= 3 ? 1 : 0;

        $db->prepare(
            "UPDATE usuarios
             SET intentos_fallidos = ?, bloqueado = ?, actualizado_el = NOW()
             WHERE id = ?"
        )->execute([$newAttempts, $bloquear, $user['id']]);

        if ($bloquear) {
            sendResponse(
                false,
                'Cuenta bloqueada tras 3 intentos fallidos. Contacte al administrador.',
                ['locked' => true],
                403
            );
        }

        $restantes = 3 - $newAttempts;
        sendResponse(
            false,
            "Contraseña incorrecta. Intentos restantes: {$restantes}.",
            ['locked' => false],
            401
        );
    }

} catch (Exception $e) {
    error_log('[SCI-TSS auth.php] ' . $e->getMessage());
    sendResponse(false, 'Error interno del servidor. Contacte al administrador.', null, 500);
}