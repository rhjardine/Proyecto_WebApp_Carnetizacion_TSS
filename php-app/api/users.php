<?php
/**
 * api/users.php — CRUD completo de Usuarios SCI-TSS (MySQL)
 * ===========================================================
 * FUSIÓN DEFINITIVA: Validado con el archivo aportado por Claude.
 * Se utilizan las 6 referencias a `nombre` en las consultas para
 * empatar perfectamente con el RBAC.php estricto.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/middleware/RBAC.php';
require_once __DIR__ . '/middleware/auth_check.php';

// PUENTES DE COMPATIBILIDAD
if (!function_exists('sendResponse')) {
    function sendResponse($success, $message, $data = null, $code = 200)
    {
        http_response_code($code);
        $res = ['success' => $success, 'message' => $message];
        if ($data !== null) {
            if (is_array($data) && isset($data['data'])) {
                $res = array_merge($res, $data);
            } else {
                $res['data'] = $data;
            }
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
        if (!in_array($rolEf, ['ADMIN', 'COORD'])) {
            sendResponse(false, 'Acceso denegado. Se requiere rol ADMIN o COORD.', null, 403);
        }

        // FIX QUIRÚRGICO: r.nombre y p.nombre
        $stmt = $db->query("
            SELECT 
                u.id, u.usuario, u.nombre_completo, u.bloqueado, u.intentos_fallidos,
                u.creado_el, u.actualizado_el,
                r.nombre AS rol,
                (SELECT GROUP_CONCAT(p.nombre SEPARATOR ', ') 
                 FROM permisos_temporales pt 
                 JOIN permisos p ON pt.permiso_id = p.id 
                 WHERE pt.usuario_id = u.id AND pt.expira_en > NOW()
                ) AS permisos_temporales_activos
            FROM usuarios u
            LEFT JOIN usuario_rol ur ON u.id = ur.usuario_id
            LEFT JOIN roles r ON ur.rol_id = r.id
            ORDER BY 
                FIELD(r.nombre, 'ADMIN', 'COORD', 'ANALISTA', 'USUARIO', 'CONSULTA'), 
                u.usuario
        ");

        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = array_map(function ($u) {
            $tieneSudo = !empty($u['permisos_temporales_activos']);
            return [
                'id' => (int) $u['id'],
                'username' => $u['usuario'],
                'full_name' => $u['nombre_completo'],
                'role' => $u['rol'] ?? 'USUARIO',
                'temporary_role' => $tieneSudo ? 'CON_PERMISOS_SUDO' : null,
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
            sendResponse(false, 'Solo ADMIN puede eliminar usuarios.', null, 403);
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0)
            sendResponse(false, 'ID de usuario inválido.', null, 400);
        if ($id === $userIdEf)
            sendResponse(false, 'No puede eliminar su propia cuenta.', null, 400);

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

            if (empty($newUser) || empty($newPass) || empty($newName)) {
                sendResponse(false, 'Usuario, contraseña y nombre completo son requeridos.', null, 400);
            }
            if (strlen($newPass) < 6)
                sendResponse(false, 'La contraseña debe tener al menos 6 caracteres.', null, 400);

            $rolesValidos = ['ADMIN', 'COORD', 'ANALISTA', 'USUARIO', 'CONSULTA'];
            if (!in_array($newRole, $rolesValidos))
                sendResponse(false, 'Rol inválido.', null, 400);
            if (!preg_match('/^[a-z][a-z0-9]{2,19}$/', $newUser)) {
                sendResponse(false, 'El usuario debe comenzar con letra minúscula y contener solo letras y números.', null, 400);
            }

            $check = $db->prepare("SELECT id FROM usuarios WHERE usuario = ?");
            $check->execute([$newUser]);
            if ($check->fetch())
                sendResponse(false, "El usuario '{$newUser}' ya existe.", null, 409);

            // FIX QUIRÚRGICO: roles WHERE nombre = ?
            $rStmt = $db->prepare("SELECT id FROM roles WHERE nombre = ? LIMIT 1");
            $rStmt->execute([$newRole]);
            $roleId = $rStmt->fetchColumn() ?: 4;

            $hash = password_hash($newPass, PASSWORD_BCRYPT);

            $db->beginTransaction();
            $stmt = $db->prepare(
                "INSERT INTO usuarios (usuario, clave_hash, nombre_completo, bloqueado, intentos_fallidos, requiere_cambio_clave, clave_ultima_rotacion)
                 VALUES (?, ?, ?, 0, 0, 1, CURRENT_DATE)"
            );
            $stmt->execute([$newUser, $hash, $newName]);
            $newId = $db->lastInsertId();

            $db->prepare("INSERT INTO usuario_rol (usuario_id, rol_id) VALUES (?, ?)")->execute([$newId, $roleId]);
            $db->commit();

            logAction($db, $userIdEf, 'USUARIO_CREADO', ['nuevo_usuario' => $newUser, 'rol' => $newRole]);
            sendResponse(true, "Usuario '{$newUser}' creado exitosamente.", ['id' => (int) $newId]);
        }

        if ($action === 'edit') {
            $id = intval($body['id'] ?? 0);
            $newName = trim($body['full_name'] ?? '');
            $newRole = strtoupper(trim($body['role'] ?? ''));

            if ($id <= 0)
                sendResponse(false, 'ID de usuario inválido.', null, 400);

            $updates = [];
            $params = [];

            if (!empty($newName)) {
                $updates[] = 'nombre_completo = ?';
                $params[] = $newName;
            }

            if (empty($updates) && empty($newRole))
                sendResponse(false, 'No hay campos para actualizar.', null, 400);

            $db->beginTransaction();

            if (!empty($updates)) {
                $updates[] = 'actualizado_el = NOW()';
                $params[] = $id;
                $sql = "UPDATE usuarios SET " . implode(', ', $updates) . " WHERE id = ?";
                $db->prepare($sql)->execute($params);
            }

            if (!empty($newRole)) {
                $rolesValidos = ['ADMIN', 'COORD', 'ANALISTA', 'USUARIO', 'CONSULTA'];
                if (!in_array($newRole, $rolesValidos)) {
                    $db->rollBack();
                    sendResponse(false, 'Rol inválido.', null, 400);
                }

                // FIX QUIRÚRGICO: roles WHERE nombre = ?
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
            sendResponse(true, 'Usuario actualizado correctamente.');
        }

        if ($action === 'change_password') {
            $id = intval($body['id'] ?? 0);
            $newPass = $body['new_password'] ?? '';

            if ($id <= 0 || empty($newPass))
                sendResponse(false, 'ID y contraseña son requeridos.', null, 400);
            if (strlen($newPass) < 6)
                sendResponse(false, 'La contraseña debe tener al menos 6 caracteres.', null, 400);

            if ($id !== $userIdEf && !in_array($rolEf, ['ADMIN', 'COORD'])) {
                sendResponse(false, 'Acceso denegado.', null, 403);
            }

            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE usuarios SET clave_hash = ?, requiere_cambio_clave = 0, clave_ultima_rotacion = CURRENT_DATE, actualizado_el = NOW() WHERE id = ?");
            $stmt->execute([$hash, $id]);

            logAction($db, $userIdEf, 'CONTRASENA_CAMBIADA', ['usuario_id' => $id]);
            sendResponse(true, 'Contraseña actualizada correctamente.');
        }

        if ($action === 'delegate') {
            if (!in_array($rolEf, ['ADMIN', 'COORD']))
                sendResponse(false, 'Acceso denegado.', null, 403);

            $targetUser = trim($body['username'] ?? '');
            $tempRole = strtoupper(trim($body['tempRole'] ?? ''));

            if (empty($targetUser) || empty($tempRole))
                sendResponse(false, 'Datos incompletos.', null, 400);

            $rolesValidos = ['ADMIN', 'COORD', 'ANALISTA', 'USUARIO', 'CONSULTA'];
            if (!in_array($tempRole, $rolesValidos))
                sendResponse(false, 'Rol temporal inválido.', null, 400);

            $uStmt = $db->prepare("SELECT id FROM usuarios WHERE usuario = ? LIMIT 1");
            $uStmt->execute([$targetUser]);
            $targetUserId = $uStmt->fetchColumn();

            if (!$targetUserId)
                sendResponse(false, 'Usuario no encontrado.', null, 404);

            $expiresIn = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $db->prepare("DELETE FROM permisos_temporales WHERE usuario_id = ?")->execute([$targetUserId]);

            // FIX QUIRÚRGICO: r.nombre
            $pStmt = $db->prepare("SELECT rp.permiso_id FROM rol_permiso rp JOIN roles r ON rp.rol_id = r.id WHERE r.nombre = ?");
            $pStmt->execute([$tempRole]);
            $permisosDelRol = $pStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($permisosDelRol)) {
                $ins = $db->prepare("INSERT INTO permisos_temporales (usuario_id, permiso_id, otorgado_por, expira_en) VALUES (?, ?, ?, ?)");
                foreach ($permisosDelRol as $pId) {
                    $ins->execute([$targetUserId, $pId, $userIdEf, $expiresIn]);
                }
            }

            logAction($db, $userIdEf, 'ROL_DELEGADO_SUDO', ['usuario_destino' => $targetUser, 'rol_temporal' => $tempRole]);
            sendResponse(true, "Permisos del rol '{$tempRole}' asignados por 24 horas.");
        }

        if ($action === 'revoke') {
            if (!in_array($rolEf, ['ADMIN', 'COORD']))
                sendResponse(false, 'Acceso denegado.', null, 403);

            $targetUser = trim($body['username'] ?? '');
            if (empty($targetUser))
                sendResponse(false, 'Usuario requerido.', null, 400);

            $uStmt = $db->prepare("SELECT id FROM usuarios WHERE usuario = ? LIMIT 1");
            $uStmt->execute([$targetUser]);
            $targetUserId = $uStmt->fetchColumn();

            if ($targetUserId) {
                $db->prepare("DELETE FROM permisos_temporales WHERE usuario_id = ?")->execute([$targetUserId]);
            }

            logAction($db, $userIdEf, 'SUDO_REVOCADO', ['usuario_destino' => $targetUser]);
            sendResponse(true, "Permisos temporales revocados.");
        }

        if ($action === 'unlock') {
            if (!in_array($rolEf, ['ADMIN', 'COORD']))
                sendResponse(false, 'Acceso denegado.', null, 403);
            $id = intval($body['id'] ?? 0);
            if ($id <= 0)
                sendResponse(false, 'ID inválido.', null, 400);

            $db->prepare("UPDATE usuarios SET bloqueado = 0, intentos_fallidos = 0, actualizado_el = NOW() WHERE id = ?")->execute([$id]);
            logAction($db, $userIdEf, 'CUENTA_DESBLOQUEADA', ['usuario_id' => $id]);
            sendResponse(true, 'Cuenta desbloqueada correctamente.');
        }

        sendResponse(false, "Acción no reconocida.", null, 400);
    }

    sendResponse(false, 'Método HTTP no permitido.', null, 405);

} catch (Exception $e) {
    if ($db->inTransaction())
        $db->rollBack();
    error_log('[SCI-TSS users.php] ' . $e->getMessage());
    sendResponse(false, 'Error interno del servidor. ' . $e->getMessage(), null, 500);
}