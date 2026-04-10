<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/includes/db_mysql.php';
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) FROM usuarios");
    $count = $stmt->fetchColumn();
    echo json_encode(['success' => true, 'count' => $count]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>