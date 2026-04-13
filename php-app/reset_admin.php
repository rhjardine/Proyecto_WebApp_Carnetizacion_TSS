<?php
require_once __DIR__ . '/includes/db_mysql.php';
try {
    $db = getDB();
    $hash = '$2y$10$INF/JbG/i3qMWhb0sDogIOBvUobRwpDLVoD3jVJK8qve9A8lsbrFu';
    $stmt = $db->prepare("UPDATE usuarios SET clave_hash = ?, bloqueado = 0, intentos_fallidos = 0 WHERE usuario = 'admin'");
    $stmt->execute([$hash]);
    echo "Admin password reset to 'admin123' and account unlocked.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
