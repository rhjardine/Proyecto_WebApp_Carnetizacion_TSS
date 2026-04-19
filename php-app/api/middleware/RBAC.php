<?php
/**
 * RBAC.php — Motor de Seguridad NIST RBAC (VERSIÓN DEFINITIVA)
 * =========================================================
 * Compatibilidad 100% garantizada con 01_master_final_spanish.sql
 */

require_once __DIR__ . '/../config/db.php';

class Security
{
    /**
     * Inicia sesión PHP de forma segura, suprimiendo warnings si ya está activa.
     */
    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $domain = ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1') ? '' : $_SERVER['HTTP_HOST'];
            session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => $domain, 'httponly' => true, 'samesite' => 'Lax']);
            @session_start();
        }
    }

    /**
     * Genera o retorna el CSRF token de la sesión activa.
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
     * logAudit() — Auditoría inmutable en tabla auditoria_logs.
     */
    public static function logAudit(PDO $pdo, ?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?array $oldValues = null, ?array $newValues = null): void
    {
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
                        substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
                    ]);
        } catch (PDOException $e) {
        } // Falla silenciosa para no romper la app principal
    }

    /**
     * Autentica al usuario devolviendo el contrato exacto que espera api_v3.js
     */
    public static function loginUser($pdo, $username, $password)
    {
        self::startSecureSession();

        // FIX DEFENSIVO: Se evita el alias "rol" en el SELECT para descartar ambigüedades en MySQL
        // y se mapea directamente en el array de retorno. CERO dependencias de "u.rol".
        $stmt = $pdo->prepare("
            SELECT 
                u.id, 
                u.clave_hash, 
                u.requiere_cambio_clave, 
                u.nombre_completo, 
                r.name AS nombre_rol
            FROM usuarios u
            LEFT JOIN usuario_rol ur ON u.id = ur.usuario_id
            LEFT JOIN roles r ON ur.rol_id = r.id
            WHERE u.usuario = :usuario AND u.activa = 1
        ");
        $stmt->execute([':usuario' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['clave_hash'])) {
            @session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $username;
            $_SESSION['nombre'] = $user['nombre_completo'];
            $_SESSION['role'] = strtoupper($user['nombre_rol'] ?? 'USUARIO');
            $_SESSION['requires_password_change'] = $user['requiere_cambio_clave'];

            self::logAudit($pdo, $user['id'], 'LOGIN_SUCCESS', 'usuarios', $user['id']);

            return [
                'success' => true,
                'message' => 'Login exitoso.',
                'csrf_token' => self::generateCsrfToken(),
                'data' => [
                    'id' => $user['id'],
                    'username' => $username,
                    'full_name' => $user['nombre_completo'],
                    'role' => $_SESSION['role'],
                    'requires_password_change' => (bool) $user['requiere_cambio_clave']
                ]
            ];
        }

        self::logAudit($pdo, null, 'LOGIN_FAILED', 'usuarios', null, null, ['username' => $username]);
        return ['success' => false, 'message' => 'Credenciales inválidas.'];
    }

    /**
     * Verifica permisos del usuario
     */
    public static function hasPermission($pdo, $userId, $permissionName)
    {
        $sql = "
            SELECT p.name
            FROM usuarios u
            JOIN usuario_rol ur ON u.id = ur.usuario_id
            JOIN rol_permiso rp ON ur.rol_id = rp.rol_id
            JOIN permisos p ON rp.permiso_id = p.id
            WHERE u.id = ? AND p.name = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $permissionName]);
        return $stmt->fetchColumn() ? true : false;
    }

    /**
     * Middleware para proteger endpoints
     */
    public static function requirePermission($pdo, $permissionName)
    {
        self::startSecureSession();
        if (!isset($_SESSION['user_id'])) {
            header('HTTP/1.0 401 Unauthorized');
            echo json_encode(['success' => false, 'error' => 'No autenticado.']);
            exit;
        }
        if (!self::hasPermission($pdo, $_SESSION['user_id'], $permissionName)) {
            header('HTTP/1.0 403 Forbidden');
            echo json_encode(['success' => false, 'error' => 'Permiso denegado.']);
            exit;
        }
        return true;
    }
}