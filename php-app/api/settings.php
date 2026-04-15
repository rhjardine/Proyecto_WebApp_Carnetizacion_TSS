<?php
/**
 * api/settings.php — Persistencia de configuración institucional
 * =============================================================
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/middleware/auth_check.php';

$method = $_SERVER['REQUEST_METHOD'];

function normalizeSettingValue(array $row)
{
    $tipo = $row['tipo'] ?? 'string';
    $valor = $row['valor'] ?? null;

    return match ($tipo) {
        'boolean' => filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ($valor === '1'),
        'number' => is_numeric($valor) ? ($valor + 0) : $valor,
        'json' => json_decode($valor, true) ?? $valor,
        default => $valor,
    };
}

function inferSettingType($valor): string
{
    if (is_bool($valor)) {
        return 'boolean';
    }
    if (is_int($valor) || is_float($valor)) {
        return 'number';
    }
    if (is_array($valor) || is_object($valor)) {
        return 'json';
    }
    return 'string';
}

function serializeSettingValue($valor, string $tipo): string
{
    return match ($tipo) {
        'boolean' => $valor ? '1' : '0',
        'json' => json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        default => (string) $valor,
    };
}

try {
    $db = getDB();

    if ($method === 'GET') {
        $stmt = $db->query("SELECT seccion, clave, valor, tipo FROM configuracion_sistema ORDER BY seccion, clave");
        $results = $stmt->fetchAll();
        $config = [];
        foreach ($results as $row) {
            if (!isset($config[$row['seccion']])) {
                $config[$row['seccion']] = [];
            }
            $config[$row['seccion']][$row['clave']] = normalizeSettingValue($row);
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
        $tipo = $input['tipo'] ?? inferSettingType($valor);
        $descripcion = $input['descripcion'] ?? null;

        if (!$clave) {
            sendResponse(false, 'La clave de configuración es requerida.', null, 400);
        }

        $allowedTypes = ['string', 'number', 'boolean', 'json'];
        if (!in_array($tipo, $allowedTypes, true)) {
            sendResponse(false, 'Tipo de configuración inválido.', null, 400);
        }

        $valorStr = serializeSettingValue($valor, $tipo);

        $stmt = $db->prepare(
            "INSERT INTO configuracion_sistema (seccion, clave, valor, tipo, descripcion)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                valor = VALUES(valor),
                tipo = VALUES(tipo),
                descripcion = VALUES(descripcion),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$seccion, $clave, $valorStr, $tipo, $descripcion]);

        sendResponse(true, 'Configuración institucional actualizada correctamente.');
    }

    sendResponse(false, 'Método no permitido.', null, 405);

} catch (Exception $e) {
    error_log('[SCI-TSS settings.php] ' . $e->getMessage());
    sendResponse(false, 'Error interno del servidor al procesar la configuración.', null, 500);
}
