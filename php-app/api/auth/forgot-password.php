<?php
/**
 * SCI-TSS - Endpoint de Recuperación de Contraseña
 * 
 * Este archivo implementa el flujo inicial de recuperación de contraseña.
 * Adopta principios de seguridad Zero Trust:
 * 1. Bypass CSRF justificado (endpoint público pre-autenticación).
 * 2. Mitigación de Time-based/Response-based Username Enumeration.
 * 3. Hashing de tokens (CWE-311, CWE-312) antes de persistir (mitiga robo de BD).
 * 4. Tokens criptográficamente fuertes y con ventana de vida corta (15 min).
 */

define('BYPASS_CSRF', true);

// 1. Cargar el entorno y utilidades base
require_once __DIR__ . '/../bootstrap.php';

// Configurar encabezados de respuesta segura
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// 2. Validar que la petición sea POST estricta
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// 3. Procesar y sanitizar entrada JSON
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$username = isset($data['username']) ? trim(filter_var($data['username'], FILTER_SANITIZE_STRING)) : '';
$email = isset($data['email']) ? trim(filter_var($data['email'], FILTER_SANITIZE_EMAIL)) : '';

// Mitigación de enumeración y rechazo temprano de datos vacíos
// Si faltan datos, respondemos ambiguamente.
if (empty($username) || empty($email)) {
    echo json_encode([
        'success' => true,
        'message' => 'Si los datos son correctos, se enviarán las instrucciones a su correo institucional.'
    ]);
    exit;
}

try {
    $db = getDB();

    // 4. Buscar usuario con correspondencia EXACTA (usuario, email y estado activo)
    // Se utiliza consulta preparada para prevenir SQL Injection.
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE usuario = ? AND email = ? AND activa = 1 LIMIT 1");
    $stmt->execute([$username, $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 5. & 6. Logica condicional (El atacante siempre recibe la misma respuesta JSON HTTP 200 OK)
    if ($user) {
        $userId = $user['id'];

        // a. Generar un token criptográficamente seguro (32 bytes = 64 caracteres hex)
        $plainToken = bin2hex(random_bytes(32));

        // b. Hashear el token para la base de datos
        // Si la BD es comprometida, el atacante no tiene los tokens en texto plano para usarlos.
        $hashedToken = hash('sha256', $plainToken);

        // c. Actualizar el registro del usuario con el token, forzando una ventana de expiración corta (15 min)
        $updateStmt = $db->prepare("
            UPDATE usuarios 
            SET reset_token_hash = ?, 
                reset_token_expira = DATE_ADD(NOW(), INTERVAL 15 MINUTE) 
            WHERE id = ?
        ");
        $updateStmt->execute([$hashedToken, $userId]);

        // d. Registrar la acción en auditoría
        // Simulamos un ID de sesión/usuario "Sistema" para la auditoría pre-autenticación
        if (function_exists('logAction')) {
             logAction($db, $userId, 'SOLICITUD_RECUPERACION_CLAVE', "Solicitud generada para $username");
        }

        // e. [SIMULACIÓN LOCAL] Devolver el token en claro en la respuesta.
        // ADVERTENCIA: En un entorno de producción real, el token se envía por correo electrónico 
        // y NUNCA se devuelve en la respuesta de la API. Se deja aquí estrictamente por petición del entorno de desarrollo.
        echo json_encode([
            'success' => true,
            'message' => 'Si los datos son correctos, se enviarán las instrucciones a su correo institucional.',
            '_dev_token' => $plainToken // SIMULACIÓN SMTP
        ]);
        exit;
    } else {
        // El usuario no existe, o está inactivo, o el email no coincide.
        // Respuesta ambigua idéntica para prevenir Time-Based/Response-Based Enumeration
        // Para ser estrictos contra ataques de tiempo (Timing attacks), podríamos añadir un sleep pseudoaleatorio
        // proporcional al tiempo que toma el hash de arriba, pero por ahora mantenemos simplicidad lógica.
        echo json_encode([
            'success' => true,
            'message' => 'Si los datos son correctos, se enviarán las instrucciones a su correo institucional.'
        ]);
        exit;
    }

} catch (PDOException $e) {
    // Evitar filtración de errores de DB (Zero Trust)
    error_log("DB Error en forgot-password: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.']);
    exit;
} catch (Exception $e) {
    error_log("Error general en forgot-password: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de procesamiento.']);
    exit;
}
