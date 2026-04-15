<?php
/**
 * api/users.php — CRUD completo de Usuarios SCI-TSS (MySQL)
 * ===========================================================
 * ENDPOINT:
 *   GET    api/users.php          → Listar usuarios (ADMIN/COORD)
 *   POST   api/users.php          → Acciones: create, edit, change_password,
 *                                              delegate, revoke, unlock, delete
 *
 * SEGURIDAD:
 *   - Requiere sesión activa (auth_check.php)
 *   - Solo ADMIN puede crear/eliminar/cambiar roles
 *   - COORD puede delegar roles temporales
 *   - Contraseñas se almacenan con password_hash(PASSWORD_BCRYPT)
 *
 * @version 2.2.0
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/middleware/auth_check.php';

$method = strtoupper($_SERVER['REQUEST_METHOD']);

try {
    $db = getDB();

    // ═══════════════════════════════════════════════════════════
    // GET — Listar todos los usuarios
    // ═══════════════════════════════════════════════════════════
    if ($method === 'GET') {
        // Solo ADMIN y COORD pueden ver usuarios
        if (!in_array($authUser['rol_efectivo'], ['ADMIN', 'COORD'])) {
            sendResponse(false, 'Acceso denegado. Se requiere rol ADMIN o COORD.', null, 403);
        }

        $stmt = $db->query(
            "SELECT
                id, usuario, nombre_completo, rol, rol_temporal,
                bloqueado, intentos_fallidos,
                creado_el, actualizado_el
             FROM usuarios
             ORDER BY
                FIELD(rol, 'ADMIN', 'COORD', 'ANALISTA', 'USUARIO', 'CONSULTA'),
                usuario"
        );
        $usuarios = $stmt->fetchAll();

        // Mapear a formato esperado por el frontend
        $data = array_map(function ($u) {
            return [
                'id' => (int) $u['id'],
                'username' => $u['usuario'],
                'full_name' => $u['nombre_completo'],
                'role' => $u['rol'],
                'temporary_role' => $u['rol_temporal'],
                'is_locked' => (bool) $u['bloqueado'],
                'failed_attempts' => (int) $u['intentos_fallidos'],
                'created_at' => $u['creado_el'],
                'updated_at' => $u['actualizado_el'],
            ];
        }, $usuarios);

        sendResponse(true, 'Usuarios obtenidos.', $data);
    }

    // ═══════════════════════════════════════════════════════════
    // DELETE — Eliminar usuario
    // ═══════════════════════════════════════════════════════════
    if ($method === 'DELETE') {
        if ($authUser['rol_efectivo'] !== 'ADMIN') {
            sendResponse(false, 'Solo ADMIN puede eliminar usuarios.', null, 403);
        }

        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            sendResponse(false, 'ID de usuario inválido.', null, 400);
        }

        // No permitir auto-eliminación
        if ($id === $authUser['id']) {
            sendResponse(false, 'No puede eliminar su propia cuenta.', null, 400);
        }

        $stmt = $db->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            sendResponse(false, 'Usuario no encontrado.', null, 404);
        }

        logAction($db, $authUser['id'], 'USUARIO_ELIMINADO', ['usuario_id' => $id]);
        sendResponse(true, 'Usuario eliminado correctamente.');
    }

    // ═══════════════════════════════════════════════════════════
    // POST — Acciones de escritura
    // ═══════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? '';

        // ── Crear usuario ────────────────────────────────────
        if ($action === 'create') {
            if ($authUser['rol_efectivo'] !== 'ADMIN') {
                sendResponse(false, 'Solo ADMIN puede crear usuarios.', null, 403);
            }

            $newUser = trim($body['username'] ?? '');
            $newPass = $body['password'] ?? '';
            $newName = trim($body['full_name'] ?? '');
            $newRole = strtoupper(trim($body['role'] ?? 'USUARIO'));

            if (empty($newUser) || empty($newPass) || empty($newName)) {
                sendResponse(false, 'Usuario, contraseña y nombre completo son requeridos.', null, 400);
            }

            if (strlen($newPass) < 6) {
                sendResponse(false, 'La contraseña debe tener al menos 6 caracteres.', null, 400);
            }

            $rolesValidos = ['ADMIN', 'COORD', 'ANALISTA', 'USUARIO', 'CONSULTA'];
            if (!in_array($newRole, $rolesValidos)) {
                sendResponse(false, 'Rol inválido. Valores permitidos: ' . implode(', ', $rolesValidos), null, 400);
            }

            // Verificar formato de usuario
            if (!preg_match('/^[a-z][a-z0-9]{2,19}$/', $newUser)) {
                sendResponse(false, 'El usuario debe comenzar con letra minúscula y contener solo letras y números (ej: amejia, rmartinez).', null, 400);
            }

            // Verificar duplicado
            $check = $db->prepare("SELECT id FROM usuarios WHERE usuario = ?");
            $check->execute([$newUser]);
            if ($check->fetch()) {
                sendResponse(false, "El usuario '{$newUser}' ya existe.", null, 409);
            }

            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $stmt = $db->prepare(
                "INSERT INTO usuarios (usuario, clave_hash, nombre_completo, rol, bloqueado, intentos_fallidos, requiere_cambio_clave, clave_ultima_rotacion)
                 VALUES (?, ?, ?, ?, 0, 0, 1, CURRENT_DATE)"
            );
            $stmt->execute([$newUser, $hash, $newName, $newRole]);

            logAction($db, $authUser['id'], 'USUARIO_CREADO', [
                'nuevo_usuario' => $newUser,
                'rol' => $newRole,
            ]);

            sendResponse(true, "Usuario '{$newUser}' creado exitosamente.", [
                'id' => (int) $db->lastInsertId(),
            ]);
        }

        // ── Editar usuario ───────────────────────────────────
        if ($action === 'edit') {
            if ($authUser['rol_efectivo'] !== 'ADMIN') {
                sendResponse(false, 'Solo ADMIN puede editar usuarios.', null, 403);
            }

            $id = intval($body['id'] ?? 0);
            $newName = trim($body['full_name'] ?? '');
            $newRole = strtoupper(trim($body['role'] ?? ''));

            if ($id <= 0) {
                sendResponse(false, 'ID de usuario inválido.', null, 400);
            }

            $updates = [];
            $params = [];

            if (!empty($newName)) {
                $updates[] = 'nombre_completo = ?';
                $params[] = $newName;
            }
            if (!empty($newRole)) {
                $rolesValidos = ['ADMIN', 'COORD', 'ANALISTA', 'USUARIO', 'CONSULTA'];
                if (!in_array($newRole, $rolesValidos)) {
                    sendResponse(false, 'Rol inválido.', null, 400);
                }
                $updates[] = 'rol = ?';
                $params[] = $newRole;
            }

            if (empty($updates)) {
                sendResponse(false, 'No hay campos para actualizar.', null, 400);
            }

            $updates[] = 'actualizado_el = NOW()';
            $params[] = $id;

            $sql = "UPDATE usuarios SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            logAction($db, $authUser['id'], 'USUARIO_EDITADO', [
                'usuario_id' => $id,
                'cambios' => $body,
            ]);

            sendResponse(true, 'Usuario actualizado correctamente.');
        }

        // ── Cambiar contraseña ───────────────────────────────
        if ($action === 'change_password') {
            $id = intval($body['id'] ?? 0);
            $newPass = $body['new_password'] ?? '';

            if ($id <= 0 || empty($newPass)) {
                sendResponse(false, 'ID y nueva contraseña son requeridos.', null, 400);
            }

            if (strlen($newPass) < 6) {
                sendResponse(false, 'La contraseña debe tener al menos 6 caracteres.', null, 400);
            }

            // Solo ADMIN o COORD pueden cambiar la contraseña de otros.
            if ($id !== $authUser['id'] && !in_array($authUser['rol_efectivo'], ['ADMIN', 'COORD'])) {
                sendResponse(false, 'Solo Administradores y Coordinadores pueden cambiar la contraseña de otros usuarios.', null, 403);
            }

            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE usuarios SET clave_hash = ?, requiere_cambio_clave = 0, clave_ultima_rotacion = CURRENT_DATE, actualizado_el = NOW() WHERE id = ?");
            $stmt->execute([$hash, $id]);

            logAction($db, $authUser['id'], 'CONTRASENA_CAMBIADA', ['usuario_id' => $id]);
            sendResponse(true, 'Contraseña actualizada correctamente.');
        }

        // ── Delegar rol temporal ─────────────────────────────
        if ($action === 'delegate') {
            if (!in_array($authUser['rol_efectivo'], ['ADMIN', 'COORD'])) {
                sendResponse(false, 'Solo ADMIN o COORD pueden delegar roles.', null, 403);
            }

            $targetUser = trim($body['username'] ?? '');
            $tempRole = strtoupper(trim($body['tempRole'] ?? ''));

            if (empty($targetUser) || empty($tempRole)) {
                sendResponse(false, 'Usuario destino y rol temporal son requeridos.', null, 400);
            }

            $rolesValidos = ['ADMIN', 'COORD', 'ANALISTA', 'USUARIO', 'CONSULTA'];
            if (!in_array($tempRole, $rolesValidos)) {
                sendResponse(false, 'Rol temporal inválido.', null, 400);
            }

            // Por defecto, delegación de 24 horas si no se especifica
            $expiresIn = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $stmt = $db->prepare(
                "UPDATE usuarios SET 
                    rol_temporal = ?, 
                    rol_temporal_expira_en = ?, 
                    delegado_por = ?, 
                    actualizado_el = NOW() 
                 WHERE usuario = ?"
            );
            $stmt->execute([$tempRole, $expiresIn, $authUser['id'], $targetUser]);

            if ($stmt->rowCount() === 0) {
                sendResponse(false, "Usuario '{$targetUser}' no encontrado.", null, 404);
            }

            logAction($db, $authUser['id'], 'ROL_DELEGADO', [
                'usuario_destino' => $targetUser,
                'rol_temporal' => $tempRole,
                'delegado_por' => $authUser['username'],
            ]);

            sendResponse(true, "Rol temporal '{$tempRole}' asignado a '{$targetUser}'.");
        }

        // ── Revocar delegación ───────────────────────────────
        if ($action === 'revoke') {
            if (!in_array($authUser['rol_efectivo'], ['ADMIN', 'COORD'])) {
                sendResponse(false, 'Solo ADMIN o COORD pueden revocar roles.', null, 403);
            }

            $targetUser = trim($body['username'] ?? '');
            if (empty($targetUser)) {
                sendResponse(false, 'Usuario destino es requerido.', null, 400);
            }

            $stmt = $db->prepare(
                "UPDATE usuarios SET 
                    rol_temporal = NULL, 
                    rol_temporal_expira_en = NULL, 
                    delegado_por = NULL, 
                    actualizado_el = NOW() 
                 WHERE usuario = ?"
            );
            $stmt->execute([$targetUser]);

            logAction($db, $authUser['id'], 'ROL_REVOCADO', ['usuario_destino' => $targetUser]);
            sendResponse(true, "Rol temporal revocado para '{$targetUser}'.");
        }

        // ── Desbloquear cuenta ───────────────────────────────
        if ($action === 'unlock') {
            if (!in_array($authUser['rol_efectivo'], ['ADMIN', 'COORD'])) {
                sendResponse(false, 'Solo Administradores y Coordinadores pueden desbloquear cuentas.', null, 403);
            }

            $id = intval($body['id'] ?? 0);
            if ($id <= 0) {
                sendResponse(false, 'ID de usuario inválido.', null, 400);
            }

            $stmt = $db->prepare(
                "UPDATE usuarios SET bloqueado = 0, intentos_fallidos = 0, actualizado_el = NOW() WHERE id = ?"
            );
            $stmt->execute([$id]);

            logAction($db, $authUser['id'], 'CUENTA_DESBLOQUEADA', ['usuario_id' => $id]);
            sendResponse(true, 'Cuenta desbloqueada correctamente.');
        }

        // ── Eliminar (vía POST) ──────────────────────────────
        if ($action === 'delete') {
            if ($authUser['rol_efectivo'] !== 'ADMIN') {
                sendResponse(false, 'Solo ADMIN puede eliminar usuarios.', null, 403);
            }

            $id = intval($body['id'] ?? 0);
            if ($id <= 0) {
                sendResponse(false, 'ID de usuario inválido.', null, 400);
            }
            if ($id === $authUser['id']) {
                sendResponse(false, 'No puede eliminar su propia cuenta.', null, 400);
            }

            $stmt = $db->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);

            logAction($db, $authUser['id'], 'USUARIO_ELIMINADO', ['usuario_id' => $id]);
            sendResponse(true, 'Usuario eliminado correctamente.');
        }

        // Acción no reconocida
        sendResponse(false, "Acción '{$action}' no reconocida.", null, 400);
    }

    // Método no soportado
    sendResponse(false, 'Método no permitido.', null, 405);

} catch (Exception $e) {
    error_log('[SCI-TSS users.php] ' . $e->getMessage());
    sendResponse(false, 'Error interno del servidor.', null, 500);
}