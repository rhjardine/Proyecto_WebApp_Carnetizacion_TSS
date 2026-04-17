<?php
/**
 * PATCH /api/employees/edit/index.php
 * Actualiza campos editables de un empleado y registra el cambio en audit_log.
 *
 * Acepta JSON: {
 *   "id"          : 1,           (requerido)
 *   "nombre"      : "Apellido N",
 *   "cargo"       : "Analista",
 *   "departamento": "TI",
 *   "tipo_sangre" : "O+",
 *   "photo_url"   : null         ← null elimina la foto
 * }
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

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'El campo id es requerido.']);
    exit;
}

// --- Campos permitidos para actualizar (whitelist canónica v2.0) ---
$allowed = [
    'primer_nombre',
    'segundo_nombre',
    'primer_apellido',
    'segundo_apellido',
    'cargo',
    'gerencia_id',
    'tipo_sangre',
    'foto_url'
];
$setClauses = [];
$params = [':id' => $id];
$changed = [];   // Para audit log

foreach ($allowed as $field) {
    if (!array_key_exists($field, $body))
        continue;   // Solo los que vienen en la petición

    $value = $body[$field];

    // Validaciones mínimas
    if ($field === 'foto_url') {
        $value = ($value === null) ? null : trim((string) $value);
    } elseif ($field === 'gerencia_id') {
        $value = $value ? intval($value) : null;
    } else {
        $value = trim((string) $value);
        // Campos obligatorios si se envían
        if (in_array($field, ['primer_nombre', 'primer_apellido', 'cargo'], true) && $value === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "El campo '{$field}' no puede estar vacío."]);
            exit;
        }
        $value = $value === '' ? null : $value;
    }

    $setClauses[] = "{$field} = :{$field}";
    $params[":{$field}"] = $value;
    $changed[$field] = $value;
}

if (empty($setClauses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No se enviaron campos válidos para actualizar.']);
    exit;
}

try {
    $db = getDB();

    // MySQL: actualizar en la tabla canónica 'empleados' y luego obtener el registro
    $sql = 'UPDATE empleados SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    // Recuperar datos actualizados
    $sel = $db->prepare('SELECT e.*, g.nombre AS gerencia FROM empleados e LEFT JOIN gerencias g ON e.gerencia_id = g.id WHERE e.id = :id LIMIT 1');
    $sel->execute([':id' => $id]);
    $employee = $sel->fetch();

    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empleado no encontrado.']);
        exit;
    }

    // --- AUDIT LOG ---
    logAction($db, $authUser['id'], 'EMPLOYEE_FIELDS_UPDATED', array_merge(
        ['employee_id' => $id, 'cedula' => $employee['cedula']],
        $changed
    ));

    echo json_encode(['success' => true, 'data' => $employee]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al actualizar el empleado.']);
}
