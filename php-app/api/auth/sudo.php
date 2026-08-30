<?php
/**
 * api/auth/sudo.php — SUDO endpoint
 * Centralizado con bootstrap
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../middleware/auth_check.php';

header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = getDB();
    Security::requirePermission($pdo, 'security.sudo');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido.', 405);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? 'grant';
    $targetUserId = (int) ($body['user_id'] ?? 0);
    $durationMinutes = (int) ($body['minutes'] ?? 60);

    // Se acepta el permiso por id o por nombre: la interfaz razona en nombres
    // ('carnet.create'), mientras que las tablas relacionan por id.
    $permissionId = (int) ($body['permission_id'] ?? 0);
    if (!$permissionId && !empty($body['permission'])) {
        $lookup = $pdo->prepare('SELECT id FROM permisos WHERE nombre = ? LIMIT 1');
        $lookup->execute([trim($body['permission'])]);
        $permissionId = (int) $lookup->fetchColumn();
        if (!$permissionId)
            throw new Exception('El permiso indicado no existe: ' . trim($body['permission']), 404);
    }

    // ── Contención de escalada de privilegios ─────────────────────────────────
    // 'security.sudo' lo tiene también el rol COORD. Sin estas comprobaciones, el
    // endpoint concedía CUALQUIER permiso a CUALQUIER usuario por CUALQUIER duración:
    // un COORD podía autoconcederse 'user.manage' y quedar con capacidades de ADMIN.
    $solicitanteId = (int) $_SESSION['user_id'];

    if ($action === 'grant') {
        if (!$targetUserId || !$permissionId)
            throw new Exception('Datos incompletos para asignar permiso.', 400);

        if ($targetUserId === $solicitanteId)
            throw new Exception('No puede asignarse privilegios temporales a sí mismo.', 403);

        $pStmt = $pdo->prepare('SELECT nombre FROM permisos WHERE id = ? LIMIT 1');
        $pStmt->execute([$permissionId]);
        $permNombre = $pStmt->fetchColumn();
        if (!$permNombre)
            throw new Exception('El permiso indicado no existe.', 404);

        // Nadie puede delegar más autoridad de la que posee.
        if (!Security::hasPermission($pdo, $solicitanteId, $permNombre))
            throw new Exception("No puede delegar un permiso que usted no posee: [{$permNombre}].", 403);

        // La delegación de la propia facultad de delegar sólo corresponde a ADMIN,
        // de lo contrario la restricción anterior se elude encadenando concesiones.
        $rolSolicitante = strtoupper($_SESSION['role'] ?? '');
        if ($permNombre === 'security.sudo' && $rolSolicitante !== 'ADMIN')
            throw new Exception('Sólo un Administrador puede delegar la facultad de delegación.', 403);

        $uStmt = $pdo->prepare('SELECT id FROM usuarios WHERE id = ? AND activa = 1 LIMIT 1');
        $uStmt->execute([$targetUserId]);
        if (!$uStmt->fetchColumn())
            throw new Exception('El usuario destino no existe o está inactivo.', 404);

        // Un privilegio "temporal" sin tope deja de serlo. El máximo acompaña al
        // límite de 30 días que ya ofrece la interfaz de delegación.
        if ($durationMinutes < 1 || $durationMinutes > 43200)
            throw new Exception('La duración debe estar entre 1 minuto y 30 días.', 400);

        Security::grantTemporaryPermission($pdo, $targetUserId, $permissionId, $durationMinutes);
        echo json_encode([
            'success' => true,
            'message' => 'Privilegio temporal asignado correctamente.',
            'data' => ['user_id' => $targetUserId, 'permission_id' => $permissionId, 'expires_in' => $durationMinutes . ' minutos']
        ]);
    } elseif ($action === 'revoke') {
        if (!$targetUserId || !$permissionId)
            throw new Exception('Datos incompletos para revocar permiso.', 400);
        Security::revokeTemporaryPermission($pdo, $targetUserId, $permissionId);
        echo json_encode(['success' => true, 'message' => 'Privilegio temporal revocado.']);
    } else {
        throw new Exception('Acción no reconocida.', 400);
    }
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}