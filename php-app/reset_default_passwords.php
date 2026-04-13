<?php
/**
 * reset_default_passwords.php
 * ============================
 * Script de recuperación de accesos para entorno pre-productivo SCI-TSS.
 *
 * ACCIÓN: Restablece las contraseñas de todos los usuarios de demostración
 *         a 'admin123' (hash bcrypt válido) y desbloquea sus cuentas.
 *
 * USO:
 *   php php-app/reset_default_passwords.php
 *
 * IMPORTANTE: Eliminar este script antes del pase a producción final.
 */

require_once __DIR__ . '/includes/db_mysql.php';

// Hash bcrypt verificado de 'admin123'
// Generado con: password_hash('admin123', PASSWORD_BCRYPT)
$hash = '$2y$10$INF/JbG/i3qMWhb0sDogIOBvUobRwpDLVoD3jVJK8qve9A8lsbrFu';

try {
    $db = getDB();

    // Verificar que el hash sea válido antes de aplicarlo
    if (!password_verify('admin123', $hash)) {
        echo "❌ ERROR: El hash definido en el script es inválido. Abortando.\n";
        exit(1);
    }

    // Obtener cuentas actuales para reporte
    $stmt = $db->query("SELECT usuario, bloqueado, intentos_fallidos FROM usuarios ORDER BY rol");
    $usuarios = $stmt->fetchAll();

    echo "──────────────────────────────────────────\n";
    echo " SCI-TSS — Reset de Contraseñas por Defecto\n";
    echo "──────────────────────────────────────────\n\n";

    echo "Cuentas encontradas en la BD:\n";
    foreach ($usuarios as $u) {
        $estado = $u['bloqueado'] ? '🔒 BLOQUEADA' : '✅ Activa';
        echo "  [{$estado}] {$u['usuario']} ({$u['intentos_fallidos']} intentos fallidos)\n";
    }

    // Aplicar el restablecimiento global
    $stmt = $db->prepare(
        "UPDATE usuarios
         SET clave_hash           = ?,
             bloqueado            = 0,
             intentos_fallidos    = 0,
             requiere_cambio_clave = 1,
             actualizado_el       = NOW()
        "
    );
    $stmt->execute([$hash]);
    $afectados = $stmt->rowCount();

    echo "\n✅ {$afectados} cuenta(s) restablecidas a la contraseña 'admin123'.\n";
    echo "   Flag 'requiere_cambio_clave' = 1 (forzará rotación en el próximo login).\n\n";

    echo "Credenciales de acceso post-reset:\n";
    echo "  admin       / admin123  (ADMIN)\n";
    echo "  coordinador / admin123  (COORD)\n";
    echo "  analista    / admin123  (ANALISTA)\n";
    echo "  usuario     / admin123  (USUARIO)\n";
    echo "  consulta    / admin123  (CONSULTA)\n\n";

    echo "⚠️  ADVERTENCIA: Eliminar este script antes del despliegue en producción.\n";

} catch (Exception $e) {
    echo "❌ Error al conectar con la base de datos: " . $e->getMessage() . "\n";
    exit(1);
}
