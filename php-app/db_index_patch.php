<?php
require 'includes/db_mysql.php';
try {
    $db = getDB();
    // Index on fecha_ingreso
    $in = $db->prepare("SHOW INDEX FROM empleados WHERE Key_name = 'idx_empleados_fecha_ingreso'");
    $in->execute();
    if (!$in->fetch()) {
        $db->exec("CREATE INDEX idx_empleados_fecha_ingreso ON empleados (fecha_ingreso)");
        echo 'Index created. ';
    } else {
        echo 'Index already exists. ';
    }
} catch (Exception $e) {
    echo 'ERROR: ' . $e->getMessage();
}
