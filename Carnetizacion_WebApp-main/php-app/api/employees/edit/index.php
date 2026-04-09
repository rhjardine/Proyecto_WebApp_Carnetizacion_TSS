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
require_once __DIR__ . '/../../../middleware/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$id   = intval($body['id'] ?? 0);

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'El campo id es requerido.']);
    exit;
}

// --- Campos permitidos para actualizar (whitelist) ---
$allowed  = ['nombre', 'cargo', 'departamento', 'tipo_sangre', 'photo_url'];
$setClauses = [];
$params     = [':id' => $id];
$changed    = [];   // Para audit log

foreach ($allowed as $field) {
    if (!array_key_exists($field, $body)) continue;   // Solo los que vienen en la petición

    $value = $body[$field];

    // Validaciones mínimas
    if ($field === 'photo_url') {
        // Aceptar null (eliminar foto) o una URL válida relativa
        $value = ($value === null) ? null : trim($value);
    } else {
        $value = trim((string)$value);
        if (in_array($field, ['nombre', 'cargo', 'departamento'], true) && $value === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "El campo '{$field}' no puede estar vacío."]);
            exit;
        }
        $value = $value === '' ? null : $value;
    }

    $setClauses[]       = "{$field} = :{$field}";
    $params[":{$field}"] = $value;
    $changed[$field]    = $value;
}

if (empty($setClauses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No se enviaron campos válidos para actualizar.']);
    exit;
}

try {
    $db = getDB();

    $sql  = 'UPDATE employees SET ' . implode(', ', $setClauses) . ' WHERE id = :id RETURNING *';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $employee = $stmt->fetch();

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
