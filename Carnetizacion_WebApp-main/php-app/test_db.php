<?php
/**
 * test_db.php — Script de prueba de conexión a Supabase
 */
require_once __DIR__ . '/includes/db.php';

echo "<h1>Probando conexión a Supabase</h1>";

try {
    // 1. Probar conexión básica
    echo "<p>✅ Conexión PDO establecida satisfactoriamente.</p>";

    // 2. Probar consulta a la tabla users
    $stmt = $pdo->query("SELECT count(*) FROM users");
    $count = $stmt->fetchColumn();
    echo "<p>✅ Consulta exitosa. Usuarios registrados en la tabla 'users': <strong>$count</strong></p>";

    // 3. Probar consulta a la tabla gerencias
    $stmt = $pdo->query("SELECT count(*) FROM gerencias");
    $countG = $stmt->fetchColumn();
    echo "<p>✅ Consulta exitosa. Gerencias registradas: <strong>$countG</strong></p>";

    echo "<hr><p style='color: green;'><strong>¡Todo listo! El sistema ya está operando sobre PostgreSQL en la nube de Supabase.</strong></p>";
    echo "<a href='login.html'>Ir al Login</a>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Error en la prueba:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    echo "<p>Asegúrate de haber puesto la contraseña correcta en <strong>includes/db.php</strong>.</p>";
}
