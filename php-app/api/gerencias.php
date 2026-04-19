<?php
/**
 * api/gerencias.php — Gestión de Gerencias (REMEDIADO v2.1)
 * ===========================================================
 * CORRECCIÓN CRÍTICA: $method se declaraba DESPUÉS de usarse en los if de RBAC.
 * Causa: PHP evaluaba `if ($method === 'GET')` con $method undefined → Warning/Error.
 * Fix: Mover asignación de $method a la primera línea, antes de cualquier uso.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/middleware/RBAC.php';
require_once __DIR__ . '/middleware/auth_check.php';

// CRÍTICO: $method debe declararse ANTES de usarse en requirePermission
$method = $_SERVER['REQUEST_METHOD'];
$pdo = getDB();

if ($method === 'GET') {
    Security::requirePermission($pdo, 'carnet.view_all');
} else {
    Security::requirePermission($pdo, 'gerencia.manage');
}

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT id, nombre, siglas, activa FROM gerencias ORDER BY nombre ASC");
            sendResponse(true, 'Lista de gerencias.', $stmt->fetchAll());
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = isset($input['id']) ? intval($input['id']) : null;
            $nombre = trim($input['nombre'] ?? '');

            if (empty($nombre)) {
                sendResponse(false, 'El nombre de la gerencia es requerido.', null, 400);
            }

            if ($id) {
                // UPDATE: renombrar gerencia existente
                $pdo->prepare("UPDATE gerencias SET nombre = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$nombre, $id]);
                sendResponse(true, 'Gerencia actualizada.');
            } else {
                // INSERT: crear nueva gerencia
                // Verificar duplicado
                $check = $pdo->prepare("SELECT id FROM gerencias WHERE nombre = ? LIMIT 1");
                $check->execute([$nombre]);
                if ($check->fetchColumn()) {
                    sendResponse(false, "Ya existe una gerencia con el nombre '{$nombre}'.", null, 409);
                }
                $stmt = $pdo->prepare("INSERT INTO gerencias (nombre) VALUES (?)");
                $stmt->execute([$nombre]);
                $newId = $pdo->lastInsertId();
                sendResponse(true, 'Gerencia creada exitosamente.', ['id' => (int) $newId, 'nombre' => $nombre]);
            }
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = isset($_GET['id']) ? intval($_GET['id']) : (isset($input['id']) ? intval($input['id']) : null);
            $nombre = trim($input['nombre'] ?? '');

            if (!$id || empty($nombre)) {
                sendResponse(false, 'ID y nombre son requeridos.', null, 400);
            }
            $pdo->prepare("UPDATE gerencias SET nombre = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$nombre, $id]);
            sendResponse(true, 'Gerencia actualizada.');
            break;

        case 'DELETE':
            $id = isset($_GET['id']) ? intval($_GET['id']) : null;
            if (!$id) {
                sendResponse(false, 'ID de gerencia no proporcionado.', null, 400);
            }
            // Verificar si tiene empleados asociados antes de eliminar
            $emp = $pdo->prepare("SELECT COUNT(*) FROM empleados WHERE gerencia_id = ?");
            $emp->execute([$id]);
            if ($emp->fetchColumn() > 0) {
                sendResponse(false, 'No se puede eliminar: la gerencia tiene empleados asociados.', null, 409);
            }
            $pdo->prepare("DELETE FROM gerencias WHERE id = ?")->execute([$id]);
            sendResponse(true, 'Gerencia eliminada exitosamente.');
            break;

        default:
            sendResponse(false, 'Método HTTP no permitido.', null, 405);
    }
} catch (Exception $e) {
    error_log('[SCI-TSS gerencias.php] ' . $e->getMessage());
    sendResponse(false, 'Error interno del servidor.', null, 500);
}
