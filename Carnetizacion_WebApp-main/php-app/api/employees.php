<?php
/**
 * api/employees.php — Gestión de Empleados
 * Corrected to match schema: joins gerencias table, uses photo_path column.
 * Also supports partial updates (only fields sent in body are updated).
 */
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $id = $_GET['id'] ?? null;
            if ($id) {
                // Single employee with gerencia name joined
                $sql = "SELECT e.*, g.nombre AS gerencia
                        FROM employees e
                        LEFT JOIN gerencias g ON e.gerencia_id = g.id
                        WHERE e.id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id]);
                $emp = $stmt->fetch();
                if ($emp) {
                    // Return photo_url as alias for compatibility with frontend
                    $emp['photo_url'] = $emp['photo_path'];
                    sendResponse(true, 'Empleado encontrado', ['data' => [$emp], 'meta' => ['totalRecords' => 1, 'currentPage' => 1, 'totalPages' => 1, 'limit' => 1]]);
                } else {
                    sendResponse(false, 'Empleado no encontrado', null, 404);
                }
            } else {
                // List with search, status filter, pagination
                $page = max(1, intval($_GET['page'] ?? 1));
                $limit = min(200, max(1, intval($_GET['limit'] ?? 50)));
                $search = trim($_GET['search'] ?? '');
                $status = trim($_GET['status'] ?? '');
                $offset = ($page - 1) * $limit;

                $where = [];
                $params = [];
                if ($search) {
                    $where[] = "(e.nombres ILIKE ? OR e.apellidos ILIKE ? OR e.cedula ILIKE ?)";
                    $s = "%{$search}%";
                    $params[] = $s;
                    $params[] = $s;
                    $params[] = $s;
                }
                if ($status) {
                    $where[] = "e.status = ?";
                    $params[] = $status;
                }
                $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

                $countSQL = "SELECT COUNT(*) FROM employees e $whereSQL";
                $total = (int) $pdo->prepare($countSQL)->execute($params) ? $pdo->prepare($countSQL)->execute($params) : 0;
                $cStmt = $pdo->prepare($countSQL);
                $cStmt->execute($params);
                $total = (int) $cStmt->fetchColumn();

                $listSQL = "SELECT e.*, g.nombre AS gerencia, e.photo_path AS photo_url
                            FROM employees e
                            LEFT JOIN gerencias g ON e.gerencia_id = g.id
                            $whereSQL
                            ORDER BY e.created_at DESC
                            LIMIT ? OFFSET ?";
                $dStmt = $pdo->prepare($listSQL);
                $dStmt->execute(array_merge($params, [$limit, $offset]));
                $list = $dStmt->fetchAll();

                sendResponse(true, 'Lista de empleados', [
                    'data' => $list,
                    'meta' => [
                        'totalRecords' => $total,
                        'currentPage' => $page,
                        'totalPages' => (int) ceil($total / $limit),
                        'limit' => $limit,
                    ]
                ]);
            }
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = $input['id'] ?? null;

            if ($id) {
                // --- PARTIAL UPDATE: only update fields that are present in body ---
                $allowed = ['nombres', 'apellidos', 'cargo', 'nacionalidad', 'nivel_permiso', 'status', 'forma_entrega'];
                $setClauses = [];
                $values = [];

                foreach ($allowed as $field) {
                    if (array_key_exists($field, $input)) {
                        $setClauses[] = "$field = ?";
                        $values[] = $input[$field];
                    }
                }

                // Gerencia by name → resolve to ID
                if (array_key_exists('gerencia', $input)) {
                    $gStmt = $pdo->prepare("SELECT id FROM gerencias WHERE nombre = ?");
                    $gStmt->execute([trim($input['gerencia'])]);
                    $gId = $gStmt->fetchColumn();
                    if ($gId) {
                        $setClauses[] = "gerencia_id = ?";
                        $values[] = $gId;
                    }
                }

                // Photo (sent as base64 data URL)
                if (array_key_exists('photo_url', $input)) {
                    $setClauses[] = "photo_path = ?";
                    $values[] = $input['photo_url'];
                }

                if (empty($setClauses)) {
                    sendResponse(false, 'No hay campos para actualizar', null, 400);
                    exit;
                }

                $setClauses[] = "updated_at = NOW()";
                $values[] = $id;
                $sql = "UPDATE employees SET " . implode(', ', $setClauses) . " WHERE id = ?";
                $pdo->prepare($sql)->execute($values);
                sendResponse(true, 'Empleado actualizado');
            } else {
                // --- CREATE ---
                $cedula = trim($input['cedula'] ?? '');
                $nombres = trim($input['nombres'] ?? '');
                $apellidos = trim($input['apellidos'] ?? '');
                $cargo = trim($input['cargo'] ?? '');
                $gerenciaNom = trim($input['gerencia'] ?? '');
                $nac = $input['nacionalidad'] ?? 'V';
                $nivel = $input['nivel_permiso'] ?? 'Nivel 1';

                if (!$cedula || !$nombres || !$apellidos || !$cargo || !$gerenciaNom) {
                    sendResponse(false, 'Campos obligatorios incompletos', null, 400);
                    exit;
                }

                // Resolve gerencia name → ID
                $gStmt = $pdo->prepare("SELECT id FROM gerencias WHERE nombre = ?");
                $gStmt->execute([$gerenciaNom]);
                $gerenciaId = $gStmt->fetchColumn();

                if (!$gerenciaId) {
                    // Auto-create the gerencia if not found
                    $pdo->prepare("INSERT INTO gerencias (nombre) VALUES (?)")->execute([$gerenciaNom]);
                    $gerenciaId = $pdo->lastInsertId();
                }

                $sql = "INSERT INTO employees 
                        (cedula, nombres, apellidos, cargo, gerencia_id, nacionalidad, nivel_permiso, status, fecha_ingreso)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'Pendiente por Imprimir', CURRENT_DATE)
                        RETURNING id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$cedula, $nombres, $apellidos, $cargo, $gerenciaId, $nac, $nivel]);
                $newId = $stmt->fetchColumn();
                sendResponse(true, 'Empleado registrado con éxito', ['id' => $newId]);
            }
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                sendResponse(false, 'ID no proporcionado', null, 400);
                exit;
            }
            $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
            sendResponse(true, 'Empleado eliminado');
            break;

        default:
            sendResponse(false, 'Método no permitido', null, 405);
    }
} catch (Exception $e) {
    sendResponse(false, 'Error: ' . $e->getMessage(), null, 500);
}
