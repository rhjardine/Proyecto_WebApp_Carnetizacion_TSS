<?php
/**
 * api/employees/index.php
 * Compatibilidad para clientes legados que aún apuntan a /api/employees/index.php.
 * Reenvía toda la ejecución al endpoint canónico MySQL en /api/employees.php.
 */

require_once __DIR__ . '/../employees.php';
