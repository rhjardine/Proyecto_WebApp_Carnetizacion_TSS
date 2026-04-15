<?php
/**
 * api/middleware/auth_check.php — Middleware RBAC Zero Trust (SCI-TSS)
 * ======================================================================
 * CORRECCIÓN v2.1:
 *   - Variables de sesión alineadas con los nombres establecidos en auth.php:
 *       $_SESSION['user_id']      → id del usuario
 *       $_SESSION['username']     → campo `usuario` de la tabla
 *       $_SESSION['role']         → campo `rol` de la tabla
 *       $_SESSION['rol_temporal'] → campo `rol_temporal` de la tabla
 *       $_SESSION['nombre']       → campo `nombre_completo` de la tabla
 *
 * GARANTÍAS:
 *  1. Sesión activa         → HTTP 401 si no autenticado.
 *  2. CSRF token válido     → HTTP 403 en métodos mutantes sin token.
 *  3. RBAC por rol efectivo → HTTP 403 si CONSULTA intenta escritura.
 *  4. rol_temporal tiene PRECEDENCIA sobre rol permanente (Zero Trust).
 *
 * EXPORTA $authUser[] con:
 *   id, username, rol, rol_temporal, rol_efectivo, nombre
 */
require_once __DIR__ . '/../config/db.php';

// ── Session Hardening ────────────────────────────────────────
$isSecure = ENFORCE_HTTPS || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Strict',
]);

// Iniciar sesión solo si no está ya activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

try {
    $db = getDB();

    // ── 1. VERIFICAR SESIÓN Y CARGAR DATOS FRESCOS (Zero Trust) ──
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No autorizado.']);
        exit;
    }

    $stmt = $db->prepare("SELECT id, usuario, rol, rol_temporal, rol_temporal_expira_en, requiere_cambio_clave, clave_ultima_rotacion FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado. Sesión cerrada.']);
        exit;
    }

    // ── 2. VALIDAR EXPIRACIÓN DE ROL TEMPORAL ────────────────────
    $rolEfectivo = $user['rol'];
    if ($user['rol_temporal']) {
        $expira = $user['rol_temporal_expira_en'] ? strtotime($user['rol_temporal_expira_en']) : null;
        if ($expira && $expira < time()) {
            // Rol expirado → Limpiar en BD y usar rol base
            $db->prepare("UPDATE usuarios SET rol_temporal = NULL, rol_temporal_expira_en = NULL, delegado_por = NULL WHERE id = ?")
                ->execute([$user['id']]);
            $_SESSION['rol_temporal'] = null;
        } else {
            $rolEfectivo = $user['rol_temporal'];
        }
    }

    // ── 3. POLÍTICAS DE CONTRASEÑA ───────────────────────────────
    $diffDays = (time() - strtotime($user['clave_ultima_rotacion'])) / 86400;
    $passwordExpired = $diffDays > PASS_ROTATION_DAYS;
    $mustChange = (int) $user['requiere_cambio_clave'] === 1 || $passwordExpired;

    // Permitir SOLO cambiar contraseña si debe hacerlo
    $currentUri = $_SERVER['REQUEST_URI'];
    if ($mustChange && strpos($currentUri, 'action=change_password') === false && strpos($currentUri, 'force-password-change.php') === false && strpos($currentUri, 'auth/logout.php') === false) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'requires_password_change' => true,
            'message' => $passwordExpired ? 'Su contraseña ha expirado (90 días). Debe cambiarla.' : 'Debe cambiar su contraseña antes de continuar.'
        ]);
        exit;
    }

    // ── 4. MATRIZ DE ROLES (RBAC v2.6) ───────────────────────────
    $method = strtoupper($_SERVER['REQUEST_METHOD']);
    $metodosMutantes = ['POST', 'PATCH', 'PUT', 'DELETE'];
    $esMutante = in_array($method, $metodosMutantes, true);

    if ($esMutante) {
        $denied = false;
        $msg = 'Acceso denegado.';

        switch ($rolEfectivo) {
            case 'CONSULTA':
                $denied = true;
                $msg = 'El rol CONSULTA no tiene permisos de escritura.';
                break;
            case 'USUARIO':
                // Solo lectura o CRUD limitado (se valida en el endpoint específico)
                break;
            case 'ANALISTA':
                // No puede gestionar usuarios ni delegar
                if (strpos($currentUri, 'api/users.php') !== false)
                    $denied = true;
                break;
            case 'COORD':
                // Puede delegar pero no gestionar usuarios base
                if (strpos($currentUri, 'api/users.php') !== false) {
                    $body = json_decode(file_get_contents('php://input'), true) ?? [];
                    $action = $body['action'] ?? $_GET['action'] ?? '';
                    if (!in_array($action, ['delegate', 'revoke']))
                        $denied = true;
                }
                break;
            case 'ADMIN':
                // Acceso total
                break;
        }

        if ($denied) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }
    }

    // ── 5. VALIDACIÓN CSRF ───────────────────────────────────────
    if ($esMutante && ENFORCE_CSRF) {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($sessionToken) || !hash_equals($sessionToken, $headerToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
            exit;
        }
    }

    $authUser = [
        'id' => (int) $user['id'],
        'username' => $user['usuario'],
        'rol_base' => $user['rol'],
        'rol_temporal' => $user['rol_temporal'],
        'rol_efectivo' => $rolEfectivo,
        'must_change_password' => $mustChange
    ];

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de seguridad middleware.']);
    exit;
}
