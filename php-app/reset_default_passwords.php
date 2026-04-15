<?php
/**
 * reset_default_passwords.php
 * ============================
 * Script de recuperación de accesos para entorno pre-productivo SCI-TSS.
 * CORRECCIÓN: Genera hashes dinámicos para mayor fiabilidad.
 */

require_once __DIR__ . '/api/config/db.php';

try {
    $db = getDB();

    $credentials = [
        'admin' => 'admin123',
        'coordinador' => 'coord123',
        'analista' => 'analista123',
        'usuario' => 'usuario123',
        'consulta' => 'consulta123'
    ];

    echo "──────────────────────────────────────────\n";
    echo " SCI-TSS — Reset de Contraseñas por Defecto\n";
    echo "──────────────────────────────────────────\n\n";

    $stmt = $db->prepare(
        "UPDATE usuarios
         SET clave_hash           = ?,
             bloqueado            = 0,
             intentos_fallidos    = 0,
             requiere_cambio_clave = 1,
             actualizado_el       = NOW()
         WHERE usuario = ?"
    );

    foreach ($credentials as $user => $pass) {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt->execute([$hash, $user]);
        if ($stmt->rowCount() > 0) {
            echo "✅ Usuario '{$user}' restablecido con éxito.\n";
        } else {
            echo "⚠️  Usuario '{$user}' no encontrado o no modificado.\n";
        }
    }

    echo "\nCredenciales de acceso post-reset:\n";
    foreach ($credentials as $user => $pass) {
        echo "  {$user} / {$pass}\n";
    }

    echo "\n⚠️  ADVERTENCIA: Eliminar este script antes del despliegue en producción.\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
