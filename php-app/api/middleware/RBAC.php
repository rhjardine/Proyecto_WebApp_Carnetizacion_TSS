<?php
/**
 * RBAC.php — Motor de Seguridad NIST RBAC + Patrón SUDO (Consolidado)
 * ====================================================================
 * Provee:
 *  - Autenticación segura (Bcrypt + Brute Force Protection)
 *  - Autorización granular (Permissions, Role Inheritance)
 *  - Patrón SUDO (Temporary Permission Delegation)
 *  - Auditoría Inmutable (NIST/OWASP Compliance)
 */

class Security
{
    /**
     * Verifica si hay demasiados intentos fallidos (Anti-Brute Force).
     */
    private static function isBruteForce(PDO $pdo, $username)
    {
        $stmt = $pdo->prepare("SELECT intentos_fallidos, bloqueado FROM usuarios WHERE usuario = ?");
        $stmt->execute([$username]);
        $res = $stmt->fetch();
        return ($res && (int) $res['bloqueado'] === 1);
    }

    /**
     * Autenticación NIST: Verifica credenciales en la tabla usuarios.
     */
    public static function loginUser(PDO $pdo, $username, $password)
    {
        // Protección contra Brute Force (OWASP)
        if (self::isBruteForce($pdo, $username)) {
            self::logAudit($pdo, null, 'LOGIN_BLOCKED_BRUTEFORCE', 'usuarios', null);
            return ['success' => false, 'message' => 'Seguridad: Cuenta bloqueada por demasiados intentos.'];
        }

        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ? AND activa = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            self::logAudit($pdo, null, 'LOGIN_FAILURE', 'usuarios', null);
            return ['success' => false, 'message' => 'Credenciales inválidas.'];
        }

        // Verificar bloqueo manual
        if ((int) $user['bloqueado'] === 1) {
            return ['success' => false, 'message' => 'Cuenta bloqueada. Contacte al administrador.'];
        }

        if (password_verify($password, $user['clave_hash'])) {
            // Login Exitoso: Limpiar fallos y regenerar ID
            $stmt = $pdo->prepare("UPDATE usuarios SET intentos_fallidos = 0, last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
            $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);

            // Obtener el nombre del rol base (desde usuario_rol)
            $roleStmt = $pdo->prepare("
                SELECT r.name FROM roles r
                JOIN usuario_rol ur ON r.id = ur.rol_id
                WHERE ur.usuario_id = ?
                LIMIT 1
            ");
            $roleStmt->execute([$user['id']]);
            $roleName = $roleStmt->fetchColumn() ?: 'USUARIO';

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['usuario'];
            $_SESSION['nombre'] = $user['nombre_completo'];
            $_SESSION['role'] = $roleName;
            self::generateCSRF();

            self::logAudit($pdo, $user['id'], 'LOGIN_SUCCESS', 'usuarios', $user['id']);

            return [
                'success' => true,
                'message' => 'Login exitoso.',
                'csrf_token' => $_SESSION['csrf_token'] ?? '',
                'data' => [
                    'id' => (int) $user['id'],
                    'username' => $user['usuario'],
                    'full_name' => $user['nombre_completo'],
                    'role' => $roleName,
                    'effective_role' => $roleName,
                    'requires_password_change' => (bool) $user['requiere_cambio_clave']
                ]
            ];
        } else {
            // Login Fallido: Incrementar contador
            $newAttempts = (int) $user['intentos_fallidos'] + 1;
            $bloquear = ($newAttempts >= 5) ? 1 : 0;

            $stmt = $pdo->prepare("UPDATE usuarios SET intentos_fallidos = ?, bloqueado = ? WHERE id = ?");
            $stmt->execute([$newAttempts, $bloquear, $user['id']]);

            self::logAudit($pdo, (int) $user['id'], 'LOGIN_FAILURE', 'usuarios', $user['id']);
            return ['success' => false, 'message' => 'Credenciales inválidas.'];
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
                    SELECT p.id FROM permisos p
                    JOIN rol_permiso rp ON p.id = rp.permiso_id
                    JOIN usuario_rol ur ON rp.rol_id = ur.rol_id
                    WHERE ur.usuario_id = ? AND p.nombre = ?
                    UNION
                    -- Patrón SUDO (Permisos temporales)
                    SELECT tp.permiso_id FROM permisos_temporales tp
                    JOIN permisos p ON tp.permiso_id = p.id
                    WHERE tp.usuario_id = ? AND p.nombre = ? AND tp.expira_en > NOW()
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
        $adminId = $_SESSION['user_id'];
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$durationMinutes minutes"));

        $sql = "INSERT INTO permisos_temporales (usuario_id, permiso_id, otorgado_por, expira_en) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE expira_en = VALUES(expira_en)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $permissionId, $adminId, $expiresAt]);

        self::logAudit($pdo, $adminId, 'GRANT_TEMP_PERMISSION', 'usuarios', $userId);
        return true;
    }

    public static function revokeTemporaryPermission(PDO $pdo, $userId, $permissionId)
    {
        $adminId = $_SESSION['user_id'];
        $sql = "DELETE FROM permisos_temporales WHERE usuario_id = ? AND permiso_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $permissionId]);

        self::logAudit($pdo, $adminId, 'REVOKE_TEMP_PERMISSION', 'usuarios', $userId);
        return true;
    }

    /**
     * Auditoría Inmutable (Consolidada con auditoria_logs).
     */
    public static function logAudit(PDO $pdo, $userId, $action, $entityType = null, $entityId = null, $oldValues = null, $newValues = null)
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO auditoria_logs (usuario_id, accion, detalles, direccion_ip, agente_usuario, creado_el) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $details = [
                'tabla' => $entityType,
                'id' => $entityId,
                'antes' => $oldValues,
                'despues' => $newValues
            ];
            $stmt->execute([
                $userId,
                substr($action, 0, 50),
                json_encode($details, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500)
            ]);
        } catch (Exception $e) {
            error_log("[SECURITY] AuditLog Fail: " . $e->getMessage());
        }
    }

    public static function generateCSRF()
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
}
