<?php
require_once __DIR__ . '/api/config/db.php';

$newPassword = 'admin123';

try {
    $db = getDB();
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    if ($hash === false) {
        throw new RuntimeException('No se pudo generar el hash BCRYPT para la contraseña del administrador.');
    }
    $stmt = $db->prepare("UPDATE usuarios SET clave_hash = ?, bloqueado = 0, intentos_fallidos = 0 WHERE usuario = 'admin'");
    $stmt->execute([$hash]);
    echo "Admin password reset to '{$newPassword}' and account unlocked.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
