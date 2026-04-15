<?php
/**
 * api/gerencias.php — Gestión de Gerencias
 */
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/config/db.php';
$pdo = getDB();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT * FROM gerencias ORDER BY nombre ASC");
            sendResponse(true, 'Lista de gerencias', $stmt->fetchAll());
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = $input['id'] ?? null;
            $nombre = trim($input['nombre'] ?? '');

            if ($id && $nombre) {
                // UPDATE (rename)
                $pdo->prepare("UPDATE gerencias SET nombre = ? WHERE id = ?")
                    ->execute([$nombre, $id]);
                sendResponse(true, 'Gerencia actualizada');
            } elseif ($nombre) {
                // INSERT
                $stmt = $pdo->prepare("INSERT INTO gerencias (nombre) VALUES (?)");
                $stmt->execute([$nombre]);
                $newId = $pdo->lastInsertId();
                sendResponse(true, 'Gerencia creada con éxito', ['id' => $newId, 'nombre' => $nombre]);
            } else {
                sendResponse(false, 'El nombre de la gerencia es requerido', null, 400);
            }
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id = $_GET['id'] ?? $input['id'] ?? null;
            $nombre = trim($input['nombre'] ?? '');
            if (!$id || !$nombre) {
                sendResponse(false, 'ID y nombre son requeridos', null, 400);
                exit;
            }
            $pdo->prepare("UPDATE gerencias SET nombre = ? WHERE id = ?")
                ->execute([$nombre, $id]);
            sendResponse(true, 'Gerencia actualizada');
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                sendResponse(false, 'ID de gerencia no proporcionado', null, 400);
                exit;
            }
            $pdo->prepare("DELETE FROM gerencias WHERE id = ?")->execute([$id]);
            sendResponse(true, 'Gerencia eliminada con éxito');
            break;

        default:
            sendResponse(false, 'Método no permitido', null, 405);
    }
} catch (Exception $e) {
    sendResponse(false, 'Error en el servidor: ' . $e->getMessage(), null, 500);
}
