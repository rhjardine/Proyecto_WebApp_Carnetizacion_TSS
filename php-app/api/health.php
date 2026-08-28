<?php
/**
 * api/health.php — Diagnóstico de despliegue SCI-TSS
 * ==============================================================================
 * Responde a la pregunta que ni el login ni los logs de Apache dejan clara:
 * ¿el archivo .env se está leyendo, y la conexión a MySQL funciona?
 *
 * Sin este endpoint, un .env guardado como ".env.txt" o ubicado fuera de php-app/
 * produce exactamente el mismo "Access denied" que una contraseña equivocada,
 * porque el cargador cae en silencio a los valores por defecto (root / sin clave).
 *
 * Uso:  http://localhost/sci-tss/api/health.php
 *
 * SEGURIDAD:
 *   - DB_PASS nunca se expone (ni su valor, ni su longitud, ni un hash).
 *   - Con APP_ENV=production el detalle se omite por completo.
 */

// Se cargan sólo entorno y helpers: deliberadamente NO se incluye bootstrap.php, para que el
// diagnóstico siga respondiendo aunque la sesión o el guardián CSRF estén mal configurados.
require_once __DIR__ . '/config/config_fixed.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// En producción no se publica topología ni credenciales de infraestructura.
if (!APP_DEBUG) {
    echo json_encode(['success' => true, 'status' => 'ok'], JSON_UNESCAPED_UNICODE);
    exit;
}

$envLoaded = defined('ENV_FILE_LOADED') && ENV_FILE_LOADED;

