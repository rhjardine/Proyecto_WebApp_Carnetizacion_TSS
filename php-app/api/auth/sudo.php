<?php
/**
 * SCI-TSS SUDO Endpoint
 * =====================
 * Gestiona la asignación de privilegios temporales (SUDO Pattern).
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/RBAC.php';
require_once __DIR__ . '/../middleware/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();

    // Requerir permiso específico para gestionar SUDO
    Security::requirePermission($pdo, 'security.sudo');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido.', 405);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $action = $body['action'] ?? 'grant';
    $targetUserId = (int) ($body['user_id'] ?? 0);
    $permissionId = (int) ($body['permission_id'] ?? 0);
    $durationMinutes = (int) ($body['minutes'] ?? 60);

    // Los métodos en RBAC.php ya han sido actualizados a español internamente
    if ($action === 'grant') {
        if (!$targetUserId || !$permissionId) {
            throw new Exception('Datos incompletos para asignar permiso.', 400);
        }

        Security::grantTemporaryPermission($pdo, $targetUserId, $permissionId, $durationMinutes);

        echo json_encode([
            'success' => true,
            'message' => 'Privilegio temporal asignado correctamente.',
            'data' => [
                'user_id' => $targetUserId,
                'permission_id' => $permissionId,
                'expires_in' => $durationMinutes . ' minutos'
            ]
        ]);
    } elseif ($action === 'revoke') {
        if (!$targetUserId || !$permissionId) {
            throw new Exception('Datos incompletos para revocar permiso.', 400);
        }

        Security::revokeTemporaryPermission($pdo, $targetUserId, $permissionId);

        echo json_encode([
            'success' => true,
            'message' => 'Privilegio temporal revocado.'
        ]);
    } else {
        throw new Exception('Acción no reconocida.', 400);
    }

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
