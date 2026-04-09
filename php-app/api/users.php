<?php
/**
 * api/users.php — Gestión de Usuarios y Roles
 */
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/db_mysql.php';
$pdo = getDB();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query(
                "SELECT id, usuario as username, nombre_completo as full_name, rol as role, rol_temporal as temporary_role, delegado_por as delegated_by, bloqueado as is_locked, intentos_fallidos as failed_attempts
                 FROM usuarios ORDER BY nombre_completo ASC"
            );
            $users = $stmt->fetchAll();
            // Resolve delegated_by id → username
            $userMap = array_column($users, 'username', 'id');
            foreach ($users as &$u) {
                $u['delegated_by'] = $u['delegated_by'] ? ($userMap[$u['delegated_by']] ?? null) : null;
            }
            sendResponse(true, 'Lista de usuarios', $users);
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $action = $input['action'] ?? '';

            if ($action === 'delegate') {
                $username = $input['username'] ?? '';
                $tempRole = $input['tempRole'] ?? null;
                $delegatedBy = $input['delegatedBy'] ?? null;

                if (empty($username)) {
                    sendResponse(false, 'Usuario no especificado', null, 400);
                    exit;
                }
                if (!in_array($tempRole, ['ADMIN', 'COORD', 'ANALISTA', 'USUARIO'])) {
                    sendResponse(false, 'Rol temporal inválido', null, 400);
                    exit;
                }

                // Find delegator's ID
                $delegatorId = null;
                if ($delegatedBy) {
                    $ds = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
                    $ds->execute([$delegatedBy]);
                    $delegatorId = $ds->fetchColumn() ?: null;
                }

                $pdo->prepare("UPDATE usuarios SET rol_temporal = ?, delegado_por = ?, actualizado_el = NOW() WHERE usuario = ?")
                    ->execute([$tempRole, $delegatorId, $username]);

                sendResponse(true, "Permiso temporal '$tempRole' asignado a '$username'");

            } elseif ($action === 'revoke') {
                $username = $input['username'] ?? '';
                if (empty($username)) {
                    sendResponse(false, 'Usuario no especificado', null, 400);
                    exit;
                }

                $pdo->prepare("UPDATE usuarios SET rol_temporal = NULL, delegado_por = NULL, actualizado_el = NOW() WHERE usuario = ?")
                    ->execute([$username]);

                sendResponse(true, "Permiso temporal revocado para '$username'");

            } elseif ($action === 'unlock') {
                // Allow admin to unlock user accounts
                $username = $input['username'] ?? '';
                if (empty($username)) {
                    sendResponse(false, 'Usuario no especificado', null, 400);
                    exit;
                }

                $pdo->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado = FALSE, actualizado_el = NOW() WHERE usuario = ?")
                    ->execute([$username]);

                sendResponse(true, "Cuenta de '$username' desbloqueada");

            } else {
                sendResponse(false, "Acción '$action' no válida", null, 400);
            }
            break;

        default:
            sendResponse(false, 'Método no permitido', null, 405);
    }
} catch (Exception $e) {
    sendResponse(false, 'Error: ' . $e->getMessage(), null, 500);
}
