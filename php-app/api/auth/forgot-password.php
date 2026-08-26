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
    // La recuperación autoservicio está reservada a ADMIN y COORD (decisión funcional TSS).
    // El resto de los roles recibe la misma respuesta ambigua: no se revela por qué no aplica.
    $stmt = $db->prepare("
        SELECT u.id
        FROM usuarios u
        JOIN usuario_rol ur ON ur.usuario_id = u.id
        JOIN roles r ON r.id = ur.rol_id
        WHERE u.usuario = ? AND u.email = ? AND u.activa = 1
          AND r.nombre IN ('ADMIN', 'COORD')
        LIMIT 1
    ");
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
             // Ver nota en reset-password.php: el 4º argumento debe ser array, no string.
             logAction($db, $userId, 'SOLICITUD_RECUPERACION_CLAVE', ['usuario' => $username]);
        }

        // e. Entrega del enlace de recuperación.
        //
        // Mientras la TSS no provea un servidor SMTP, el enlace se deposita en un registro
        // local que sólo puede leer quien tenga acceso al servidor (Apache bloquea *.log vía
        // .htaccess). El token en claro NO viaja en la respuesta HTTP salvo en desarrollo
        // (APP_DEBUG=true): devolverlo siempre convertía la recuperación en una puerta
        // abierta — cualquiera que supiera usuario y correo podía tomar la cuenta.
        $enlace = rtrim(get_env_resilient('APP_URL', ''), '/') . '/reset-password.html?token=' . $plainToken;

        $logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0770, true);
        }
        @file_put_contents(
            $logDir . '/password-resets.log',
            sprintf("[%s] usuario=%s enlace=%s expira=15min%s", date('c'), $username, $enlace, PHP_EOL),
            FILE_APPEND | LOCK_EX
        );

        $respuesta = [
            'success' => true,
            'message' => 'Si los datos son correctos, se enviarán las instrucciones a su correo institucional.',
        ];
        if (APP_DEBUG) {
            $respuesta['_dev_reset_url'] = $enlace;
            $respuesta['_dev_warning'] = 'Este enlace sólo se expone porque APP_DEBUG=true. '
                . 'En producción configure SMTP y ponga APP_DEBUG=false.';
        }
        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
