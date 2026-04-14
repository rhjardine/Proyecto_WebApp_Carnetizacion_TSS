<?php
/**
 * api/settings.php — Persistencia de configuración institucional
 * =============================================================
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/middleware/auth_check.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    if ($method === 'GET') {
        $stmt = $db->query("SELECT seccion, clave, valor FROM configuracion_sistema");
        $results = $stmt->fetchAll();
        $config = [];
        foreach ($results as $row) {
            if (!isset($config[$row['seccion']])) {
                $config[$row['seccion']] = [];
            }
            // Intentar decodificar si es JSON
            $val = json_decode($row['valor'], true);
            $config[$row['seccion']][$row['clave']] = (json_last_error() === JSON_ERROR_NONE) ? $val : $row['valor'];
        }
        sendResponse(true, 'Configuración obtenida.', $config);
    }

    if ($method === 'POST') {
        if ($authUser['rol_efectivo'] !== 'ADMIN') {
            sendResponse(false, 'Solo el Administrador puede modificar la configuración global.', null, 403);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $seccion = $input['seccion'] ?? 'global';
        $clave = $input['clave'] ?? '';
        $valor = $input['valor'] ?? null;

        if (!$clave) {
            sendResponse(false, 'La clave de configuración es requerida.', null, 400);
        }

        $valorStr = (is_array($valor) || is_object($valor)) ? json_encode($valor) : (string) $valor;

        $stmt = $db->prepare(
            "INSERT INTO configuracion_sistema (seccion, clave, valor)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = ?, actualizado_el = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$seccion, $clave, $valorStr, $valorStr]);

        sendResponse(true, 'Configuración institucional actualizada correctamente.');
    }

} catch (Exception $e) {
    error_log('[SCI-TSS settings.php] ' . $e->getMessage());
    sendResponse(false, 'Error interno del servidor al procesar la configuración.', null, 500);
}
