<?php
/**
 * /api/employees/update/index.php
 * PATCH — Update employee status
 * Accepts: { "id": 1, "status": "Verificado" }
 */
require_once __DIR__ . '/../../../middleware/RBAC.php';
require_once __DIR__ . '/../../../middleware/auth_check.php';

$db = getDB();
Security::requirePermission($db, 'carnet.update_status');

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$id = intval($body['id'] ?? 0);
$status = trim($body['status'] ?? '');

$allowed = ['Pendiente', 'Verificado', 'Impreso', 'Rechazado'];
if (!$id || !in_array($status, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID y estado válido requeridos.']);
    exit;
}

try {
    $db = getDB();
    // Actualizar estado en la tabla canonical 'empleados' (MySQL)
    $stmt = $db->prepare('UPDATE empleados SET estado_carnet = :status WHERE id = :id');
    $stmt->execute([':status' => $status, ':id' => $id]);

    // Recuperar registro actualizado
    $sel = $db->prepare('SELECT id, cedula, primer_nombre, primer_apellido, estado_carnet FROM empleados WHERE id = :id LIMIT 1');
    $sel->execute([':id' => $id]);
    $employee = $sel->fetch();

    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empleado no encontrado.']);
        exit;
    }

    // ── AUDIT LOG ──────────────────────────────────────────
    logAction($db, $authUser['id'], 'EMPLOYEE_STATUS_CHANGED', [
        'employee_id' => $employee['id'],
        'cedula' => $employee['cedula'],
        'nombre' => trim(($employee['primer_nombre'] ?? '') . ' ' . ($employee['primer_apellido'] ?? '')),
        'new_status' => $status,
    ]);
    // ───────────────────────────────────────────────────────

    echo json_encode(['success' => true, 'data' => $employee]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al actualizar estado.']);
}
