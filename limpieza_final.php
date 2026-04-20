<?php
/**
 * limpieza_final.php — Herramienta de Hardening SCI-TSS
 * ====================================================
 * Este script elimina archivos de utilidad, logs y scripts de prueba
 * para preparar el sistema para el despliegue en red.
 */

$targets = [
    // Scripts de utilidad peligrosos
    'reset_admin.php',
    'check_db.php',
    'test_hash.php',
    'setup.php',
    'fix_paths.php',
    // Archivos SQL (Ya deben estar en la BD, no deben estar en el webroot)
    'db/00_tablas_espanol.sql',
    'db/02_consolidacion_spanish.sql',
    'db/fix_indexes.sql',
    'db/02_rbac_migration.sql',
    // Archivos legacy
    'api/auth.php'
];

echo "<h2>Iniciando Saneamiento de Seguridad SCI-TSS...</h2>";
echo "<ul>";

foreach ($targets as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        if (unlink($path)) {
            echo "<li style='color:green;'>[ELIMINADO] $file</li>";
        } else {
            echo "<li style='color:red;'>[ERROR] No se pudo eliminar $file (Revise permisos)</li>";
        }
    } else {
        echo "<li style='color:gray;'>[SALTADO] $file (No existe)</li>";
    }
}

echo "</ul>";
echo "<p><b>Hardening completado.</b> Este script se eliminará a sí mismo ahora...</p>";

// Auto-eliminación por seguridad
unlink(__FILE__);
?>