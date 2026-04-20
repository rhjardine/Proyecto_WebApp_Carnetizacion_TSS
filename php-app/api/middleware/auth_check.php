<?php
/**
 * auth_check.php — Middleware de compatibilidad (Versión Saneada)
 * ==============================================================
 * Este archivo ya no hace consultas peligrosas a la BD. 
 * Se nutre directamente de la sesión segura validada por RBAC.php
 */

require_once __DIR__ . '/RBAC.php';

Security::startSecureSession();

// Si no hay sesión válida, lanza el error que el frontend puede atrapar
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Error de seguridad middleware.']);
    exit;
}

// Inyectamos la variable global $authUser para que archivos legacy (como settings.php) no colapsen
$authUser = [
    'id' => $_SESSION['user_id'],
    'username' => $_SESSION['username'] ?? '',
    'nombre' => $_SESSION['nombre'] ?? '',
    'rol_efectivo' => $_SESSION['role'] ?? 'USUARIO'
];