<?php
/**
 * POST /api/auth/login.php
 * Body: { "username": "admin", "password": "admin123" }
 *
 * HARDENING:
 * - Cookie flags: HttpOnly, Secure, SameSite=Strict
 * - session_regenerate_id(true) post-login → previene Session Fixation
 * - Genera CSRF token en sesión tras autenticación exitosa
 */
require_once __DIR__ . '/../../includes/db_mysql.php';

// --- Session Hardening: configurar ANTES de session_start() ---
$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

session_set_cookie_params([
    'lifetime' => 0,           // Sesión de navegador (sin expiración persistente)
    'path' => '/',
    'domain' => '',          // Dominio actual
    'secure' => $isSecure,   // true en HTTPS (producción); false en HTTP local
    'httponly' => true,        // Inaccesible desde JavaScript → protege contra XSS
    'samesite' => 'Strict',    // Bloquea envío cross-site → protege contra CSRF
]);

session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$username = trim($body['username'] ?? '');
$password = trim($body['password'] ?? '');

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Usuario y contraseña son requeridos.']);
    exit;
}

try {
    $db = getDB();

    // ── Consulta completa: incluye rol_temporal y nombre_completo ─────────
    // rol_temporal es fundamental para el cálculo del rol efectivo en el middleware.
    $stmt = $db->prepare(
        'SELECT id, username, password_hash AS password,
                rol AS role, rol_temporal, full_name AS nombre,
                bloqueado, intentos_fallidos
         FROM usuarios WHERE username = :username LIMIT 1'
    );
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Credenciales inválidas.']);
        exit;
    }

    // ── Verificar bloqueo antes de validar contraseña ─────────────────────
    if ($user['bloqueado']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Cuenta bloqueada. Contacte al administrador.']);
        exit;
    }

    if (!password_verify($password, $user['password'])) {
        // Incrementar intentos fallidos
        $newAttempts = $user['intentos_fallidos'] + 1;
        $bloquear = $newAttempts >= 3 ? 1 : 0;
        $db->prepare('UPDATE usuarios SET intentos_fallidos = ?, bloqueado = ?, actualizado_el = NOW() WHERE id = ?')
            ->execute([$newAttempts, $bloquear, $user['id']]);

        http_response_code(401);
        $restantes = max(0, 3 - $newAttempts);
        echo json_encode([
            'success' => false,
            'message' => $bloquear
                ? 'Cuenta bloqueada tras 3 intentos fallidos. Contacte al administrador.'
                : "Contraseña incorrecta. Intentos restantes: {$restantes}.",
        ]);
        exit;
    }

    // ── Login exitoso: Resetear intentos ──────────────────────────────────
    $db->prepare('UPDATE usuarios SET intentos_fallidos = 0, actualizado_el = NOW() WHERE id = ?')
        ->execute([$user['id']]);

    // ── PREVENCIÓN DE SESSION FIXATION ────────────────────────────────────
    session_regenerate_id(true);

    // ── Persisitir datos en $_SESSION (usados por auth_check.php) ─────────
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['rol_temporal'] = $user['rol_temporal'] ?: null;  // null si sin delegación
    $_SESSION['nombre'] = $user['nombre'];

    // ── CSRF Token (una sola vez por sesión) ──────────────────────────────
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // ── Calcular rol efectivo para la respuesta al frontend ───────────────
    $rolEfectivo = $user['rol_temporal'] ?: $user['role'];

    echo json_encode([
        'success' => true,
        'message' => 'Login exitoso.',
        'csrf_token' => $_SESSION['csrf_token'],
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'full_name' => $user['nombre'],
            'role' => $user['role'],
            'temporary_role' => $user['rol_temporal'],
            'effective_role' => $rolEfectivo,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.']);
}
