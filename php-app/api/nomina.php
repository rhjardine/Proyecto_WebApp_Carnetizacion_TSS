<?php
/**
 * api/nomina.php — Importación de nómina en tres pasos.
 * =============================================================================
 * A diferencia de la importación anterior (que escribía en `empleados` en el
 * mismo instante en que el operador elegía el archivo), aquí el archivo se
 * analiza y se previsualiza ANTES de tocar la base:
 *
 *   1. POST action=analizar   (multipart) → guarda el archivo, lo lee, clasifica
 *                                            cada fila y devuelve la vista previa.
 *   2. POST action=confirmar  {id, mapeo} → aplica los cambios en una transacción.
 *   3. POST action=descartar  {id}        → cancela sin escribir nada.
 *   4. GET  ?id=N                          → recupera una vista previa guardada.
 *   5. GET                                 → historial de importaciones.
 *
 * El archivo original queda almacenado y cada fila deja registro de qué empleado
 * originó, de modo que una auditoría pueda rastrear el origen de cualquier carnet.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/middleware/auth_check.php';
require_once __DIR__ . '/lib/NominaParser.php';
require_once __DIR__ . '/lib/NominaMapper.php';

$db = getDB();
$metodo = strtoupper($_SERVER['REQUEST_METHOD']);
$userId = $_SESSION['user_id'] ?? null;

/** Carpeta de archivos cargados; Apache la bloquea vía storage/.htaccess. */
const DIR_NOMINA = __DIR__ . '/../storage/nomina';

/** Tope de tamaño del archivo cargado. */
const MAX_BYTES_NOMINA = 10 * 1024 * 1024;

/** Filas que se envían al navegador en la vista previa. */
const FILAS_VISTA_PREVIA = 50;

try {
    if ($metodo === 'GET') {
        Security::requirePermission($db, 'carnet.view_all');
        session_write_close(); // lectura pura: no retener el bloqueo de sesión

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            sendResponse(true, 'Vista previa.', obtenerVistaPrevia($db, $id));
        }
        sendResponse(true, 'Historial de importaciones.', ['data' => listarHistorial($db)]);
    }

    if ($metodo !== 'POST') {
        sendResponse(false, 'Método no permitido.', null, 405);
    }

    // Con multipart la acción viaja en $_POST; con JSON, en el cuerpo.
    $cuerpo = [];
    if (empty($_POST)) {
        $cuerpo = json_decode(file_get_contents('php://input'), true) ?? [];
    }
    $accion = $_POST['action'] ?? $cuerpo['action'] ?? '';

    Security::requirePermission($db, 'nomina.import');

    match ($accion) {
        'analizar' => analizar($db, $userId),
        'confirmar' => confirmar($db, $userId, (int) ($cuerpo['id'] ?? 0), $cuerpo['mapeo'] ?? null),
        'descartar' => descartar($db, $userId, (int) ($cuerpo['id'] ?? 0)),
        default => sendResponse(false, 'Acción no reconocida. Use analizar, confirmar o descartar.', null, 400),
    };
} catch (NominaParserException $e) {
    // Error atribuible al archivo: el mensaje es útil para el operador.
    sendResponse(false, $e->getMessage(), null, 422);
} catch (Throwable $e) {
    error_log('[SCI-TSS nomina.php] ' . $e->getMessage());
    sendResponse(false, 'Error interno al procesar la nómina. Revise los registros del servidor.', null, 500);
}

// ─────────────────────────────────────────────────────────────────────────────
// PASO 1 — Analizar
// ─────────────────────────────────────────────────────────────────────────────

