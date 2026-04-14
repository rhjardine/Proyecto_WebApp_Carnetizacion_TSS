<?php
/**
 * includes/cors.php — CORS headers seguros para SCI-TSS
 * =========================================================
 * Seguridad:
 *  - No se usa wildcard '*' (incompatible con credentials: true según RFC 6454).
 *  - Se valida el origen entrante contra una whitelist explícita.
 *  - En producción, agregar los dominios institucionales reales al array $allowedOrigins.
 */

$allowedOrigins = [
    'http://localhost',
    'http://localhost:80',
    'http://127.0.0.1',
    'http://localhost:3000',  // dev servers
    'http://127.0.0.1:3000',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    // Si no hay origen (petición directa del browser en misma origen), no se mandan headers CORS.
    // El endpoint sigue funcionando por cookie/session de Apache normal.
}

header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-CSRF-Token');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
