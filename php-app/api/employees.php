<?php
/**
 * api/employees.php — CRUD de Empleados (MySQL / InnoDB)
 * =========================================================
 * Sistema de Carnetización Inteligente (SCI-TSS)
 * Esquema: carnetizacion_tss
 *
 * REFACTORIZACIÓN v2.0:
 *  - Migrado de PostgreSQL a MySQL (InnoDB).
 *  - Campos ajustados al nuevo esquema:
 *      cedula (solo numérico), primer_nombre, segundo_nombre,
 *      primer_apellido, segundo_apellido, estado_carnet, foto_url, foto_ruta.
 *  - Búsqueda en campos disgregados (primer_nombre, primer_apellido, cedula).
 *  - Validación de cédula: solo dígitos, regex '^[0-9]+$'.
 *  - Registro en auditoria_logs para operaciones CRUD sensibles.
 *  - Respuestas JSON uniformes vía sendResponse().
 *
 * SEGURIDAD:
 *  - Prepared statements PDO nativos (sin emulación) → previene SQL Injection.
 *  - Validación de tipos y longitudes antes de INSERT/UPDATE.
 *  - Whitelist de estados para filtros → previene filtros maliciosos.
 *  - ON DELETE SET NULL en gerencia_id → integridad referencial preservada.
 *
 * ENDPOINTS:
 *  GET  api/employees.php                  → Lista paginada con filtros
 *  GET  api/employees.php?id={n}           → Empleado individual
 *  POST api/employees.php                  → Crear / Actualizar empleado
 *  DELETE api/employees.php?id={n}         → Eliminar empleado
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/db_mysql.php';   // ← Conexión MySQL
require_once __DIR__ . '/../api/middleware/auth_check.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$userId = $_SESSION['user_id'] ?? null;

// ── Whitelist de estados válidos ──────────────────────────────
const ESTADOS_VALIDOS = ['Pendiente por Imprimir', 'Carnet Impreso', 'Carnet Entregado'];
const FORMAS_ENTREGA = ['', 'Manual', 'Digital'];
const CAMPOS_EDITABLES = [
    'primer_nombre',
    'segundo_nombre',
    'primer_apellido',
    'segundo_apellido',
    'cargo',
    'estado_laboral',
    'forma_entrega',
    'nivel_permiso',
];

try {

    switch ($method) {

        // ════════════════════════════════════════════════════════
        // GET — Lista paginada o empleado individual
        // ════════════════════════════════════════════════════════
        case 'GET':
            $id = isset($_GET['id']) ? intval($_GET['id']) : null;

            if ($id) {
                // ── Empleado individual ──────────────────────────────
                $sql = "SELECT
                            e.*,
                            g.nombre AS gerencia,
                            e.foto_url AS photo_url
                        FROM empleados e
                        LEFT JOIN gerencias g ON e.gerencia_id = g.id
                        WHERE e.id = ?
                        LIMIT 1";
                $stmt = $db->prepare($sql);
                $stmt->execute([$id]);
                $emp = $stmt->fetch();

                if ($emp) {
                    sendResponse(true, 'Empleado encontrado.', [
                        'data' => [$emp],
                        'meta' => ['totalRecords' => 1, 'currentPage' => 1, 'totalPages' => 1, 'limit' => 1],
                    ]);
                } else {
                    sendResponse(false, 'Empleado no encontrado.', null, 404);
                }
            }

            // ── Lista paginada ───────────────────────────────────────
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = min(200, max(1, intval($_GET['limit'] ?? 50)));
            $search = trim($_GET['search'] ?? '');
            $status = trim($_GET['status'] ?? '');
            $offset = ($page - 1) * $limit;

            // Validar status contra whitelist
            if ($status !== '' && !in_array($status, ESTADOS_VALIDOS, true)) {
                $status = ''; // Ignorar valores no válidos
            }

            // ── Construcción del WHERE dinámico ──────────────────────
            $conditions = [];
            $params = [];

            if ($search !== '') {
                // Búsqueda en campos disgregados (primer_nombre, primer_apellido, cedula)
                // MySQL usa LIKE en lugar de ILIKE (PostgreSQL)
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

            // ── Conteo total (para paginación) ───────────────────────
            $cStmt = $db->prepare("SELECT COUNT(*) FROM empleados e {$where}");
            $cStmt->execute($params);
            $total = (int) $cStmt->fetchColumn();
            $totalPages = (int) ceil($total / $limit);

            // ── Consulta principal ───────────────────────────────────
            $dStmt = $db->prepare("
                SELECT
                    e.*,
                    g.nombre  AS gerencia,
                    e.foto_url AS photo_url
                FROM empleados e
                LEFT JOIN gerencias g ON e.gerencia_id = g.id
                {$where}
                ORDER BY e.creado_el DESC
                LIMIT ? OFFSET ?
            ");

            // Bind params de filtro + paginación
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

        // ════════════════════════════════════════════════════════
        // POST — Crear o Actualizar empleado
        // ════════════════════════════════════════════════════════
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = isset($input['id']) ? intval($input['id']) : null;
            $action = trim($input['action'] ?? '');

            // ── Acciones especiales ──────────────────────────────────
            if ($action === 'upload_payroll') {
                $rows = $input['rows'] ?? [];
                $added = 0;
                $db->beginTransaction();
                try {
                    $ins = $db->prepare("
                        INSERT IGNORE INTO empleados
                            (nacionalidad, cedula, primer_nombre, segundo_nombre,
                             primer_apellido, segundo_apellido, cargo, gerencia_id,
                             fecha_ingreso, estado_carnet)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, 'Pendiente por Imprimir')
                    ");

                    foreach ($rows as $r) {
                        $ced = preg_replace('/[^0-9]/', '', $r['Cédula'] ?? $r['cedula'] ?? '');
                        if (strlen($ced) < 5)
                            continue;

                        $nac = 'V';
                        if (isset($r['Nacionalidad'])) {
                            $nac = strtoupper(trim($r['Nacionalidad'])) === 'E' ? 'E' : 'V';
                        }

                        // Resolución de gerencia por nombre
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
                        if ($db->lastInsertId())
                            $added++;
                    }
                    $db->commit();
                    logAction($db, $userId, 'NOMINA_IMPORTADA', ['filas' => count($rows), 'registrados' => $added]);
                    sendResponse(true, $added > 0
                        ? "Nómina importada: {$added} empleado(s) registrado(s)."
                        : 'No se importaron empleados (cédulas duplicadas o datos incompletos).');
                } catch (Exception $ex) {
                    $db->rollBack();
                    sendResponse(false, 'Error al importar nómina: ' . $ex->getMessage(), null, 500);
                }
                break;
            }

            if ($action === 'auto_match') {
                // Placeholder: lógica de Auto-Match (a implementar en fase siguiente)
                logAction($db, $userId, 'AUTO_MATCH_EJECUTADO', ['modo' => 'placeholder']);
                sendResponse(true, 'Auto-Match ejecutado. Sin cambios aplicados en esta versión.');
                break;
            }

            // ── Actualización parcial (PATCH semántico sobre POST) ──
            if ($id) {
                $setClauses = [];
                $values = [];

                // Campos básicos editables (whitelist)
                foreach (CAMPOS_EDITABLES as $campo) {
                    if (array_key_exists($campo, $input)) {
                        $setClauses[] = "{$campo} = ?";
                        $values[] = $input[$campo] ?? null;
                    }
                }

                // estado_carnet (acepta tanto 'estado_carnet' como 'status' para compatibilidad)
                $nuevoEstado = $input['estado_carnet'] ?? $input['status'] ?? null;
                if ($nuevoEstado !== null && in_array($nuevoEstado, ESTADOS_VALIDOS, true)) {
                    $setClauses[] = "estado_carnet = ?";
                    $values[] = $nuevoEstado;
                }

                // forma_entrega
                if (array_key_exists('forma_entrega', $input)) {
                    $forma = $input['forma_entrega'];
                    if ($forma === '' || in_array($forma, FORMAS_ENTREGA, true)) {
                        $setClauses[] = "forma_entrega = ?";
                        $values[] = $forma ?: null;
                    }
                }

                // Gerencia por nombre → resolver a ID
                if (array_key_exists('gerencia', $input) && $input['gerencia']) {
                    $gStmt = $db->prepare("SELECT id FROM gerencias WHERE nombre = ? LIMIT 1");
                    $gStmt->execute([trim($input['gerencia'])]);
                    $gId = $gStmt->fetchColumn();
                    if ($gId) {
                        $setClauses[] = "gerencia_id = ?";
                        $values[] = $gId;
                    }
                }

                // Foto (base64 data URL o URL HTTP)
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

            // ── Creación de nuevo empleado ───────────────────────────
            // Extraer y validar campos obligatorios
            $cedula = preg_replace('/[^0-9]/', '', trim($input['cedula'] ?? ''));
            $primerNombre = trim($input['primer_nombre'] ?? $input['nombres'] ?? '');
            $primerApellido = trim($input['primer_apellido'] ?? $input['apellidos'] ?? '');
            $cargo = trim($input['cargo'] ?? '');
            $gerenciaNom = trim($input['gerencia'] ?? '');
            $nac = strtoupper(trim($input['nacionalidad'] ?? 'V'));
            $nac = in_array($nac, ['V', 'E'], true) ? $nac : 'V';

            // Campos opcionales
            $segundoNombre = trim($input['segundo_nombre'] ?? '') ?: null;
            $segundoApellido = trim($input['segundo_apellido'] ?? '') ?: null;
            $fechaIngreso = trim($input['fecha_ingreso'] ?? '');
            $nivelPermiso = trim($input['nivel_permiso'] ?? 'Nivel 1');

            // ── Validaciones ─────────────────────────────────────────
            if (!$cedula || strlen($cedula) < 5 || strlen($cedula) > 10) {
                sendResponse(false, 'La cédula debe contener entre 5 y 10 dígitos numéricos.', null, 400);
                break;
            }
            if (!preg_match('/^[0-9]+$/', $cedula)) {
                sendResponse(false, 'La cédula debe contener SOLO dígitos (0-9). No incluya prefijos V- o E-.', null, 400);
                break;
            }
            if (!$primerNombre || !$primerApellido || !$cargo || !$gerenciaNom) {
                sendResponse(false, 'Campos obligatorios incompletos: primer nombre, primer apellido, cargo y gerencia.', null, 400);
                break;
            }

            // Validar que la cédula no exista
            $check = $db->prepare("SELECT id FROM empleados WHERE cedula = ? LIMIT 1");
            $check->execute([$cedula]);
            if ($check->fetchColumn()) {
                sendResponse(false, "Ya existe un empleado registrado con la cédula {$nac}-{$cedula}.", null, 409);
                break;
            }

            // Resolver gerencia por nombre (auto-crear si no existe)
            $gStmt = $db->prepare("SELECT id FROM gerencias WHERE nombre = ? LIMIT 1");
            $gStmt->execute([$gerenciaNom]);
            $gerenciaId = $gStmt->fetchColumn();

            if (!$gerenciaId) {
                $db->prepare("INSERT INTO gerencias (nombre) VALUES (?)")->execute([$gerenciaNom]);
                $gerenciaId = $db->lastInsertId();
            }

            // Fecha de ingreso
            $fechaFinal = $fechaIngreso && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaIngreso)
                ? $fechaIngreso
                : date('Y-m-d');

            // INSERT
            $stmt = $db->prepare("
                INSERT INTO empleados
                    (nacionalidad, cedula, primer_nombre, segundo_nombre,
                     primer_apellido, segundo_apellido, cargo, gerencia_id,
                     fecha_ingreso, estado_laboral, estado_carnet, nivel_permiso)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Activo', 'Pendiente por Imprimir', ?)
            ");
            $stmt->execute([
                $nac,
                $cedula,
                $primerNombre,
                $segundoNombre,
                $primerApellido,
                $segundoApellido,
                $cargo,
                $gerenciaId,
                $fechaFinal,
                $nivelPermiso,
            ]);
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

        // ════════════════════════════════════════════════════════
        // DELETE — Eliminar empleado
        // ════════════════════════════════════════════════════════
        case 'DELETE':
            $id = isset($_GET['id']) ? intval($_GET['id']) : null;
            if (!$id) {
                sendResponse(false, 'ID de empleado no proporcionado.', null, 400);
                break;
            }

            // Obtener datos del empleado antes de eliminar (para log)
            $empStmt = $db->prepare("SELECT cedula, primer_nombre, primer_apellido FROM empleados WHERE id = ? LIMIT 1");
            $empStmt->execute([$id]);
            $empData = $empStmt->fetch();

            $db->prepare("DELETE FROM empleados WHERE id = ?")->execute([$id]);

            logAction($db, $userId, 'EMPLEADO_ELIMINADO', [
                'empleado_id' => $id,
                'cedula' => $empData['cedula'] ?? 'N/A',
                'nombre' => ($empData['primer_apellido'] ?? '') . ', ' . ($empData['primer_nombre'] ?? ''),
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
