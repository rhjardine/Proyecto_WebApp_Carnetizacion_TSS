-- ==============================================================================
-- CONSOLIDACIÓN DE SCHEMA: SCI-TSS (NIST RBAC en Español)
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Asegurar que las tablas English (si existen y tienen datos) se pasen a Spanish
-- (Nota: Preferimos mantener 'usuarios' y 'empleados' que son las originales)

-- 2. Esquema RBAC en Español
CREATE TABLE IF NOT EXISTS permisos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) UNIQUE NOT NULL, 
    descripcion TEXT,
    recurso VARCHAR(100) NULL,
    accion VARCHAR(50) NULL,
    INDEX idx_permisos_recurso_accion (recurso, accion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuario_rol (
    usuario_id INT NOT NULL,
    rol_id INT NOT NULL,
    PRIMARY KEY (usuario_id, rol_id),
    INDEX idx_usuario_rol_usuario (usuario_id),
    INDEX idx_usuario_rol_rol (rol_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rol_permiso (
    rol_id INT NOT NULL,
    permiso_id INT NOT NULL,
    PRIMARY KEY (rol_id, permiso_id),
    INDEX idx_rol_permiso_rol (rol_id),
    INDEX idx_rol_permiso_permiso (permiso_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permisos_temporales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    permiso_id INT NOT NULL,
    otorgado_por INT NOT NULL, 
    otorgado_el TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expira_en TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_temp_perm_lookup (usuario_id, permiso_id, expira_en),
    INDEX idx_temp_perm_otorgado_por (otorgado_por)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configuracion_sistema (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(100) NOT NULL UNIQUE,
    valor TEXT NULL,
    seccion VARCHAR(50) NOT NULL DEFAULT 'global',
    tipo ENUM('string', 'number', 'boolean', 'json') NOT NULL DEFAULT 'string',
    descripcion VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_configuracion_seccion (seccion),
    INDEX idx_configuracion_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP PROCEDURE IF EXISTS migrar_rbac_desde_english;

DELIMITER $$
CREATE PROCEDURE migrar_rbac_desde_english()
BEGIN
        IF EXISTS (
                SELECT 1
                    FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                     AND table_name = 'permissions'
        ) THEN
                INSERT IGNORE INTO permisos (id, nombre, descripcion)
                SELECT id, name, description FROM permissions;
        END IF;

        IF EXISTS (
                SELECT 1
                    FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                     AND table_name = 'user_role'
        ) THEN
                INSERT IGNORE INTO usuario_rol (usuario_id, rol_id)
                SELECT user_id, role_id FROM user_role;
        END IF;

        IF EXISTS (
                SELECT 1
                    FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                     AND table_name = 'role_permission'
        ) THEN
                INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
                SELECT role_id, permission_id FROM role_permission;
        END IF;
END$$
DELIMITER ;

CALL migrar_rbac_desde_english();
DROP PROCEDURE IF EXISTS migrar_rbac_desde_english;

-- 3. Migración de Semilla (Seed) a las nuevas tablas Spanish

-- Si permissions no tiene datos, insertar directamente
INSERT IGNORE INTO permisos (id, nombre, descripcion, recurso, accion) VALUES 
(1, 'carnet.create', 'Solicitar un nuevo carné', 'carnet', 'create'),
(2, 'carnet.view_own', 'Ver estado de carné propio', 'carnet', 'read_own'),
(3, 'carnet.view_all', 'Ver listado de todos los carnés', 'carnet', 'read'),
(4, 'carnet.update_status', 'Avanzar estado del carné (Impreso/Entregado)', 'carnet', 'update_status'),
(5, 'carnet.approve', 'Aprobar o anular solicitudes de carné', 'carnet', 'approve'),
(6, 'user.manage', 'Crear, editar y desactivar usuarios', 'usuarios', 'manage'),
(7, 'security.sudo', 'Otorgar permisos temporales a otros', 'security', 'sudo'),
(8, 'auth.change_password', 'Cambiar contraseña de otro usuario (Admin)', 'auth', 'change_password'),
(9, 'auth.self_password', 'Cambiar propia contraseña (Todos autenticados)', 'auth', 'self_password'),
(10, 'settings.manage', 'Gestionar configuración institucional', 'config', 'update'),
(11, 'gerencia.manage', 'Crear, editar y eliminar gerencias', 'gerencias', 'manage'),
(12, 'carnet.delete', 'Eliminar registros de carnet', 'carnet', 'delete');

-- Si no hay nada, admin por defecto
INSERT IGNORE INTO usuario_rol (usuario_id, rol_id) VALUES (1, 1);

-- Si no hay nada, permisos ADMIN
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) VALUES 
(1,1), (1,2), (1,3), (1,4), (1,5), (1,6), (1,7), (1,8), (1,9), (1,10), (1,11), (1,12);

-- 4. Limpieza de tablas duplicadas English
-- Eliminada por seguridad: no se hacen DROP automáticos en consolidación.
-- La remoción de tablas legacy debe ser una tarea manual y validada por DBA
-- después de confirmar migración completa de datos y compatibilidad de endpoints.

SET FOREIGN_KEY_CHECKS = 1;
