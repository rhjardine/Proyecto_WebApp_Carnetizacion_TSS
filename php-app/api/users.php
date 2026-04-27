<?php
/**
 * api/users.php — Gestión de Usuarios SCI-TSS
 * ===========================================================
 * VERSIÓN DEFINITIVA Y SINCRONIZADA:
 * - Se corrigen las referencias a 'nombre' en tablas roles/permisos.
 * - FIX 400: Tolerancia de payload para cambio de contraseña (password vs new_password).
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/middleware/RBAC.php';
require_once __DIR__ . '/middleware/auth_check.php';

if (!function_exists('sendResponse')) {
    function sendResponse($success, $message, $data = null, $code = 200)
    {
        http_response_code($code);
        $res = ['success' => $success, 'message' => $message];
        if ($data !== null) {
            if (is_array($data) && isset($data['data']))
                $res = array_merge($res, $data);
            else
                $res['data'] = $data;
        }
        echo json_encode($res);
        exit;
    }
}

if (!function_exists('logAction')) {
    function logAction($db, $userId, $action, $details = [])
    {
        Security::logAudit($db, $userId, $action, 'usuarios', null, null, $details);
    }
}

$db = getDB();
Security::requirePermission($db, 'user.manage');

$method = strtoupper($_SERVER['REQUEST_METHOD']);
$userIdEf = $authUser['id'] ?? $_SESSION['user_id'] ?? 1;
$rolEf = $authUser['rol_efectivo'] ?? $_SESSION['role'] ?? 'USUARIO';

try {
    if ($method === 'GET') {
        if (!in_array($rolEf, ['ADMIN', 'COORD']))
            sendResponse(false, 'Acceso denegado.', null, 403);

        $stmt = $db->query("
            SELECT u.id, u.usuario, u.nombre_completo, u.bloqueado, u.intentos_fallidos, u.creado_el, u.actualizado_el,
                   r.nombre AS rol,
                   (SELECT GROUP_CONCAT(p.nombre SEPARATOR ', ') 
                    FROM permisos_temporales pt 
                    JOIN permisos p ON pt.permiso_id = p.id 
                    WHERE pt.usuario_id = u.id AND pt.expira_el > NOW()) AS permisos_temporales_activos
            FROM usuarios u
            LEFT JOIN usuario_rol ur ON u.id = ur.usuario_id
            LEFT JOIN roles r ON ur.rol_id = r.id
            ORDER BY FIELD(r.nombre, 'ADMIN', 'COORD', 'ANALISTA', 'USUARIO', 'CONSULTA'), u.usuario
        ");

        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = array_map(function ($u) {
            return [
                'id' => (int) $u['id'],
                'username' => $u['usuario'],
                'full_name' => $u['nombre_completo'],
                'role' => $u['rol'] ?? 'USUARIO',
                'temporary_role' => !empty($u['permisos_temporales_activos']) ? 'CON_PERMISOS_SUDO' : null,
                'is_locked' => (bool) $u['bloqueado'],
                'failed_attempts' => (int) $u['intentos_fallidos'],
                'created_at' => $u['creado_el'],
                'updated_at' => $u['actualizado_el'],
            ];
        }, $usuarios);

        sendResponse(true, 'Usuarios obtenidos.', $data);
    }

    if ($method === 'DELETE') {
        if ($rolEf !== 'ADMIN')
            sendResponse(false, 'Solo ADMIN puede eliminar.', null, 403);
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0 || $id === $userIdEf)
            sendResponse(false, 'ID inválido o auto-eliminación no permitida.', null, 400);

        $stmt = $db->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0)
            sendResponse(false, 'Usuario no encontrado.', null, 404);

        logAction($db, $userIdEf, 'USUARIO_ELIMINADO', ['usuario_id' => $id]);
        sendResponse(true, 'Usuario eliminado correctamente.');
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? '';

        if ($action === 'create') {
            $newUser = trim($body['username'] ?? '');
            $newPass = $body['password'] ?? '';
            $newName = trim($body['full_name'] ?? '');
            $newRole = strtoupper(trim($body['role'] ?? 'USUARIO'));

            if (!$newUser || !$newPass || !$newName)
                sendResponse(false, 'Datos incompletos.', null, 400);
            if (strlen($newPass) < 6)
                sendResponse(false, 'Contraseña muy corta.', null, 400);

            $check = $db->prepare("SELECT id FROM usuarios WHERE usuario = ?");
            $check->execute([$newUser]);
            if ($check->fetch())
                sendResponse(false, "El usuario ya existe.", null, 409);

            $rStmt = $db->prepare("SELECT id FROM roles WHERE nombre = ? LIMIT 1");
            $rStmt->execute([$newRole]);
            $roleId = $rStmt->fetchColumn() ?: 4;

            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $db->beginTransaction();
            $db->prepare("INSERT INTO usuarios (usuario, clave_hash, nombre_completo, bloqueado, intentos_fallidos, requiere_cambio_clave) VALUES (?, ?, ?, 0, 0, 1)")->execute([$newUser, $hash, $newName]);
            $newId = $db->lastInsertId();
            $db->prepare("INSERT INTO usuario_rol (usuario_id, rol_id) VALUES (?, ?)")->execute([$newId, $roleId]);
            $db->commit();

            logAction($db, $userIdEf, 'USUARIO_CREADO', ['nuevo_usuario' => $newUser, 'rol' => $newRole]);
            sendResponse(true, "Usuario creado.", ['id' => (int) $newId]);
        }

        if ($action === 'edit') {
            $id = intval($body['id'] ?? 0);
            $newName = trim($body['full_name'] ?? '');
            $newRole = strtoupper(trim($body['role'] ?? ''));
            if ($id <= 0)
                sendResponse(false, 'ID inválido.', null, 400);

            $db->beginTransaction();
            if ($newName)
                $db->prepare("UPDATE usuarios SET nombre_completo = ?, actualizado_el = NOW() WHERE id = ?")->execute([$newName, $id]);

            if ($newRole) {
                $rStmt = $db->prepare("SELECT id FROM roles WHERE nombre = ? LIMIT 1");
                $rStmt->execute([$newRole]);
                $roleId = $rStmt->fetchColumn();
                if ($roleId) {
                    $db->prepare("DELETE FROM usuario_rol WHERE usuario_id = ?")->execute([$id]);
                    $db->prepare("INSERT INTO usuario_rol (usuario_id, rol_id) VALUES (?, ?)")->execute([$id, $roleId]);
                }
            }
            $db->commit();
            logAction($db, $userIdEf, 'USUARIO_EDITADO', ['usuario_id' => $id, 'cambios' => $body]);
            sendResponse(true, 'Usuario actualizado.');
        }

        // FIX CRÍTICO: Manejo tolerante del payload para contraseñas
        if ($action === 'change_password') {
            $id = intval($body['id'] ?? 0);
            // Aceptamos 'new_password' o 'password' indistintamente
            $newPass = $body['new_password'] ?? $body['password'] ?? '';

            if ($id <= 0 || empty($newPass)) {
                sendResponse(false, 'ID y contraseña requeridos.', null, 400);
            }
            if (strlen($newPass) < 6) {
                sendResponse(false, 'La contraseña debe tener al menos 6 caracteres.', null, 400);
            }
            if ($id !== $userIdEf && !in_array($rolEf, ['ADMIN', 'COORD'])) {
                sendResponse(false, 'Acceso denegado.', null, 403);
            }

            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $db->prepare("UPDATE usuarios SET clave_hash = ?, requiere_cambio_clave = 0, clave_ultima_rotacion = CURRENT_DATE, actualizado_el = NOW() WHERE id = ?")->execute([$hash, $id]);
            logAction($db, $userIdEf, 'CONTRASENA_CAMBIADA', ['usuario_id' => $id]);
            sendResponse(true, 'Contraseña actualizada con éxito.');
        }

        if ($action === 'delegate') {
            if (!in_array($rolEf, ['ADMIN', 'COORD']))
                sendResponse(false, 'Acceso denegado.', null, 403);
            $targetUser = trim($body['username'] ?? '');
            $tempRole = strtoupper(trim($body['tempRole'] ?? ''));

            $uStmt = $db->prepare("SELECT id FROM usuarios WHERE usuario = ? LIMIT 1");
            $uStmt->execute([$targetUser]);
            $targetUserId = $uStmt->fetchColumn();
            if (!$targetUserId)
                sendResponse(false, 'Usuario no encontrado.', null, 404);

            $expiresIn = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $db->prepare("DELETE FROM permisos_temporales WHERE usuario_id = ?")->execute([$targetUserId]);

            $pStmt = $db->prepare("SELECT rp.permiso_id FROM rol_permiso rp JOIN roles r ON rp.rol_id = r.id WHERE r.nombre = ?");
            $pStmt->execute([$tempRole]);
            $permisosDelRol = $pStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($permisosDelRol)) {
                $ins = $db->prepare("INSERT INTO permisos_temporales (usuario_id, permiso_id, asignado_por, expira_el) VALUES (?, ?, ?, ?)");
                foreach ($permisosDelRol as $pId)
                    $ins->execute([$targetUserId, $pId, $userIdEf, $expiresIn]);
            }
            logAction($db, $userIdEf, 'ROL_DELEGADO_SUDO', ['usuario_destino' => $targetUser, 'rol_temporal' => $tempRole]);
            sendResponse(true, "Permisos asignados.");
        }

        if ($action === 'revoke') {
            if (!in_array($rolEf, ['ADMIN', 'COORD']))
                sendResponse(false, 'Acceso denegado.', null, 403);
            $targetUser = trim($body['username'] ?? '');

            $uStmt = $db->prepare("SELECT id FROM usuarios WHERE usuario = ? LIMIT 1");
            $uStmt->execute([$targetUser]);
            $targetUserId = $uStmt->fetchColumn();

            if ($targetUserId)
                $db->prepare("DELETE FROM permisos_temporales WHERE usuario_id = ?")->execute([$targetUserId]);
            logAction($db, $userIdEf, 'SUDO_REVOCADO', ['usuario_destino' => $targetUser]);
            sendResponse(true, "Permisos revocados.");
        }

        if ($action === 'unlock') {
            if (!in_array($rolEf, ['ADMIN', 'COORD']))
                sendResponse(false, 'Acceso denegado.', null, 403);
            $id = intval($body['id'] ?? 0);
            if ($id <= 0)
                sendResponse(false, 'ID inválido.', null, 400);

            $db->prepare("UPDATE usuarios SET bloqueado = 0, intentos_fallidos = 0, actualizado_el = NOW() WHERE id = ?")->execute([$id]);
            logAction($db, $userIdEf, 'CUENTA_DESBLOQUEADA', ['usuario_id' => $id]);
            sendResponse(true, 'Cuenta desbloqueada.');
        }

        sendResponse(false, "Acción no reconocida.", null, 400);
    }
} catch (Exception $e) {
    if ($db->inTransaction())
        $db->rollBack();
    error_log('[SCI-TSS users.php] ' . $e->getMessage());
    sendResponse(false, 'Error interno: ' . $e->getMessage(), null, 500);
}