$report = [
    'success' => true,
    'app' => [
        'nombre' => APP_NAME,
        'version' => APP_VERSION,
        'entorno' => APP_ENV,
        'debug' => APP_DEBUG,
    ],
    'php' => [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'gd' => extension_loaded('gd'),
        'mbstring' => extension_loaded('mbstring'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
    ],
    'env' => [
        'archivo_leido' => $envLoaded,
        'ruta_esperada' => defined('ENV_FILE_PATH') ? ENV_FILE_PATH : null,
    ],
    'db' => [
        'host' => DB_HOST,
        'puerto' => DB_PORT,
        'esquema' => DB_NAME,
        'usuario' => DB_USER,
        // Se informa únicamente SI hay contraseña configurada, jamás cuál es.
        'contrasena_configurada' => (DB_PASS !== ''),
        'conexion' => null,
    ],
    'diagnostico' => [],
];

if (!$envLoaded) {
    $report['diagnostico'][] = 'El archivo .env NO fue leído. Se están usando los valores por defecto '
        . '(usuario "root" sin contraseña). Verifique en una consola cmd, dentro de la carpeta del '
        . 'proyecto, que el archivo se llame exactamente ".env" y no ".env.txt" (comando: dir /a).';
}

if (!extension_loaded('pdo_mysql')) {
    $report['diagnostico'][] = 'La extensión pdo_mysql no está activa en php.ini. La conexión a MySQL '
        . 'es imposible hasta habilitarla y reiniciar Apache.';
}

// Comprobaciones de disco: no dependen de la base de datos, así que se hacen
// aquí y siguen informando aunque MySQL esté caído.
foreach ([
    'uploads' => 'las fotografías de los carnets',
    'storage/nomina' => 'los archivos de nómina importados',
] as $relativa => $proposito) {
    $ruta = dirname(__DIR__) . '/' . $relativa;
    $ok = is_dir($ruta) && is_writable($ruta);
    $report['almacenamiento'][$relativa] = $ok ? 'escribible' : 'NO escribible';
    if (!$ok) {
        $report['diagnostico'][] = "La carpeta {$relativa} no existe o no permite escritura; "
            . "el sistema no podrá guardar {$proposito}.";
    }
}

if (!extension_loaded('zip')) {
    $report['diagnostico'][] = 'La extensión zip no está activa: la importación de nómina no podrá '
        . 'leer archivos .xlsx ni .docx. Habilite extension=zip en php.ini.';
}
$report['php']['zip'] = extension_loaded('zip');

// Prueba de conexión real. No se usa getDB() porque su manejador de error corta la
// ejecución con sendResponse(); aquí interesa capturar el fallo y seguir reportando.
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    $report['db']['conexion'] = 'ok';
    $report['db']['servidor'] = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    // Verificación de migraciones: estas columnas las agregan 02 y 03. Si faltan, el alta
    // de empleados y la recuperación de contraseña fallan en tiempo de ejecución.
    $columnas = [
        'usuarios.email' => ['usuarios', 'email'],
        'usuarios.reset_token_hash' => ['usuarios', 'reset_token_hash'],
        'empleados.email' => ['empleados', 'email'],
    ];
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $faltantes = [];
    foreach ($columnas as $etiqueta => $ref) {
        $stmt->execute([DB_NAME, $ref[0], $ref[1]]);
        $existe = (int) $stmt->fetchColumn() > 0;
        $report['migraciones'][$etiqueta] = $existe;
        if (!$existe) {
            $faltantes[] = $etiqueta;
        }
    }
    if ($faltantes) {
        $report['diagnostico'][] = 'Faltan columnas de migración (' . implode(', ', $faltantes) . '). '
            . 'Importe en phpMyAdmin, en orden: db/01_master_final_spanish.sql, db/02_update_recovery.sql '
            . 'y db/03_update_empleados_email.sql.';
    }

    // La importación de nómina depende de la migración 04; sin ella el módulo
    // responde 403 para todos, porque el permiso 'nomina.import' no existe.
    $tablas = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $tablas->execute([DB_NAME, 'nomina_importaciones']);
    $report['migraciones']['nomina_importaciones'] = (int) $tablas->fetchColumn() > 0;
    if (!$report['migraciones']['nomina_importaciones']) {
        $report['diagnostico'][] = 'Falta la tabla nomina_importaciones: la importación de nómina no funcionará. '
            . 'Importe db/04_nomina_importaciones.sql.';
    }


    // Estado de las cuentas semilla: un administrador bloqueado por intentos fallidos
    // no puede desbloquearse a sí mismo desde la interfaz.
    $bloqueados = $pdo->query(
        "SELECT usuario FROM usuarios WHERE bloqueado = 1 ORDER BY usuario"
    )->fetchAll(PDO::FETCH_COLUMN);
    $report['usuarios_bloqueados'] = $bloqueados;
    if ($bloqueados) {
        $report['diagnostico'][] = 'Cuentas bloqueadas por intentos fallidos: ' . implode(', ', $bloqueados)
            . '. Desbloquee con: UPDATE usuarios SET bloqueado = 0, intentos_fallidos = 0 WHERE usuario = '
            . "'admin';";
    }
} catch (PDOException $e) {
    $report['success'] = false;
    $report['db']['conexion'] = 'error';
    $report['db']['sqlstate'] = $e->getCode();
    $report['db']['detalle'] = $e->getMessage();

    $codigo = (int) (is_array($e->errorInfo) ? ($e->errorInfo[1] ?? 0) : 0);
    if ($codigo === 1045) {
        $report['diagnostico'][] = 'MySQL rechazó las credenciales. Si el .env figura como leído, revise que '
            . 'el valor de DB_PASS no lleve comillas ni comentarios sobrantes; si figura como NO leído, el '
            . 'problema es la ubicación o el nombre del archivo, no la contraseña.';
    } elseif ($codigo === 1049) {
        $report['diagnostico'][] = 'El esquema "' . DB_NAME . '" no existe. Importe db/01_master_final_spanish.sql '
            . 'desde phpMyAdmin.';
    } elseif ($codigo === 2002 || $codigo === 2003) {
        $report['diagnostico'][] = 'No hay servicio MySQL escuchando en ' . DB_HOST . ':' . DB_PORT . '. '
            . 'Inicie MySQL en el panel de XAMPP y confirme el puerto que muestra (puede ser 3307).';
    } else {
        // Cualquier otro código debe informarse igual. Sin esta rama, un error no
        // catalogado dejaba el diagnóstico vacío y más abajo se declaraba
        // "sin incidencias" pese a que la conexión había fallado.
        $report['diagnostico'][] = 'No se pudo conectar a la base de datos (código ' . $codigo . '). '
            . 'Revise el campo "detalle" y confirme que MySQL está iniciado y que las credenciales '
            . 'del archivo .env son correctas.';
    }
}

// El mensaje de "todo correcto" sólo puede emitirse si nada falló: de lo contrario
// la herramienta de diagnóstico daría por bueno un entorno que no lo está.
if (!$report['diagnostico'] && $report['success']) {
    $report['diagnostico'][] = 'Sin incidencias detectadas. El entorno está listo para operar.';
} elseif (!$report['diagnostico']) {
    $report['diagnostico'][] = 'Se detectaron errores. Revise los campos "db" y "migraciones".';
}

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
