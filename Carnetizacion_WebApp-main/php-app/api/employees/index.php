<?php
/**
 * /api/employees/index.php
 *
 * GET  — Lista empleados con paginación del lado del servidor y filtros seguros
 *        Params: ?page=1&limit=50&search=termino&status=Verificado
 * POST — Crea un nuevo empleado
 *
 * SECURITY: Todos los filtros usan parámetros enlazados (PDO) → sin SQL Injection.
 */
require_once __DIR__ . '/../../middleware/auth_check.php';

$method = $_SERVER['REQUEST_METHOD'];

// ==============================================================
// GET — List employees (paginated + filtered)
// ==============================================================
if ($method === 'GET') {

    // --- Parámetros de paginación con valores por defecto seguros ---
    $page   = max(1, intval($_GET['page']  ?? 1));
    $limit  = min(200, max(1, intval($_GET['limit'] ?? 50))); // máx 200 filas por página
    $offset = ($page - 1) * $limit;

    // --- Parámetros de filtro ---
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? '');

    // Valores válidos para status (whitelist → no hay inyección posible)
    $allowedStatuses = ['Pendiente', 'Verificado', 'Impreso', 'Rechazado'];
    if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
        $status = ''; // ignorar valores no válidos
    }

    // --- Construcción dinámica del WHERE seguro ---
    $conditions = [];
    $params     = [];

    if ($search !== '') {
        // ILIKE en PostgreSQL → búsqueda case-insensitive
        $conditions[] = "(nombre ILIKE :search OR cedula ILIKE :search)";
        $params[':search'] = '%' . addcslashes($search, '%_\\') . '%';
    }

    if ($status !== '') {
        $conditions[] = "status = :status";
        $params[':status'] = $status;
    }

    $whereClause = count($conditions) > 0
        ? 'WHERE ' . implode(' AND ', $conditions)
        : '';

    try {
        $db = getDB();

        // --- Consulta de conteo total (para calcular páginas) ---
        $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM employees {$whereClause}");
        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();
        $totalPages   = (int) ceil($totalRecords / $limit);

        // --- Consulta principal con LIMIT/OFFSET ---
        $dataStmt = $db->prepare("
            SELECT
                id, cedula, nombre, cargo, departamento,
                tipo_sangre, nss, fecha_ingreso, photo_url,
                status, created_at, updated_at
            FROM employees
            {$whereClause}
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");

        // Vincular params de filtro
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        // Vincular paginación como enteros
        $dataStmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $dataStmt->execute();
        $employees = $dataStmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data'    => $employees,
            'meta'    => [
                'totalRecords' => $totalRecords,
                'currentPage'  => $page,
                'totalPages'   => $totalPages,
                'limit'        => $limit,
                'search'       => $search,
                'status'       => $status,
            ],
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error al obtener empleados.']);
    }
    exit;
}

// ==============================================================
// POST — Create employee
// ==============================================================
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $cedula       = trim($body['cedula']       ?? '');
    $nombre       = trim($body['nombre']       ?? '');
    $cargo        = trim($body['cargo']        ?? '');
    $departamento = trim($body['departamento'] ?? '');
    $tipo_sangre  = trim($body['tipo_sangre']  ?? '');
    $nss          = trim($body['nss']          ?? '');

    if (empty($cedula) || empty($nombre) || empty($cargo) || empty($departamento)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Campos obligatorios faltantes: cedula, nombre, cargo, departamento.']);
        exit;
    }

    try {
        $db = getDB();

        $check = $db->prepare('SELECT id FROM employees WHERE cedula = :cedula');
        $check->execute([':cedula' => $cedula]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Ya existe un empleado con esa cédula.']);
            exit;
        }

        $stmt = $db->prepare('
            INSERT INTO employees (cedula, nombre, cargo, departamento, tipo_sangre, nss)
            VALUES (:cedula, :nombre, :cargo, :departamento, :tipo_sangre, :nss)
            RETURNING *
        ');
        $stmt->execute([
            ':cedula'       => $cedula,
            ':nombre'       => $nombre,
            ':cargo'        => $cargo,
            ':departamento' => $departamento,
            ':tipo_sangre'  => $tipo_sangre ?: null,
            ':nss'          => $nss ?: null,
        ]);
        $employee = $stmt->fetch();

        // ── AUDIT LOG ──────────────────────────────────────────
        logAction($db, $authUser['id'], 'EMPLOYEE_CREATED', [
            'employee_id' => $employee['id'],
            'cedula'      => $employee['cedula'],
            'nombre'      => $employee['nombre'],
            'cargo'       => $employee['cargo'],
            'departamento'=> $employee['departamento'],
        ]);
        // ───────────────────────────────────────────────────────

        http_response_code(201);
        echo json_encode(['success' => true, 'data' => $employee]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error al crear el empleado.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
