<?php
require_once __DIR__ . '/api/config/db.php';
$pdo = getDB();
$stmt = $pdo->prepare("SELECT id, usuario, nombre_completo, bloqueado, intentos_fallidos, clave_hash, activa FROM usuarios WHERE usuario = 'admin'");
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
if ($user) {
    echo json_encode([
        'exists' => true,
        'username' => $user['usuario'],
        'bloqueado' => $user['bloqueado'],
        'intentos_fallidos' => $user['intentos_fallidos'],
        'activa' => $user['activa'],
        'hash_prefix' => substr($user['clave_hash'], 0, 10) . '...'
    ]);
} else {
    echo json_encode(['exists' => false]);
}
