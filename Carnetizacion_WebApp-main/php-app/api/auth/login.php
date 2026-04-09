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
require_once __DIR__ . '/../config/db.php';

// --- Session Hardening: configurar ANTES de session_start() ---
$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

session_set_cookie_params([
    'lifetime' => 0,           // Sesión de navegador (sin expiración persistente)
    'path'     => '/',
    'domain'   => '',          // Dominio actual
    'secure'   => $isSecure,   // true en HTTPS (producción); false en HTTP local
    'httponly' => true,        // Inaccesible desde JavaScript → protege contra XSS
    'samesite' => 'Strict',    // Bloquea envío cross-site → protege contra CSRF
]);

session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true);
$username = trim($body['username'] ?? '');
$password = trim($body['password'] ?? '');

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Usuario y contraseña son requeridos.']);
    exit;
}

try {
    $db   = getDB();
    $stmt = $db->prepare('SELECT id, username, password, role FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Credenciales inválidas.']);
        exit;
    }

    // --- PREVENCIÓN DE SESSION FIXATION ---
    // Regenerar el ID de sesión ANTES de escribir datos de usuario.
    // El parámetro `true` elimina el archivo de sesión anterior del servidor.
    session_regenerate_id(true);

    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];

    // --- GENERACIÓN DE TOKEN CSRF ---
    // Se crea UNA sola vez por sesión. Persiste hasta el logout.
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // 64 caracteres hex
    }

    echo json_encode([
        'success'    => true,
        'message'    => 'Login exitoso.',
        'csrf_token' => $_SESSION['csrf_token'],  // El frontend lo almacena en memoria
        'user' => [
            'id'       => $user['id'],
            'username' => $user['username'],
            'role'     => $user['role'],
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.']);
}
