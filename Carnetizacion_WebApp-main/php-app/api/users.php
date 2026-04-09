<?php
/**
 * api/users.php — Gestión de Usuarios y Roles
 */
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query(
                "SELECT id, username, full_name, role, temporary_role, delegated_by, is_locked, failed_attempts
                 FROM users ORDER BY full_name ASC"
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
                    $ds = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $ds->execute([$delegatedBy]);
                    $delegatorId = $ds->fetchColumn() ?: null;
                }

                $pdo->prepare("UPDATE users SET temporary_role = ?, delegated_by = ?, updated_at = NOW() WHERE username = ?")
                    ->execute([$tempRole, $delegatorId, $username]);

                sendResponse(true, "Permiso temporal '$tempRole' asignado a '$username'");

            } elseif ($action === 'revoke') {
                $username = $input['username'] ?? '';
                if (empty($username)) {
                    sendResponse(false, 'Usuario no especificado', null, 400);
                    exit;
                }

                $pdo->prepare("UPDATE users SET temporary_role = NULL, delegated_by = NULL, updated_at = NOW() WHERE username = ?")
                    ->execute([$username]);

                sendResponse(true, "Permiso temporal revocado para '$username'");

            } elseif ($action === 'unlock') {
                // Allow admin to unlock user accounts
                $username = $input['username'] ?? '';
                if (empty($username)) {
                    sendResponse(false, 'Usuario no especificado', null, 400);
                    exit;
                }

                $pdo->prepare("UPDATE users SET failed_attempts = 0, is_locked = FALSE, updated_at = NOW() WHERE username = ?")
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
