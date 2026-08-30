<?php
/**
 * api/settings.php — Configuración institucional
 * Centralizado con bootstrap.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/middleware/auth_check.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo = getDB();
$userId = $_SESSION['user_id'] ?? null;

try {
    if ($method === 'GET') {
        Security::requirePermission($pdo, 'carnet.view_all');
        // Lectura pura: liberar el bloqueo del archivo de sesión para no serializar las
        // peticiones concurrentes que lanza la pantalla de Ajustes. Ver gerencias.php.
        session_write_close();
        $stmt = $pdo->query("SELECT clave, valor, descripcion FROM configuracion_sistema");
        $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formatted = [];
        foreach ($configs as $c) {
            $val = json_decode($c['valor'], true);
            $formatted[$c['clave']] = (json_last_error() === JSON_ERROR_NONE) ? $val : $c['valor'];
        }
        sendResponse(true, 'Configuraciones obtenidas.', $formatted);
    }

    if ($method === 'POST' || $method === 'PUT') {
        Security::requirePermission($pdo, 'settings.manage');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $clave = $input['clave'] ?? null;
        $valor = $input['valor'] ?? null;
        $desc = $input['descripcion'] ?? null;
        if (empty($clave))
            sendResponse(false, 'La clave de configuración es requerida.', null, 400);
        $valStr = is_array($valor) ? json_encode($valor) : (string) $valor;
        $stmt = $pdo->prepare("INSERT INTO configuracion_sistema (clave, valor, descripcion) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor), descripcion = COALESCE(VALUES(descripcion), descripcion), updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$clave, $valStr, $desc]);
        Security::logAudit($pdo, $userId, 'CONFIGURACION_ACTUALIZADA', 'configuracion_sistema', null, null, ['clave' => $clave]);
        sendResponse(true, 'Configuración actualizada correctamente.');
    }

    sendResponse(false, 'Método no permitido.', null, 405);
} catch (Exception $e) {
    error_log('[SCI-TSS settings.php] ' . $e->getMessage());
    sendResponse(false, 'Error interno al procesar configuraciones.', null, 500);
}