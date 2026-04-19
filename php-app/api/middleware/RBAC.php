<?php
/**
 * RBAC.php — Motor de Seguridad NIST RBAC (REMEDIADO v3.0)
 * =========================================================
 * CORRECCIONES:
 *  1. generateCsrfToken() ahora es estático público (login.php la llama así)
 *     y retorna el token en lugar de void.
 *  2. startSecureSession() usa @session_start() suprimido correctamente.
 *  3. loginUser() retorna estructura `data` completa y consistente con
 *     el contrato esperado por api.js (res.data.username, res.data.role, etc.)
 *  4. Consulta de rol desde usuario_rol JOIN roles: si no encuentra rol,
 *     intenta obtenerlo directamente del campo `rol` de la tabla usuarios
 *     como fallback (robustez ante datos incompletos de seed).
 *  5. requirePermission(): retorna true correctamente, no hace exit en éxito.
 */

class Security
{
    /**
     * Inicia sesión PHP de forma segura, suprimiendo warnings si ya está activa.
     */
    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    /**
     * Genera o retorna el CSRF token de la sesión activa.
     * CORRECCIÓN: retorna string (antes era void).
     */
    public static function generateCsrfToken(): string
    {
        self::startSecureSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Anti-Brute Force: verifica si la cuenta está bloqueada.
     */
    private static function isBruteForce(PDO $pdo, string $username): bool
    {
        $stmt = $pdo->prepare("SELECT bloqueado FROM usuarios WHERE usuario = ? LIMIT 1");
        $stmt->execute([$username]);
        $res = $stmt->fetch();
        return ($res && (int) $res['bloqueado'] === 1);
    }

    /**
     * loginUser() — Autenticación NIST con estructura de respuesta unificada.
     *
     * CONTRATO DE RESPUESTA (éxito):
     * {
     *   success: true,
     *   message: string,
     *   csrf_token: string,
     *   data: {
     *     id: int, username: string, full_name: string,
     *     role: string, effective_role: string,
     *     temporary_role: null|string,
     *     requires_password_change: bool
     *   }
     * }
     */
    public static function loginUser(PDO $pdo, string $username, string $password): array
    {
        // Verificar bloqueo antes de tocar la BD innecesariamente
        if (self::isBruteForce($pdo, $username)) {
            self::logAudit($pdo, null, 'LOGIN_BLOCKED_BRUTEFORCE', 'usuarios', null);
            return [
                'success' => false,
                'message' => 'Cuenta bloqueada por múltiples intentos fallidos. Contacte al administrador.',
            ];
        }

        // Obtener usuario activo
        $stmt = $pdo->prepare(
            "SELECT id, usuario, clave_hash, nombre_completo, rol, bloqueado,
                    intentos_fallidos, requiere_cambio_clave, activa
             FROM usuarios
             WHERE usuario = ?
             LIMIT 1"
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Usuario no encontrado (respuesta genérica anti-enumeración)
        if (!$user) {
            self::logAudit($pdo, null, 'LOGIN_FAILURE_NOTFOUND', 'usuarios', null);
            return ['success' => false, 'message' => 'Credenciales inválidas.'];
        }

        // Usuario inactivo
        if ((int) $user['activa'] !== 1) {
            return ['success' => false, 'message' => 'Cuenta inactiva. Contacte al administrador.'];
        }

        // Cuenta bloqueada manualmente
        if ((int) $user['bloqueado'] === 1) {
            return ['success' => false, 'message' => 'Cuenta bloqueada. Contacte al administrador.'];
        }

        // Verificar contraseña con bcrypt
        if (!password_verify($password, $user['clave_hash'])) {
            // Incrementar contador de intentos fallidos
            $newAttempts = (int) $user['intentos_fallidos'] + 1;
            $bloquear = ($newAttempts >= 5) ? 1 : 0;

            $pdo->prepare("UPDATE usuarios SET intentos_fallidos = ?, bloqueado = ? WHERE id = ?")
                ->execute([$newAttempts, $bloquear, $user['id']]);

            self::logAudit($pdo, (int) $user['id'], 'LOGIN_FAILURE_BADPASS', 'usuarios', $user['id']);
            return ['success' => false, 'message' => 'Credenciales inválidas.'];
        }

        // ── LOGIN EXITOSO ─────────────────────────────────────

        // Resetear contadores de seguridad y registrar acceso
        $pdo->prepare(
            "UPDATE usuarios SET intentos_fallidos = 0, last_login_at = NOW(), last_login_ip = ? WHERE id = ?"
        )->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);

        // Obtener rol desde usuario_rol JOIN roles (NIST RBAC)
        // Fallback: usar campo `rol` directo de la tabla usuarios
        $roleStmt = $pdo->prepare(
            "SELECT r.name
             FROM roles r
             JOIN usuario_rol ur ON r.id = ur.rol_id
             WHERE ur.usuario_id = ?
             LIMIT 1"
        );
        $roleStmt->execute([$user['id']]);
        $roleName = $roleStmt->fetchColumn();

        // Fallback: si no hay registro en usuario_rol, usar campo `rol` directo
        if (!$roleName) {
            $roleName = strtoupper($user['rol'] ?? 'USUARIO');
        }

        // Iniciar sesión segura
        self::startSecureSession();
        @session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['usuario'];
        $_SESSION['nombre'] = $user['nombre_completo'];
        $_SESSION['role'] = $roleName;

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

    /**
     * requirePermission() — Verifica permisos RBAC + SUDO Pattern.
     * Termina la ejecución con HTTP 401/403 si no autorizado.
     */
    public static function requirePermission(PDO $pdo, string $permissionName): bool
    {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Sesión no iniciada. Inicie sesión nuevamente.']);
            exit;
        }

        $sql = "SELECT COUNT(*) FROM (
                    -- Permisos por rol (RBAC estándar)
                    SELECT p.id
                    FROM permisos p
                    JOIN rol_permiso rp ON p.id = rp.permiso_id
                    JOIN usuario_rol ur ON rp.rol_id = ur.rol_id
                    WHERE ur.usuario_id = :uid AND p.nombre = :perm

                    UNION

                    -- Permisos temporales (Patrón SUDO)
                    SELECT pt.permiso_id
                    FROM permisos_temporales pt
                    JOIN permisos p ON pt.permiso_id = p.id
                    WHERE pt.usuario_id = :uid2 AND p.nombre = :perm2
                      AND (pt.expira_en IS NULL OR pt.expira_en > NOW())
                ) AS allowed_set";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uid' => $_SESSION['user_id'],
            ':perm' => $permissionName,
            ':uid2' => $_SESSION['user_id'],
            ':perm2' => $permissionName,
        ]);

        if ($stmt->fetchColumn() == 0) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => "Acceso denegado. Permiso requerido: [{$permissionName}].",
            ]);
            exit;
        }

        return true;
    }

    /**
     * grantTemporaryPermission() — Patrón SUDO: otorgar permiso temporal.
     */
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

    /**
     * revokeTemporaryPermission() — Patrón SUDO: revocar permiso temporal.
     */
    public static function revokeTemporaryPermission(PDO $pdo, int $userId, int $permissionId): bool
    {
        $adminId = (int) $_SESSION['user_id'];
        $pdo->prepare("DELETE FROM permisos_temporales WHERE usuario_id = ? AND permiso_id = ?")
            ->execute([$userId, $permissionId]);
        self::logAudit($pdo, $adminId, 'REVOKE_TEMP_PERMISSION', 'usuarios', $userId);
        return true;
    }

    /**
     * logAudit() — Auditoría inmutable en tabla auditoria_logs.
     */
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
                        substr($action, 0, 50),
                        $details,
                        substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45),
                        substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500),
                    ]);
        } catch (Exception $e) {
            error_log('[SCI-TSS RBAC] AuditLog failed: ' . $e->getMessage());
        }
    }
}