function analizar(PDO $db, ?int $userId): void
{
    if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        $codigo = $_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE;
        sendResponse(false, mensajeErrorCarga($codigo), null, 400);
    }

    $tmp = $_FILES['archivo']['tmp_name'];
    $nombreOriginal = $_FILES['archivo']['name'];
    $tamano = (int) $_FILES['archivo']['size'];

    if ($tamano > MAX_BYTES_NOMINA) {
        sendResponse(false, 'El archivo supera el límite de 10 MB.', null, 400);
    }

    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    if (!in_array($ext, NominaParser::FORMATOS, true)) {
        sendResponse(false, 'Formato no soportado. Use .xlsx, .csv o .docx.', null, 415);
    }

    // Se lee ANTES de guardar: si el archivo no sirve, no se deja basura en disco.
    $resultado = NominaParser::parsear($tmp, $nombreOriginal);
    $encabezados = $resultado['encabezados'];
    $filas = $resultado['filas'];

    if (!$filas) {
        sendResponse(false, 'El archivo no contiene ninguna fila de datos bajo el encabezado.', null, 422);
    }

    $mapeo = NominaMapper::detectarMapeo($encabezados);
    $faltantes = NominaMapper::faltantes($mapeo);

    // Se persiste el archivo con nombre no adivinable, conservando la extensión.
    if (!is_dir(DIR_NOMINA)) {
        @mkdir(DIR_NOMINA, 0770, true);
    }
    $hash = hash_file('sha256', $tmp);
    $nombreGuardado = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $rutaDestino = DIR_NOMINA . '/' . $nombreGuardado;
    if (!@move_uploaded_file($tmp, $rutaDestino) && !@copy($tmp, $rutaDestino)) {
        sendResponse(false, 'No se pudo almacenar el archivo. Verifique permisos de storage/nomina.', null, 500);
    }

    $clasificadas = clasificar($db, $filas, $mapeo);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO nomina_importaciones
                (usuario_id, nombre_archivo, archivo_hash, archivo_ruta, formato, tamano_bytes,
                 estado, mapeo_columnas, encabezados, total_filas, nuevos, actualizados, sin_cambios, invalidos)
             VALUES (?, ?, ?, ?, ?, ?, "analizado", ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId, $nombreOriginal, $hash, $nombreGuardado, $ext, $tamano,
            json_encode($mapeo, JSON_UNESCAPED_UNICODE),
            json_encode($encabezados, JSON_UNESCAPED_UNICODE),
            count($filas),
            $clasificadas['conteo']['nuevo'],
            $clasificadas['conteo']['actualizar'],
            $clasificadas['conteo']['sin_cambios'],
            $clasificadas['conteo']['error'],
        ]);
        $importacionId = (int) $db->lastInsertId();

        $insFila = $db->prepare(
            'INSERT INTO nomina_filas (importacion_id, numero_fila, datos_originales, cedula, accion, motivo_error, empleado_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($clasificadas['filas'] as $f) {
            $insFila->execute([
                $importacionId,
                $f['numero_fila'],
                json_encode($f['originales'], JSON_UNESCAPED_UNICODE),
                $f['cedula'],
                $f['accion'],
                $f['motivo_error'],
                $f['empleado_id'],
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        @unlink($rutaDestino);
        throw $e;
    }

    logAction($db, $userId, 'NOMINA_ANALIZADA', [
        'importacion_id' => $importacionId,
        'archivo' => $nombreOriginal,
        'filas' => count($filas),
    ]);

    sendResponse(true, 'Archivo analizado. Revise la vista previa antes de confirmar.', [
        'importacion_id' => $importacionId,
        'archivo' => $nombreOriginal,
        'formato' => $ext,
        'encabezados' => $encabezados,
        'mapeo' => $mapeo,
        'campos_faltantes' => $faltantes,
        'conteo' => $clasificadas['conteo'],
        'total_filas' => count($filas),
        'vista_previa' => array_slice($clasificadas['filas'], 0, FILAS_VISTA_PREVIA),
    ]);
}

/**
 * Decide qué hacer con cada fila SIN escribir nada.
 * Una cédula repetida dentro del mismo archivo se marca como error: si dos filas
 * distintas dicen cosas distintas del mismo funcionario, la nómina tiene un
 * problema que debe resolver quien la emitió, no el importador.
 */
function clasificar(PDO $db, array $filas, array $mapeo): array
{
    $conteo = ['nuevo' => 0, 'actualizar' => 0, 'sin_cambios' => 0, 'error' => 0];
    $salida = [];
    $vistasEnArchivo = [];

    $buscar = $db->prepare(
        'SELECT e.id, e.cargo, e.gerencia_id, e.estado_laboral, g.nombre AS gerencia
         FROM empleados e LEFT JOIN gerencias g ON g.id = e.gerencia_id
         WHERE e.cedula = ? LIMIT 1'
    );

    foreach ($filas as $fila) {
        $numeroFila = $fila['_fila'] ?? 0;
        $originales = $fila;
        unset($originales['_fila']);

        $extraida = NominaMapper::extraerFila($fila, $mapeo);

        if ($extraida['datos'] === null) {
            $conteo['error']++;
            $salida[] = [
                'numero_fila' => $numeroFila, 'originales' => $originales, 'cedula' => null,
                'accion' => 'error', 'motivo_error' => $extraida['error'], 'empleado_id' => null,
                'datos' => null, 'cambios' => [],
            ];
            continue;
        }

        $datos = $extraida['datos'];
        $cedula = $datos['cedula'];

        if (isset($vistasEnArchivo[$cedula])) {
            $conteo['error']++;
            $salida[] = [
                'numero_fila' => $numeroFila, 'originales' => $originales, 'cedula' => $cedula,
                'accion' => 'error',
                'motivo_error' => 'Cédula repetida en el archivo (ya aparece en la fila ' . $vistasEnArchivo[$cedula] . ')',
                'empleado_id' => null, 'datos' => $datos, 'cambios' => [],
            ];
            continue;
        }
        $vistasEnArchivo[$cedula] = $numeroFila;

        $buscar->execute([$cedula]);
        $existente = $buscar->fetch(PDO::FETCH_ASSOC);

        if (!$existente) {
            $conteo['nuevo']++;
            $salida[] = [
                'numero_fila' => $numeroFila, 'originales' => $originales, 'cedula' => $cedula,
                'accion' => 'nuevo', 'motivo_error' => null, 'empleado_id' => null,
                'datos' => $datos, 'cambios' => [],
            ];
            continue;
        }

        // Sólo se comparan los campos laborales: la foto y el estatus del carnet
        // son trabajo hecho en el sistema y una reimportación no debe pisarlos.
        $cambios = [];
        if ($datos['cargo'] !== '' && $datos['cargo'] !== $existente['cargo']) {
            $cambios['cargo'] = ['antes' => $existente['cargo'], 'despues' => $datos['cargo']];
        }
        if ($datos['gerencia'] !== '' && $datos['gerencia'] !== ($existente['gerencia'] ?? '')) {
            $cambios['gerencia'] = ['antes' => $existente['gerencia'] ?? '—', 'despues' => $datos['gerencia']];
        }

        $accion = $cambios ? 'actualizar' : 'sin_cambios';
        $conteo[$accion]++;
        $salida[] = [
            'numero_fila' => $numeroFila, 'originales' => $originales, 'cedula' => $cedula,
            'accion' => $accion, 'motivo_error' => null, 'empleado_id' => (int) $existente['id'],
            'datos' => $datos, 'cambios' => $cambios,
        ];
    }

    return ['conteo' => $conteo, 'filas' => $salida];
}

// ─────────────────────────────────────────────────────────────────────────────
// PASO 2 — Confirmar
// ─────────────────────────────────────────────────────────────────────────────

function confirmar(PDO $db, ?int $userId, int $importacionId, ?array $mapeoManual): void
{
    if ($importacionId <= 0) {
        sendResponse(false, 'Identificador de importación inválido.', null, 400);
    }

    $stmt = $db->prepare('SELECT * FROM nomina_importaciones WHERE id = ? LIMIT 1');
    $stmt->execute([$importacionId]);
    $imp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$imp) {
        sendResponse(false, 'La importación no existe.', null, 404);
    }
    if ($imp['estado'] !== 'analizado') {
        sendResponse(false, "Esta importación ya fue {$imp['estado']}. Cargue el archivo nuevamente.", null, 409);
    }

    // Se relee el archivo original en vez de confiar en lo previsualizado: así el
    // operador puede corregir el mapeo de columnas y volver a aplicar.
    $ruta = DIR_NOMINA . '/' . basename((string) $imp['archivo_ruta']);
    if (!is_readable($ruta)) {
        sendResponse(false, 'El archivo original ya no está disponible en el servidor.', null, 410);
    }

    $resultado = NominaParser::parsear($ruta, $imp['nombre_archivo']);
    $mapeo = $mapeoManual ?: (json_decode((string) $imp['mapeo_columnas'], true) ?: []);

    $faltantes = NominaMapper::faltantes($mapeo);
    if ($faltantes) {
        sendResponse(false, 'Faltan columnas obligatorias por asignar: ' . implode(', ', $faltantes), null, 400);
    }

    // Se reclasifica contra el estado actual de la base: entre la vista previa y
    // la confirmación pudo registrarse a alguien manualmente.
    $clasificadas = clasificar($db, $resultado['filas'], $mapeo);

    $db->beginTransaction();
    try {
        $insEmpleado = $db->prepare(
            'INSERT INTO empleados
                (nacionalidad, cedula, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido,
                 cargo, email, gerencia_id, fecha_ingreso, estado_laboral, estado_carnet)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Activo", "Pendiente por Imprimir")'
        );
        $updEmpleado = $db->prepare(
            'UPDATE empleados SET cargo = ?, gerencia_id = COALESCE(?, gerencia_id), actualizado_el = NOW()
             WHERE id = ?'
        );

        $db->prepare('DELETE FROM nomina_filas WHERE importacion_id = ?')->execute([$importacionId]);
        $insFila = $db->prepare(
            'INSERT INTO nomina_filas (importacion_id, numero_fila, datos_originales, cedula, accion, motivo_error, empleado_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $creados = 0;
        $modificados = 0;

        foreach ($clasificadas['filas'] as $f) {
            $empleadoId = $f['empleado_id'];

            if ($f['accion'] === 'nuevo') {
                $d = $f['datos'];
                $insEmpleado->execute([
                    $d['nacionalidad'], $d['cedula'], $d['primer_nombre'], $d['segundo_nombre'],
                    $d['primer_apellido'], $d['segundo_apellido'], $d['cargo'], $d['email'],
                    resolverGerencia($db, $d['gerencia']),
                    $d['fecha_ingreso'] ?? date('Y-m-d'),
                ]);
                $empleadoId = (int) $db->lastInsertId();
                $creados++;
            } elseif ($f['accion'] === 'actualizar') {
                $d = $f['datos'];
                $updEmpleado->execute([$d['cargo'], resolverGerencia($db, $d['gerencia']), $empleadoId]);
                $modificados++;
            }

            $insFila->execute([
                $importacionId, $f['numero_fila'],
                json_encode($f['originales'], JSON_UNESCAPED_UNICODE),
                $f['cedula'], $f['accion'], $f['motivo_error'], $empleadoId,
            ]);
        }

        // La condición `estado = "analizado"` va dentro del UPDATE a propósito:
        // la comprobación del principio de la función y este punto están separados
        // por toda la escritura, así que dos confirmaciones simultáneas del mismo
        // archivo podrían pasar ambas por aquel `if` y duplicar los empleados.
        // Aquí sólo una de las dos encuentra la fila en estado "analizado".
        $marcar = $db->prepare(
            'UPDATE nomina_importaciones
             SET estado = "confirmado", confirmado_el = NOW(), mapeo_columnas = ?,
                 total_filas = ?, nuevos = ?, actualizados = ?, sin_cambios = ?, invalidos = ?
             WHERE id = ? AND estado = "analizado"'
        );
        $marcar->execute([
            json_encode($mapeo, JSON_UNESCAPED_UNICODE),
            count($clasificadas['filas']),
            $clasificadas['conteo']['nuevo'],
            $clasificadas['conteo']['actualizar'],
            $clasificadas['conteo']['sin_cambios'],
            $clasificadas['conteo']['error'],
            $importacionId,
        ]);

        if ($marcar->rowCount() === 0) {
            $db->rollBack();
            sendResponse(false, 'Esta importación fue confirmada o descartada por otra sesión. No se aplicó nada.', null, 409);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    logAction($db, $userId, 'NOMINA_CONFIRMADA', [
        'importacion_id' => $importacionId,
        'archivo' => $imp['nombre_archivo'],
        'creados' => $creados,
        'actualizados' => $modificados,
        'invalidos' => $clasificadas['conteo']['error'],
    ]);

    $partes = [];
    if ($creados) $partes[] = "{$creados} registrado(s)";
    if ($modificados) $partes[] = "{$modificados} actualizado(s)";
    if ($clasificadas['conteo']['sin_cambios']) $partes[] = "{$clasificadas['conteo']['sin_cambios']} sin cambios";
    if ($clasificadas['conteo']['error']) $partes[] = "{$clasificadas['conteo']['error']} con error";

    sendResponse(true, 'Importación aplicada: ' . ($partes ? implode(', ', $partes) : 'sin cambios') . '.', [
        'importacion_id' => $importacionId,
        'creados' => $creados,
        'actualizados' => $modificados,
        'conteo' => $clasificadas['conteo'],
    ]);
}

/** Busca la gerencia por nombre; la crea si no existe. Devuelve null si viene vacía. */
function resolverGerencia(PDO $db, ?string $nombre): ?int
{
    $nombre = trim((string) $nombre);
    if ($nombre === '') {
        return null;
    }

    static $cache = [];
    if (isset($cache[$nombre])) {
        return $cache[$nombre];
    }

    $stmt = $db->prepare('SELECT id FROM gerencias WHERE nombre = ? LIMIT 1');
    $stmt->execute([$nombre]);
    $id = $stmt->fetchColumn();

    if (!$id) {
        $db->prepare('INSERT INTO gerencias (nombre) VALUES (?)')->execute([$nombre]);
        $id = (int) $db->lastInsertId();
    }

    return $cache[$nombre] = (int) $id;
}

// ─────────────────────────────────────────────────────────────────────────────
// PASO 3 — Descartar / consultas
// ─────────────────────────────────────────────────────────────────────────────

function descartar(PDO $db, ?int $userId, int $importacionId): void
{
    if ($importacionId <= 0) {
        sendResponse(false, 'Identificador de importación inválido.', null, 400);
    }

    $stmt = $db->prepare('SELECT estado, archivo_ruta, nombre_archivo FROM nomina_importaciones WHERE id = ? LIMIT 1');
    $stmt->execute([$importacionId]);
    $imp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$imp) {
        sendResponse(false, 'La importación no existe.', null, 404);
    }
    if ($imp['estado'] === 'confirmado') {
        sendResponse(false, 'No se puede descartar una importación ya confirmada.', null, 409);
    }

    $db->prepare('UPDATE nomina_importaciones SET estado = "descartado" WHERE id = ?')->execute([$importacionId]);

    // El archivo se elimina: contiene datos personales y ya no se va a aplicar.
    $ruta = DIR_NOMINA . '/' . basename((string) $imp['archivo_ruta']);
    if (is_file($ruta)) {
        @unlink($ruta);
    }

    logAction($db, $userId, 'NOMINA_DESCARTADA', [
        'importacion_id' => $importacionId,
        'archivo' => $imp['nombre_archivo'],
    ]);

    sendResponse(true, 'Importación descartada. No se modificó ningún registro.');
}

function obtenerVistaPrevia(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM nomina_importaciones WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $imp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$imp) {
        sendResponse(false, 'La importación no existe.', null, 404);
    }

    $filas = $db->prepare(
        'SELECT numero_fila, datos_originales, cedula, accion, motivo_error, empleado_id
         FROM nomina_filas WHERE importacion_id = ? ORDER BY numero_fila LIMIT ' . FILAS_VISTA_PREVIA
    );
    $filas->execute([$id]);

    return [
        'importacion' => [
            'id' => (int) $imp['id'],
            'archivo' => $imp['nombre_archivo'],
            'formato' => $imp['formato'],
            'estado' => $imp['estado'],
            'creado_el' => $imp['creado_el'],
            'confirmado_el' => $imp['confirmado_el'],
            'total_filas' => (int) $imp['total_filas'],
        ],
        'encabezados' => json_decode((string) $imp['encabezados'], true) ?: [],
        'mapeo' => json_decode((string) $imp['mapeo_columnas'], true) ?: [],
        'conteo' => [
            'nuevo' => (int) $imp['nuevos'],
            'actualizar' => (int) $imp['actualizados'],
            'sin_cambios' => (int) $imp['sin_cambios'],
            'error' => (int) $imp['invalidos'],
        ],
        'vista_previa' => array_map(static function (array $f): array {
            $f['originales'] = json_decode((string) $f['datos_originales'], true) ?: [];
            unset($f['datos_originales']);
            return $f;
        }, $filas->fetchAll(PDO::FETCH_ASSOC)),
    ];
}

function listarHistorial(PDO $db): array
{
    $stmt = $db->query(
        'SELECT i.id, i.nombre_archivo, i.formato, i.estado, i.total_filas,
                i.nuevos, i.actualizados, i.sin_cambios, i.invalidos,
                i.creado_el, i.confirmado_el, u.usuario AS operador
         FROM nomina_importaciones i
         LEFT JOIN usuarios u ON u.id = i.usuario_id
         ORDER BY i.creado_el DESC
         LIMIT 50'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function mensajeErrorCarga(int $codigo): string
{
    return match ($codigo) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'El archivo supera el tamaño permitido por el servidor (upload_max_filesize en php.ini).',
        UPLOAD_ERR_PARTIAL => 'La carga se interrumpió. Intente nuevamente.',
        UPLOAD_ERR_NO_FILE => 'No se recibió ningún archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene directorio temporal configurado.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo en disco.',
        default => 'Error desconocido al cargar el archivo.',
    };
}
