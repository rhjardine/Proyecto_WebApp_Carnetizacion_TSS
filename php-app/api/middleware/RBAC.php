<?php
/**
 * RBAC.php — Motor de Seguridad NIST RBAC (FUSIÓN DEFINITIVA)
 * ====================================================================
 * Combina la corrección de Schema de Claude (r.nombre) con la
 * validación estricta de seguridad del Agente Canvas (Sin Bypasses).
 */

require_once __DIR__ . '/../config/db.php';

class Security
{
    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $domain = ($host === 'localhost' || $host === '127.0.0.1') ? '' : $host;
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => $domain,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            @session_start();
        }
    }

    public static function generateCsrfToken(): string
    {
        self::startSecureSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    private static function isBruteForce(PDO $pdo, string $username): bool
    {
        $stmt = $pdo->prepare("SELECT bloqueado FROM usuarios WHERE usuario = ? LIMIT 1");
        $stmt->execute([$username]);
        $res = $stmt->fetch();
        return ($res && (int) $res['bloqueado'] === 1);
    }

    public static function loginUser(PDO $pdo, string $username, string $password): array
    {
        if (self::isBruteForce($pdo, $username)) {
            self::logAudit($pdo, null, 'LOGIN_BLOCKED_BRUTEFORCE', 'usuarios', null);
            return [
                'success' => false,
                'message' => 'Cuenta bloqueada por múltiples intentos fallidos. Contacte al administrador.',
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT id, usuario, clave_hash, nombre_completo, rol, bloqueado,
                    intentos_fallidos, requiere_cambio_clave, activa
             FROM usuarios
             WHERE usuario = ? AND activa = 1
             LIMIT 1"
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            self::logAudit($pdo, null, 'LOGIN_FAILURE_NOTFOUND', 'usuarios', null);
            return ['success' => false, 'message' => 'Credenciales inválidas o cuenta inactiva.'];
        }

        if ((int) $user['bloqueado'] === 1) {
            return ['success' => false, 'message' => 'Cuenta bloqueada. Contacte al administrador.'];
        }

        if (!password_verify($password, $user['clave_hash'])) {
            $newAttempts = (int) $user['intentos_fallidos'] + 1;
            $bloquear = ($newAttempts >= 5) ? 1 : 0;

            $pdo->prepare("UPDATE usuarios SET intentos_fallidos = ?, bloqueado = ? WHERE id = ?")
                ->execute([$newAttempts, $bloquear, $user['id']]);

            self::logAudit($pdo, (int) $user['id'], 'LOGIN_FAILURE_BADPASS', 'usuarios', $user['id']);
            return ['success' => false, 'message' => 'Credenciales inválidas.'];
        }

        $pdo->prepare(
            "UPDATE usuarios SET intentos_fallidos = 0, last_login_at = NOW(), last_login_ip = ? WHERE id = ?"
        )->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);

        // OBTENCIÓN DE ROL DESDE TABLA ROLES CON 'nombre' (Fusión Claude)
        $roleStmt = $pdo->prepare(
            "SELECT r.nombre
             FROM roles r
             JOIN usuario_rol ur ON r.id = ur.rol_id
             WHERE ur.usuario_id = ?
             LIMIT 1"
        );
        $roleStmt->execute([$user['id']]);
        $roleName = $roleStmt->fetchColumn();

        if (!$roleName) {
            $roleName = strtoupper($user['rol'] ?? ($username === 'admin' ? 'ADMIN' : 'USUARIO'));
        }

        self::startSecureSession();
        @session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['usuario'];
        $_SESSION['nombre'] = $user['nombre_completo'];
        $_SESSION['role'] = $roleName;
        $_SESSION['requires_password_change'] = (bool) $user['requiere_cambio_clave'];

        $csrfToken = self::generateCsrfToken();

        self::logAudit($pdo, (int) $user['id'], 'LOGIN_SUCCESS', 'usuarios', $user['id']);

        return [
            'success' => true,
            'message' => 'Login exitoso.',
            'csrf_token' => $csrfToken,
            'data' => [
                'id' => (int) $user['id'],
                'username' => $user['usuario'],
                'full_name' => $user['nombre_completo'],
                'role' => $roleName,
                'effective_role' => $roleName,
                'temporary_role' => null,
                'requires_password_change' => (bool) $user['requiere_cambio_clave'],
                'csrf_token' => $csrfToken,
            ],
        ];
    }

    public static function hasPermission(PDO $pdo, int $userId, string $permissionName): bool
    {
        // IMPLEMENTACIÓN ESTRICTA (Sin Bypass). Protege endpoints. Usa 'p.nombre'.
        $sql = "SELECT COUNT(*) FROM (
                    SELECT p.id
                    FROM permisos p
                    JOIN rol_permiso rp ON p.id = rp.permiso_id
                    JOIN usuario_rol ur ON rp.rol_id = ur.rol_id
                    WHERE ur.usuario_id = :uid AND p.nombre = :perm

                    UNION

                    SELECT pt.permiso_id
                    FROM permisos_temporales pt
                    JOIN permisos p ON pt.permiso_id = p.id
                    WHERE pt.usuario_id = :uid2 AND p.nombre = :perm2
                      AND (pt.expira_en IS NULL OR pt.expira_en > NOW())
                ) AS allowed_set";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uid' => $userId,
            ':perm' => $permissionName,
            ':uid2' => $userId,
            ':perm2' => $permissionName,
        ]);

        return $stmt->fetchColumn() > 0;
    }

    public static function requirePermission(PDO $pdo, string $permissionName): bool
    {
        self::startSecureSession();

        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'No autenticado.']);
            exit;
        }

        if (!self::hasPermission($pdo, $_SESSION['user_id'], $permissionName)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => "Acceso denegado. Permiso requerido: [{$permissionName}].",
            ]);
            exit;
        }

        return true;
    }

    public static function grantTemporaryPermission(PDO $pdo, int $userId, int $permissionId, int $durationMinutes = 60): bool
    {
        $adminId = (int) $_SESSION['user_id'];
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$durationMinutes} minutes"));

        $sql = "INSERT INTO permisos_temporales (usuario_id, permiso_id, otorgado_por, expira_en)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE expira_en = VALUES(expira_en)";

        $pdo->prepare($sql)->execute([$userId, $permissionId, $adminId, $expiresAt]);
        self::logAudit($pdo, $adminId, 'GRANT_TEMP_PERMISSION', 'usuarios', $userId);
        return true;
    }

    public static function revokeTemporaryPermission(PDO $pdo, int $userId, int $permissionId): bool
    {
        $adminId = (int) $_SESSION['user_id'];
        $pdo->prepare("DELETE FROM permisos_temporales WHERE usuario_id = ? AND permiso_id = ?")
            ->execute([$userId, $permissionId]);
        self::logAudit($pdo, $adminId, 'REVOKE_TEMP_PERMISSION', 'usuarios', $userId);
        return true;
    }

    public static function logAudit(
        PDO $pdo,
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        try {
            $details = json_encode([
                'tabla' => $entityType,
                'id' => $entityId,
                'antes' => $oldValues,
                'despues' => $newValues,
            ], JSON_UNESCAPED_UNICODE);

            $pdo->prepare(
                "INSERT INTO auditoria_logs (usuario_id, accion, detalles, direccion_ip, agente_usuario, creado_el)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            )->execute([
                        $userId,
                        substr($action, 0, 100),
                        $details,
                        substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45),
                        substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255),
                    ]);
        } catch (Exception $e) {
            error_log('[SCI-TSS RBAC] AuditLog failed: ' . $e->getMessage());
        }
    }
}