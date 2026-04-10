<?php
/**
 * mysql_connect.php — Verificación de conexión MySQL (SCI-TSS)
 * =============================================================
 * Reemplaza pg_connect.php que usaba PostgreSQL/credenciales hardcodeadas.
 * Solo para diagnóstico. NO dejar accesible en producción.
 *
 * URL: http://localhost/sci-tss/mysql_connect.php
 */

// Cargar desde variables de entorno o valores por defecto de XAMPP
$host    = getenv('DB_HOST') ?: 'localhost';
$port    = getenv('DB_PORT') ?: '3306';
$dbname  = getenv('DB_NAME') ?: 'carnetizacion_tss';
$user    = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';  // XAMPP por defecto no tiene contraseña

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    echo "<h2 style='color:green;font-family:monospace;'>✅ Conexión MySQL establecida correctamente</h2>";
    echo "<p>Host: <b>{$host}:{$port}</b> | Base de datos: <b>{$dbname}</b></p>";

    // Verificar tablas del esquema SCI-TSS
    $tablas = ['gerencias', 'usuarios', 'empleados', 'auditoria_logs'];
    echo "<table border='1' cellpadding='6' style='font-family:monospace;border-collapse:collapse;'>";
    echo "<tr><th>Tabla</th><th>Registros</th><th>Estado</th></tr>";
    foreach ($tablas as $tabla) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM `{$tabla}`")->fetchColumn();
            echo "<tr><td>{$tabla}</td><td>{$count}</td><td style='color:green;'>✅ OK</td></tr>";
        } catch (Exception $e) {
            echo "<tr><td>{$tabla}</td><td>—</td><td style='color:red;'>❌ No encontrada (ejecute schema_mysql.sql)</td></tr>";
        }
    }
    echo "</table>";

    // Verificar usuarios para login
    echo "<h3>Usuarios del sistema:</h3>";
    $stmt = $pdo->query("SELECT usuario, nombre_completo, rol, bloqueado, intentos_fallidos FROM usuarios ORDER BY rol");
    $usuarios = $stmt->fetchAll();
    if ($usuarios) {
        echo "<table border='1' cellpadding='6' style='font-family:monospace;border-collapse:collapse;'>";
        echo "<tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Bloqueado</th><th>Intentos</th></tr>";
        foreach ($usuarios as $u) {
            $bloq = $u['bloqueado'] ? '🔒 SÍ' : '✅ NO';
            echo "<tr>
                <td>{$u['usuario']}</td>
                <td>{$u['nombre_completo']}</td>
                <td>{$u['rol']}</td>
                <td>{$bloq}</td>
                <td>{$u['intentos_fallidos']}</td>
            </tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:orange;'>⚠️ No hay usuarios. Ejecute seed_mysql.sql</p>";
    }

} catch (PDOException $e) {
    echo "<h2 style='color:red;font-family:monospace;'>❌ Error de conexión MySQL</h2>";
    echo "<pre style='background:#fee2e2;padding:12px;border-radius:6px;'>";
    echo "Mensaje: " . htmlspecialchars($e->getMessage()) . "\n\n";
    echo "Verifique:\n";
    echo "  1. MySQL está activo en XAMPP Control Panel\n";
    echo "  2. La base de datos 'carnetizacion_tss' existe\n";
    echo "  3. Las credenciales en includes/db_mysql.php son correctas\n";
    echo "  4. Se ejecutaron schema_mysql.sql y seed_mysql.sql\n";
    echo "</pre>";
}
?>
