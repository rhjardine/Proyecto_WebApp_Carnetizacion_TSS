<?php
/**
 * fix_endpoints.php - Verificación de compatibilidad del schema consumido por endpoints
 * Ejecutar manualmente en preproducción para confirmar tabla y columnas requeridas.
 */

require_once __DIR__ . '/api/config/db.php';

try {
    $pdo = getDB();

    $candidateTables = ['empleados', 'employees'];
    $targetTable = null;

    foreach ($candidateTables as $table) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        if ((int) $stmt->fetchColumn() > 0) {
            $targetTable = $table;
            break;
        }
    }

    if (!$targetTable) {
        throw new RuntimeException('No existe tabla empleados ni employees en la base de datos actual.');
    }

    echo "Tabla objetivo detectada: {$targetTable}\n";

    $columns = $pdo->query("DESCRIBE {$targetTable}")->fetchAll(PDO::FETCH_COLUMN);
    $required = $targetTable === 'empleados'
        ? ['cedula', 'primer_nombre', 'primer_apellido', 'estado_carnet', 'gerencia_id']
        : ['cedula', 'nombres', 'apellidos', 'cargo', 'gerencia_id'];

    foreach ($required as $col) {
        if (!in_array($col, $columns, true)) {
            echo "FALTA columna: {$col}\n";
        } else {
            echo "OK columna: {$col}\n";
        }
    }

} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}