<?php
/**
 * SCI-TSS Security Middleware (RBAC + Hardening)
 * ============================================
 * Implementa NIST RBAC, SUDO Pattern, CSRF, Brute Force Protection y Session Hardening.
 */

class Security
{

    /**
     * Inicializa la sesión con hardening estricto y fingerprinting.
     */
    public static function initSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isSecure = ENFORCE_HTTPS || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');

            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path' => '/',
                'domain' => '',
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);

            session_start();
        }

        // Fingerprinting para prevenir secuestro de sesión (Session Hijacking)
        $fingerprint = md5(
            ($_SERVER['HTTP_USER_AGENT'] ?? 'none') .
            ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') .
            ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'none')
        );

        if (!isset($_SESSION['fingerprint'])) {
            $_SESSION['fingerprint'] = $fingerprint;
            $_SESSION['last_activity'] = time();
        } else {
            // Verificar anomalía de sesión o timeout
            if ($_SESSION['fingerprint'] !== $fingerprint || (time() - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
                self::logout();
                return false;
            }
            $_SESSION['last_activity'] = time();
        }
        return true;
    }

    /**
     * Cierra la sesión y limpia cookies.
     */
    public static function logout()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }
            session_destroy();
        }
    }

    /**
     * Generación de Token CSRF (OWASP).
     */
    public static function generateCSRF()
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validación de Token CSRF.
     */
    public static function validateCSRF($token)
    {
        return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }

    /**
     * Detección de Fuerza Bruta mediante Audit Log inmutable.
     */
    public static function isBruteForce(PDO $pdo, $username)
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // Bloqueo si hay más de 5 intentos fallidos en los últimos 15 minutos (por IP o Usuario)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM audit_log 
            WHERE action = 'LOGIN_FAILURE' 
            AND (new_values->>'$.username' = ? OR ip_address = ?) 
            AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$username, $ip]);
        return $stmt->fetchColumn() >= 5;
    }

    /**
     * Autenticación Centralizada y Hardened.
     */
    public static function loginUser(PDO $pdo, $username, $password)
    {
        self::initSession();

        if (self::isBruteForce($pdo, $username)) {
            self::logAudit($pdo, null, 'LOGIN_BLOCKED_BRUTEFORCE', 'users', null, null, ['username' => $username, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            return ['success' => false, 'message' => 'Seguridad: Demasiada actividad inusual. Cuenta bloqueada temporalmente.', 'code' => 403];
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            self::logAudit($pdo, null, 'LOGIN_FAILURE', 'users', null, null, ['username' => $username, 'reason' => 'user_not_found']);
            return ['success' => false, 'message' => 'Credenciales inválidas.', 'code' => 401];
        }

        // Verificar bloqueo manual o temporal
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            return ['success' => false, 'message' => 'Cuenta bloqueada por seguridad. Reintente más tarde.', 'code' => 403];
        }

        if (password_verify($password, $user['password'])) {
            // Login Exitoso: Limpiar fallos y regenerar ID
            $stmt = $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
            $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            self::generateCSRF();

            self::logAudit($pdo, $user['id'], 'LOGIN_SUCCESS', 'users', $user['id']);

            return [
                'success' => true,
                'message' => 'Login exitoso.',
                'csrf_token' => $_SESSION['csrf_token'],
                'data' => [
                    'id' => (int) $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'requires_password_change' => (bool) $user['requires_password_change']
                ]
            ];
        } else {
            // Login Fallido: Incrementar contador y bloquear si es necesario
            $newAttempts = (int) $user['failed_attempts'] + 1;
            $lockUntil = ($newAttempts >= 5) ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;

            $stmt = $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?");
            $stmt->execute([$newAttempts, $lockUntil, $user['id']]);

            self::logAudit($pdo, (int) $user['id'], 'LOGIN_FAILURE', 'users', $user['id'], null, ['username' => $username, 'reason' => 'invalid_password']);

            $msg = ($newAttempts >= 5) ? 'Demasiados intentos. Cuenta bloqueada por 15 minutos.' : 'Credenciales inválidas.';
            return ['success' => false, 'message' => $msg, 'code' => 401];
        }
    }

    /**
     * NIST RBAC: Verifica permisos incluyendo herencia de roles y Patrón SUDO.
     */
    public static function requirePermission(PDO $pdo, $permissionName)
    {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Sesión no iniciada.']);
            exit;
        }

        $sql = "SELECT COUNT(*) FROM (
                    -- RBAC Estándar
                    SELECT p.id FROM permissions p
                    JOIN role_permission rp ON p.id = rp.permission_id
                    JOIN user_role ur ON rp.role_id = ur.role_id
                    WHERE ur.user_id = ? AND p.name = ?
                    UNION
                    -- Patrón SUDO (Permisos temporales)
                    SELECT tp.permission_id FROM temporary_permissions tp
                    JOIN permissions p ON tp.permission_id = p.id
                    WHERE tp.user_id = ? AND p.name = ? AND tp.expires_at > NOW()
                ) AS allowed_set";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$_SESSION['user_id'], $permissionName, $_SESSION['user_id'], $permissionName]);

        if ($stmt->fetchColumn() == 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso Denegado: Permiso [' . $permissionName . '] requerido.']);
            exit;
        }
        return true;
    }

    /**
     * SUDO Pattern: Otorga permiso temporal.
     */
    public static function grantTemporaryPermission(PDO $pdo, $userId, $permissionId, $durationMinutes = 60)
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$durationMinutes minutes"));
        $stmt = $pdo->prepare("INSERT INTO temporary_permissions (user_id, permission_id, granted_by, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $permissionId, $_SESSION['user_id'], $expiresAt]);

        self::logAudit($pdo, $_SESSION['user_id'], 'SUDO_GRANT', 'temporary_permissions', $pdo->lastInsertId(), null, ['to_user' => $userId, 'perm_id' => $permissionId, 'expires' => $expiresAt]);
    }

    /**
     * SUDO Pattern: Revoca permiso temporal.
     */
    public static function revokeTemporaryPermission(PDO $pdo, $userId, $permissionId)
    {
        $stmt = $pdo->prepare("DELETE FROM temporary_permissions WHERE user_id = ? AND permission_id = ?");
        $stmt->execute([$userId, $permissionId]);

        self::logAudit($pdo, $_SESSION['user_id'], 'SUDO_REVOKE', 'temporary_permissions', null, null, ['to_user' => $userId, 'perm_id' => $permissionId]);
    }

    /**
     * Auditoría Inmutable.
     */
    public static function logAudit(PDO $pdo, $userId, $action, $entityType = null, $entityId = null, $oldValues = null, $newValues = null)
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $action,
                $entityType,
                $entityId,
                $oldValues ? json_encode($oldValues) : null,
                $newValues ? json_encode($newValues) : null,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("[CRITICAL SECURITY] AuditLog Fail: " . $e->getMessage());
        }
    }
}
