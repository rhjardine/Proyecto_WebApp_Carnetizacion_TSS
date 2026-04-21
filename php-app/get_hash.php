<?php
require_once __DIR__ . '/api/config/db.php';
$db = getDB();
$hash = $db->query("SELECT clave_hash FROM usuarios WHERE usuario = 'admin'")->fetchColumn();
file_put_contents('hash_result.txt', $hash);
