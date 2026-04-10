<?php
/**
 * ============================================================================
 * Herramienta DevOps: Reparación de Hash de Contraseña para 'admin'
 * ============================================================================
 * Ejecuta este archivo una sola vez en tu navegador para forzar la 
 * actualización de la contraseña del usuario admin al formato Bcrypt correcto.
 */

// 1. Mostrar errores para debug (Solo en pre-producción)
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2>🔧 DevOps: Reparación de cuenta Admin</h2>";

// 2. Incluir la conexión a la base de datos
// Ajusta la ruta dependiendo de qué archivo use actualmente la app refactorizada.
$db_files = [
    'api/config/db.php',
    'includes/db.php',
    'includes/db_mysql.php',
    'Mysql_connect.php'
];

$conexion = null;
foreach ($db_files as $file) {
    if (file_exists($file)) {
        require_once $file;
        echo "<p>✅ Archivo de conexión encontrado: <code>$file</code></p>";
        // Intentar capturar la variable de conexión ($conn, $pdo, $conexion, etc)
        $conexion = isset($conn) ? $conn : (isset($pdo) ? $pdo : (isset($mysqli) ? $mysqli : null));
        break;
    }
}

if (!$conexion) {
    die("<p style='color:red;'>❌ No se pudo establecer/encontrar la variable de conexión a la base de datos. Verifica cómo se llama tu variable en db.php.</p>");
}

// 3. Generar el Hash seguro
$plain_password = 'admin123';
$hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);

// 4. Actualizar la base de datos (Soporta PDO y MySQLi)
try {
    if ($conexion instanceof PDO) {
        $stmt = $conexion->prepare("UPDATE users SET password = :hash WHERE username = 'admin' OR email = 'admin@example.com'");
        $stmt->execute([':hash' => $hashed_password]);
        $filas_afectadas = $stmt->rowCount();
        
    } elseif ($conexion instanceof mysqli) {
        $stmt = $conexion->prepare("UPDATE users SET password = ? WHERE username = 'admin' OR email = 'admin@example.com'");
        $stmt->bind_param("s", $hashed_password);
        $stmt->execute();
        $filas_afectadas = $stmt->affected_rows;
    } else {
        die("<p style='color:red;'>❌ Tipo de conexión de base de datos no reconocido.</p>");
    }

    if ($filas_afectadas > 0) {
        echo "<p style='color:green;'>✅ <b>¡Éxito!</b> La contraseña del usuario 'admin' ha sido actualizada y encriptada correctamente.</p>";
        echo "<p>El nuevo hash es: <br><code style='background:#eee;padding:4px;'>$hashed_password</code></p>";
        echo "<p>Ya puedes ir a <a href='login.html'>login.html</a> e iniciar sesión con <b>admin123</b>.</p>";
        echo "<p style='color:orange;'>⚠️ <b>IMPORTANTE:</b> Elimina este archivo (<code>fix_admin.php</code>) por seguridad antes de pasar a producción.</p>";
    } else {
        echo "<p style='color:orange;'>⚠️ No se actualizaron filas. Es posible que el usuario 'admin' no exista en la tabla <code>users</code>. Revisa si la tabla está vacía.</p>";
    }

} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Error en la base de datos: " . $e->getMessage() . "</p>";
}
?>