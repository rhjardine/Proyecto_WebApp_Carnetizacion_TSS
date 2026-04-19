<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/RBAC.php';

header('Content-Type: application/json');

$pdo = getDB();

// Manejo de peticiones preflight CORS (Live Server)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);
$username = $data['username'] ?? $_POST['username'] ?? '';
$password = $data['password'] ?? $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Usuario y contraseña son requeridos.']);
    exit;
}

// Delegamos la validación a la clase de seguridad
$result = Security::loginUser($pdo, $username, $password);

if (isset($result['success']) && $result['success'] === true) {

    // Arquitectura Definitiva: El frontend necesita el ROL para renderizar la UI.
    // Nivel más bajo por defecto por seguridad.
    $role = 'USUARIO';

    try {
        // Única Fuente de Verdad: Consultamos exclusivamente el esquema consolidado
        $stmt = $pdo->prepare("SELECT rol FROM usuarios WHERE usuario = ? LIMIT 1");
        $stmt->execute([$username]);
        $fetchedRole = $stmt->fetchColumn();

        if ($fetchedRole) {
            $role = strtoupper($fetchedRole);
        }
    } catch (PDOException $e) {
        // En un entorno de producción limpio, esto solo fallará si se cae la BD.
        error_log("[LOGIN CRITICAL] Error obteniendo rol para $username: " . $e->getMessage());
    }

    // Retornamos el JSON completo con el Rol inyectado
    echo json_encode([
        'success' => true,
        'message' => 'Login exitoso.',
        'csrf_token' => Security::generateCsrfToken(),
        'role' => $role, // <-- ESTO DEVUELVE LA VISTA AL FRONTEND
        'requires_password_change' => $_SESSION['requires_password_change'] ?? false
    ]);

} else {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => $result['error'] ?? 'Credenciales inválidas.'
    ]);
}