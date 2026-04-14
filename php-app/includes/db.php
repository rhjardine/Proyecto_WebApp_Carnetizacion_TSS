<?php
/**
 * db.php — Alias de compatibilidad hacia db_mysql.php
 * ====================================================
 * DEUDA TÉCNICA ELIMINADA: Este archivo fue refactorizado para delegar
 * toda la funcionalidad a db_mysql.php, que es la fuente única de verdad.
 *
 * Los endpoints que hacen require de 'includes/db.php' siguen funcionando
 * sin ningún cambio. Internamente se redirige a db_mysql.php.
 */
require_once __DIR__ . '/db_mysql.php';

// Alias de compatibilidad: los endpoints que usan $pdo en lugar de getDB() pueden seguir
// funcionando si necesitan. Se asigna la instancia singleton al nombre legacy.
if (!isset($pdo)) {
    $pdo = getDB();
}
