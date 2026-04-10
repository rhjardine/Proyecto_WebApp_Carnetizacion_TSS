<?php
/**
 * test_db.php — Verificación de conexión y esquema SCI-TSS v2.2
 * ==============================================================
 * Devuelve JSON con:
 *  - success: true/false
 *  - count: número de usuarios
 *  - columns: columnas de la tabla usuarios
 *  - admin_hash_type: tipo de hash del usuario admin (bcrypt/plain/none)
 *  - tables: estado de cada tabla requerida
 *
 * URL: http://localhost/.../php-app/test_db.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 0); // Solo JSON en la salida
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/includes/db_mysql.php';
    $db = getDB();

    // ── 1. Verificar tablas ──────────────────────────────────
    $tablasRequeridas = ['gerencias', 'usuarios', 'empleados', 'auditoria_logs'];
    $tablas = [];
    foreach ($tablasRequeridas as $tabla) {
        try {
            $count = $db->query("SELECT COUNT(*) FROM `{$tabla}`")->fetchColumn();
            $tablas[$tabla] = ['exists' => true, 'count' => (int) $count];
        } catch (Exception $e) {
            $tablas[$tabla] = ['exists' => false, 'error' => $e->getMessage()];
        }
    }

    // ── 2. Verificar columnas de la tabla usuarios ───────────
    $columnasRequeridas = ['id', 'usuario', 'clave_hash', 'nombre_completo', 'rol', 'rol_temporal', 'bloqueado', 'intentos_fallidos'];
    $columnasActuales = [];
    $columnasFaltantes = [];

    try {
        $cols = $db->query("SHOW COLUMNS FROM usuarios")->fetchAll();
        $columnasActuales = array_column($cols, 'Field');

        foreach ($columnasRequeridas as $col) {
            if (!in_array($col, $columnasActuales)) {
                $columnasFaltantes[] = $col;
            }
        }
    } catch (Exception $e) {
        $columnasFaltantes = $columnasRequeridas;
    }

    // ── 3. Verificar hash del admin ──────────────────────────
    $adminHashType = 'none';
    $adminBloqueado = false;
    try {
        $stmt = $db->prepare("SELECT clave_hash, bloqueado FROM usuarios WHERE usuario = ? LIMIT 1");
        $stmt->execute(['admin']);
        $admin = $stmt->fetch();
        if ($admin) {
            $hash = $admin['clave_hash'];
            $adminBloqueado = (bool) $admin['bloqueado'];
            if (strlen($hash) >= 60 && str_starts_with($hash, '$2')) {
                $adminHashType = 'bcrypt';
                // Verificar que el hash corresponde a admin123
                if (password_verify('admin123', $hash)) {
                    $adminHashType = 'bcrypt_verified';
                } else {
                    $adminHashType = 'bcrypt_mismatch';
                }
            } elseif ($hash === 'admin123') {
                $adminHashType = 'plain_text';
            } else {
                $adminHashType = 'unknown';
            }
        }
    } catch (Exception $e) {
        $adminHashType = 'error: ' . $e->getMessage();
    }

    // ── 4. Contar usuarios ───────────────────────────────────
    $userCount = $tablas['usuarios']['count'] ?? 0;

    // ── Resultado final ──────────────────────────────────────
    $allTablesOk = true;
    foreach ($tablas as $t) {
        if (!$t['exists']) {
            $allTablesOk = false;
            break;
        }
    }
    $columnsOk = empty($columnasFaltantes);
    $hashOk = in_array($adminHashType, ['bcrypt_verified', 'plain_text']);

    echo json_encode([
        'success' => $allTablesOk && $columnsOk,
        'count' => $userCount,
        'tables' => $tablas,
        'columns' => $columnasActuales,
        'columns_missing' => $columnasFaltantes,
        'columns_ok' => $columnsOk,
        'admin_hash_type' => $adminHashType,
        'admin_locked' => $adminBloqueado,
        'admin_login_ok' => $hashOk,
        'version' => '2.2.0',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}