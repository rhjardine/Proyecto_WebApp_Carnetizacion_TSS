<?php
/**
 * api/employees.php — CRUD de Empleados (MySQL / InnoDB)
 * Sistema de Carnetización Inteligente (SCI-TSS)
 * Esquema: carnetizacion_tss
 *
 * v3.1 — Centralizado con bootstrap.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/middleware/auth_check.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$userId = $_SESSION['user_id'] ?? null;
$rolEf = $_SESSION['role'] ?? '';

// HARDENING: NIST RBAC Enforcement
if ($method === 'GET')
    Security::requirePermission($db, 'carnet.view_all');
if ($method === 'DELETE') {
    if ($rolEf !== 'ADMIN') {
        sendResponse(false, 'Acceso denegado. Solo un Administrador puede eliminar registros físicos.', null, 403);
    }
}

const ESTADOS_VALIDOS = ['Pendiente por Imprimir', 'Carnet Impreso', 'Carnet Entregado'];
const FORMAS_ENTREGA = ['', 'Manual', 'Digital'];
const CAMPOS_EDITABLES = [
    'primer_nombre',
    'segundo_nombre',
    'primer_apellido',
    'segundo_apellido',
    'cargo',
    'email',
    // fecha_ingreso existe en el esquema y el editor la envía, pero no estaba en
    // esta lista blanca: se descartaba en silencio y el frontend informaba éxito.
    'fecha_ingreso',
    'estado_laboral',
    'forma_entrega',
    'nivel_permiso',
    'vencimiento',
    'datos_adicionales'
];

try {
    switch ($method) {
        case 'GET':
            $id = isset($_GET['id']) && is_numeric($_GET['id']) ? intval($_GET['id']) : null;
            if ($id) {
                $sql = "SELECT e.*, e.primer_nombre AS nombres, e.primer_apellido AS apellidos,
                               g.nombre AS gerencia, e.foto_url AS photo_url
                        FROM empleados e
                        LEFT JOIN gerencias g ON e.gerencia_id = g.id
                        WHERE e.id = ? LIMIT 1";
                $stmt = $db->prepare($sql);
                $stmt->execute([$id]);
                $emp = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($emp) {
                    echo json_encode(['success' => true, 'data' => $emp]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Registro de empleado no encontrado.']);
                }
                exit;
            }

            // Lista paginada
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = min(200, max(1, intval($_GET['limit'] ?? 50)));
            $search = trim($_GET['search'] ?? '');
            $status = trim($_GET['status'] ?? '');
            $offset = ($page - 1) * $limit;

            if ($status !== '' && !in_array($status, ESTADOS_VALIDOS, true)) {
                $status = '';
            }

            $conditions = [];
            $params = [];

            $cedulaQuery = trim($_GET['cedula'] ?? '');
            if ($cedulaQuery !== '') {
                $conditions[] = "e.cedula = ?";
                $params[] = $cedulaQuery;
            }

            if ($search !== '') {
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $conditions[] = "(e.primer_nombre LIKE ? OR e.primer_apellido LIKE ? OR e.cedula LIKE ? OR e.segundo_nombre LIKE ? OR e.segundo_apellido LIKE ?)";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            if ($status !== '') {
                $conditions[] = "e.estado_carnet = ?";
                $params[] = $status;
            }

            $where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

            $cStmt = $db->prepare("SELECT COUNT(*) FROM empleados e {$where}");
            $cStmt->execute($params);
            $total = (int) $cStmt->fetchColumn();
            $totalPages = (int) ceil($total / $limit);

            $dStmt = $db->prepare("
                SELECT e.*, e.primer_nombre AS nombres, e.primer_apellido AS apellidos,
                       g.nombre AS gerencia, e.foto_url AS photo_url
                FROM empleados e
                LEFT JOIN gerencias g ON e.gerencia_id = g.id
                {$where}
                ORDER BY e.fecha_ingreso DESC
                LIMIT ? OFFSET ?
            ");

            $allParams = array_merge($params, [$limit, $offset]);
            $dStmt->execute($allParams);
            $lista = $dStmt->fetchAll();

            sendResponse(true, 'Lista de empleados.', [
                'data' => $lista,
                'meta' => [
                    'totalRecords' => $total,
                    'currentPage' => $page,
                    'totalPages' => $totalPages,
                    'limit' => $limit,
                    'search' => $search,
                    'status' => $status,
                ],
            ]);
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = isset($input['id']) ? intval($input['id']) : null;
            $action = trim($input['action'] ?? '');

            if ($action === 'upload_payroll') {
                Security::requirePermission($db, 'carnet.create');
                $rows = $input['rows'] ?? [];
                $added = 0;
                $duplicados = 0;
                $invalidos = 0;
                $db->beginTransaction();
                try {
                    $ins = $db->prepare("INSERT IGNORE INTO empleados
                        (nacionalidad, cedula, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido, cargo, gerencia_id, fecha_ingreso, estado_carnet)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, 'Pendiente por Imprimir')");
                    foreach ($rows as $r) {
                        $ced = preg_replace('/[^0-9]/', '', $r['Cédula'] ?? $r['cedula'] ?? '');
                        if (strlen($ced) < 5) {
                            $invalidos++;
                            continue;
                        }
                        $nac = 'V';
                        if (isset($r['Nacionalidad']))
                            $nac = strtoupper(trim($r['Nacionalidad'])) === 'E' ? 'E' : 'V';
                        $gerNom = trim($r['Gerencia'] ?? $r['gerencia'] ?? '');
                        $gerId = null;
                        if ($gerNom) {
                            $gStmt = $db->prepare("SELECT id FROM gerencias WHERE nombre = ? LIMIT 1");
                            $gStmt->execute([$gerNom]);
                            $gerId = $gStmt->fetchColumn() ?: null;
                        }
                        $ins->execute([
                            $nac,
                            $ced,
                            trim($r['Primer Nombre'] ?? $r['nombres'] ?? ''),
                            trim($r['Segundo Nombre'] ?? '') ?: null,
                            trim($r['Primer Apellido'] ?? $r['apellidos'] ?? ''),
                            trim($r['Segundo Apellido'] ?? '') ?: null,
                            trim($r['Cargo'] ?? $r['cargo'] ?? ''),
                            $gerId,
                        ]);
                        // rowCount() distingue sin ambigüedad la fila insertada de la que
                        // INSERT IGNORE descartó por cédula duplicada; lastInsertId() no lo
                        // hace de forma fiable, porque su valor depende de si la sentencia
                        // llegó a generar un AUTO_INCREMENT.
                        if ($ins->rowCount() > 0) {
                            $added++;
                        } else {
                            $duplicados++;
                        }
                    }
                    $db->commit();
                    logAction($db, $userId, 'NOMINA_IMPORTADA', [
                        'filas' => count($rows),
                        'registrados' => $added,
                        'duplicados' => $duplicados,
                        'invalidos' => $invalidos,
                    ]);

                    // La coordinadora necesita saber qué pasó con cada fila, no sólo el total:
                    // antes una nómina completa de repetidos informaba lo mismo que un archivo vacío.
                    $detalle = [];
                    if ($duplicados > 0)
                        $detalle[] = "{$duplicados} ya estaban registrados";
                    if ($invalidos > 0)
                        $detalle[] = "{$invalidos} sin cédula válida";
                    $sufijo = $detalle ? ' (' . implode(', ', $detalle) . ')' : '';

                    sendResponse(
                        true,
                        $added > 0
                            ? "Nómina importada: {$added} empleado(s) registrado(s){$sufijo}."
                            : "No se registró ningún empleado nuevo{$sufijo}.",
                        ['registrados' => $added, 'duplicados' => $duplicados, 'invalidos' => $invalidos]
                    );
                } catch (Exception $ex) {
                    $db->rollBack();
                    sendResponse(false, 'Error al importar nómina: ' . $ex->getMessage(), null, 500);
                }
                break;
            }

            if ($action === 'auto_match') {
                Security::requirePermission($db, 'carnet.approve');
                logAction($db, $userId, 'AUTO_MATCH_EJECUTADO', ['modo' => 'placeholder']);
                sendResponse(true, 'Auto-Match ejecutado. Sin cambios aplicados en esta versión.');
                break;
            }

            if ($id) {
                Security::requirePermission($db, 'carnet.update');
                $setClauses = [];
                $values = [];

                foreach (CAMPOS_EDITABLES as $campo) {
                    if (array_key_exists($campo, $input)) {
                        $val = $input[$campo] ?? null;
                        if ($campo === 'datos_adicionales' && is_array($val)) {
                            $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                        }
                        // El alta valida el correo; la edición no lo hacía, de modo que un
                        // registro válido podía degradarse a un correo con formato inválido.
                        if ($campo === 'email') {
                            $val = is_string($val) ? trim($val) : $val;
                            if ($val === '' || $val === null) {
                                $val = null;
                            } elseif (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                                sendResponse(false, 'Formato de correo electrónico inválido.', null, 400);
                            }
                        }
                        // fecha_ingreso es DATE NOT NULL: una cadena vacía la rechazaría
                        // MySQL en modo estricto. Se omite del UPDATE en vez de fallar.
                        if ($campo === 'fecha_ingreso') {
                            $val = is_string($val) ? trim($val) : '';
                            if ($val === '') {
                                continue;
                            }
                            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                                sendResponse(false, 'La fecha de ingreso debe tener el formato AAAA-MM-DD.', null, 400);
                            }
                        }
                        $setClauses[] = "{$campo} = ?";
                        $values[] = $val;
                    }
                }

                $nuevoEstado = $input['estado_carnet'] ?? $input['status'] ?? null;
                if ($nuevoEstado !== null && in_array($nuevoEstado, ESTADOS_VALIDOS, true)) {
                    $setClauses[] = "estado_carnet = ?";
                    $values[] = $nuevoEstado;
                }

                if (array_key_exists('forma_entrega', $input)) {
                    $forma = $input['forma_entrega'];
                    if ($forma === '' || in_array($forma, FORMAS_ENTREGA, true)) {
                        $setClauses[] = "forma_entrega = ?";
                        $values[] = $forma ?: null;
                    }
                }

                if (array_key_exists('gerencia', $input) && $input['gerencia']) {
                    $gStmt = $db->prepare("SELECT id FROM gerencias WHERE nombre = ? LIMIT 1");
                    $gStmt->execute([trim($input['gerencia'])]);
                    $gId = $gStmt->fetchColumn();
                    if ($gId) {
                        $setClauses[] = "gerencia_id = ?";
                        $values[] = $gId;
                    }
                }

                if (array_key_exists('photo_url', $input) || array_key_exists('foto_url', $input)) {
                    $foto = $input['foto_url'] ?? $input['photo_url'] ?? '';
                    $setClauses[] = "foto_url = ?";
                    $values[] = $foto ?: null;
                }

                if (empty($setClauses)) {
                    sendResponse(false, 'No hay campos válidos para actualizar.', null, 400);
                    break;
                }

                $setClauses[] = "actualizado_el = NOW()";
                $values[] = $id;

                $sql = "UPDATE empleados SET " . implode(', ', $setClauses) . " WHERE id = ?";
                $db->prepare($sql)->execute($values);
                logAction($db, $userId, 'EMPLEADO_ACTUALIZADO', ['empleado_id' => $id]);
                sendResponse(true, 'Empleado actualizado correctamente.');
                break;
            }

            Security::requirePermission($db, 'carnet.create');
            $cedula = preg_replace('/[^0-9]/', '', trim($input['cedula'] ?? ''));
            $primerNombre = trim($input['primer_nombre'] ?? $input['nombres'] ?? '');
            $primerApellido = trim($input['primer_apellido'] ?? $input['apellidos'] ?? '');
            $cargo = trim($input['cargo'] ?? '');
            $gerenciaNom = trim($input['gerencia'] ?? '');
            $nac = strtoupper(trim($input['nacionalidad'] ?? 'V'));
            $nac = in_array($nac, ['V', 'E'], true) ? $nac : 'V';
            $segundoNombre = trim($input['segundo_nombre'] ?? '') ?: null;
            $segundoApellido = trim($input['segundo_apellido'] ?? '') ?: null;
            $fechaIngreso = trim($input['fecha_ingreso'] ?? '');
            $email = trim($input['email'] ?? '') ?: null;

            if (!$cedula || strlen($cedula) < 5 || strlen($cedula) > 10)
                sendResponse(false, 'La cédula debe contener entre 5 y 10 dígitos numéricos.', null, 400);
            if (!preg_match('/^[0-9]+$/', $cedula))
                sendResponse(false, 'La cédula debe contener SOLO dígitos (0-9).', null, 400);
            if (!$primerNombre || !$primerApellido || !$cargo || !$gerenciaNom)
                sendResponse(false, 'Campos obligatorios incompletos.', null, 400);
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL))
                sendResponse(false, 'Formato de correo electrónico inválido.', null, 400);

            $check = $db->prepare("SELECT id FROM empleados WHERE cedula = ? LIMIT 1");
            $check->execute([$cedula]);
            if ($check->fetchColumn())
                sendResponse(false, "Ya existe un empleado registrado con la cédula {$nac}-{$cedula}.", null, 409);

            $gStmt = $db->prepare("SELECT id FROM gerencias WHERE nombre = ? LIMIT 1");
            $gStmt->execute([$gerenciaNom]);
            $gerenciaId = $gStmt->fetchColumn();
            if (!$gerenciaId) {
                $db->prepare("INSERT INTO gerencias (nombre) VALUES (?)")->execute([$gerenciaNom]);
                $gerenciaId = $db->lastInsertId();
            }

            $fechaFinal = $fechaIngreso && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaIngreso) ? $fechaIngreso : date('Y-m-d');

            // --- CORRECCIÓN CRÍTICA DE ALINEACIÓN ---
            // 10 campos a insertar y 10 valores ejecutados en el array (nacionalidad, cedula, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido, cargo, email, gerencia_id, fecha_ingreso)
            $stmt = $db->prepare("INSERT INTO empleados
                (nacionalidad, cedula, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido, cargo, email, gerencia_id, fecha_ingreso, estado_laboral, estado_carnet)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Activo', 'Pendiente por Imprimir')");
            $stmt->execute([$nac, $cedula, $primerNombre, $segundoNombre, $primerApellido, $segundoApellido, $cargo, $email, $gerenciaId, $fechaFinal]);
            $newId = $db->lastInsertId();

            logAction($db, $userId, 'EMPLEADO_CREADO', [
                'empleado_id' => $newId,
                'cedula' => "{$nac}-{$cedula}",
                'nombre' => "{$primerApellido}, {$primerNombre}",
                'cargo' => $cargo,
                'gerencia' => $gerenciaNom,
            ]);

            http_response_code(201);
            sendResponse(true, 'Empleado registrado exitosamente.', ['id' => $newId]);
            break;

        case 'DELETE':
            $id = isset($_GET['id']) ? intval($_GET['id']) : null;
            if (!$id)
                sendResponse(false, 'ID de empleado no proporcionado.', null, 400);
            $empStmt = $db->prepare("SELECT cedula, primer_nombre, primer_apellido, foto_url FROM empleados WHERE id = ? LIMIT 1");
            $empStmt->execute([$id]);
            $empData = $empStmt->fetch();

            // Antes se respondía "eliminado correctamente" aunque el id no existiera.
            if (!$empData)
                sendResponse(false, 'Empleado no encontrado.', null, 404);

            $db->prepare("DELETE FROM empleados WHERE id = ?")->execute([$id]);

            // La fotografía vive en el sistema de archivos, no en la BD: sin este borrado
            // quedaba accesible por URL indefinidamente tras eliminar al funcionario.
            // basename() impide que un foto_url manipulado escape del directorio uploads/.
            if (!empty($empData['foto_url'])) {
                $rutaFoto = __DIR__ . '/../uploads/' . basename($empData['foto_url']);
                if (is_file($rutaFoto)) {
                    @unlink($rutaFoto);
                }
            }

            logAction($db, $userId, 'EMPLEADO_ELIMINADO', [
                'empleado_id' => $id,
                'cedula' => $empData['cedula'] ?? 'N/A',
                'nombre' => ($empData['primer_apellido'] ?? '') . ', ' . ($empData['primer_nombre'] ?? ''),
                'foto_eliminada' => !empty($empData['foto_url']),
            ]);
            sendResponse(true, 'Empleado eliminado correctamente.');
            break;

        default:
            sendResponse(false, 'Método HTTP no permitido.', null, 405);
    }
} catch (Exception $e) {
    error_log('[SCI-TSS employees.php] ' . $e->getMessage());
    sendResponse(false, 'Error interno del servidor. Contacte al administrador.', null, 500);
}