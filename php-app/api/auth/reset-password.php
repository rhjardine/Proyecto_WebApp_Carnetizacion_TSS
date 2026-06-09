<?php
/**
 * SCI-TSS - Endpoint de Restablecimiento de Contraseña
 * 
 * Este archivo implementa la segunda fase del flujo de recuperación de contraseña.
 * Adopta principios de seguridad Zero Trust:
 * 1. Bypass CSRF justificado (endpoint de recuperación público pre-autenticación).
 * 2. Validación de hashes criptográficos en BD contra ataques de "Timing" (comparación implícita de PDO o hash_equals si fuese necesario).
 * 3. Rotación fuerte forzada usando PASSWORD_BCRYPT.
 * 4. Invalida tokens de un solo uso de forma atómica en una transacción.
 * 5. Requiere cambio de clave obligatorio en el próximo login (rotación normativa de Zero Trust).
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

// Procesar entrada JSON
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$token = isset($data['token']) ? trim($data['token']) : '';
$newPassword = isset($data['new_password']) ? $data['new_password'] : '';

// 3. Validaciones básicas y longitud mínima
if (empty($token) || empty($newPassword)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token y nueva contraseña son requeridos.']);
    exit;
}

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos 6 caracteres.']);
    exit;
}

try {
    $db = getDB();

    // 4. Hashear el token provisto para compararlo de forma segura con el guardado en BD
    $hashedTokenInput = hash('sha256', $token);

    // Buscar si existe el token Y además si todavía NO ha expirado
    $stmt = $db->prepare("SELECT id, usuario FROM usuarios WHERE reset_token_hash = ? AND reset_token_expira > NOW() LIMIT 1");
    $stmt->execute([$hashedTokenInput]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 5. Si el token es inválido, manipulado o expiró
    if (!$user) {
        // Podríamos registrar un intento fallido de uso de token, pero para simplicidad 
        // devolvemos 400 y evitamos dar pistas de si fue expiración o token incorrecto.
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'El token de recuperación es inválido o ha expirado.']);
        exit;
    }

    $userId = $user['id'];
    $username = $user['usuario'];

    // 6. Token es válido, procedemos a resetear la contraseña

    // a. Crear el hash BCRYPT de la nueva clave
    // NOTA: password_hash() maneja la "sal" automáticamente de manera segura.
    $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT);

    // b. Iniciar transacción para garantizar la atomicidad de la operación
    $db->beginTransaction();

    // c. Actualizar el usuario aplicando políticas de Zero Trust
    // - Se limpia el token de un solo uso.
    // - Se desbloquea el usuario en caso de haber sido bloqueado por fuerza bruta.
    // - Se resetean sus intentos.
    // - Se establece requiere_cambio_clave = 1 (obligando a rotar la clave la próxima vez).
    $updateStmt = $db->prepare("
        UPDATE usuarios 
        SET clave_hash = ?, 
            bloqueado = 0, 
            intentos_fallidos = 0, 
            requiere_cambio_clave = 1, 
            reset_token_hash = NULL, 
            reset_token_expira = NULL, 
            clave_ultima_rotacion = CURRENT_DATE 
        WHERE id = ?
    ");
    
    $updateStmt->execute([$newPasswordHash, $userId]);

    // d. Registrar la auditoría
    // Simulamos un ID de sesión "Sistema" temporal usando el propio ID del usuario 
    // como actor, o dejándolo registrado para trazabilidad.
    if (function_exists('logAction')) {
         logAction($db, $userId, 'CLAVE_RESETEADA_MEDIANTE_TOKEN', "El usuario $username restableció exitosamente su clave mediante token.");
    }

    // e. Comprometer la transacción
    $db->commit();

    // 7. Devolver respuesta JSON 200 de éxito
    echo json_encode([
        'success' => true,
        'message' => 'La contraseña ha sido restablecida correctamente. Será redirigido al inicio de sesión.'
    ]);
    exit;

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("DB Error en reset-password: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor de base de datos.']);
    exit;
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error general en reset-password: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de procesamiento.']);
    exit;
}
