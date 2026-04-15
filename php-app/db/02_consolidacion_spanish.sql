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
    descripcion TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuario_rol (
    usuario_id INT NOT NULL,
    rol_id INT NOT NULL,
    PRIMARY KEY (usuario_id, rol_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rol_permiso (
    rol_id INT NOT NULL,
    permiso_id INT NOT NULL,
    PRIMARY KEY (rol_id, permiso_id),
    FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permisos_temporales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    permiso_id INT NOT NULL,
    otorgado_por INT NOT NULL, 
    otorgado_el TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expira_en TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE,
    FOREIGN KEY (otorgado_por) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_temp_perm_lookup (usuario_id, permiso_id, expira_en)
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


-- 3. Migración de Semilla (Seed) a las nuevas tablas Spanish
INSERT IGNORE INTO permisos (id, nombre, descripcion)
SELECT id, name, description FROM permissions;

-- Si permissions no tiene datos, insertar directamente
INSERT IGNORE INTO permisos (id, nombre, descripcion) VALUES 
(1, 'carnet.create', 'Solicitar un nuevo carné'),
(2, 'carnet.view_own', 'Ver estado de carné propio'),
(3, 'carnet.view_all', 'Ver listado de todos los carnés'),
(4, 'carnet.update_status', 'Avanzar estado del carné (Impreso/Entregado)'),
(5, 'carnet.approve', 'Aprobar o anular solicitudes de carné'),
(6, 'user.manage', 'Crear, editar y desactivar usuarios'),
(7, 'security.sudo', 'Otorgar permisos temporales a otros'),
(8, 'auth.change_password', 'Cambiar contraseña de otro usuario (Admin)'),
(9, 'auth.self_password', 'Cambiar propia contraseña (Todos autenticados)'),
(10, 'settings.manage', 'Gestionar configuración institucional'),
(11, 'gerencia.manage', 'Crear, editar y eliminar gerencias'),
(12, 'carnet.delete', 'Eliminar registros de carnet');

INSERT IGNORE INTO usuario_rol (usuario_id, rol_id)
SELECT user_id, role_id FROM user_role;

-- Si no hay nada, admin por defecto
INSERT IGNORE INTO usuario_rol (usuario_id, rol_id) VALUES (1, 1);

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT role_id, permission_id FROM role_permission;

-- Si no hay nada, permisos ADMIN
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) VALUES 
(1,1), (1,2), (1,3), (1,4), (1,5), (1,6), (1,7), (1,8), (1,9), (1,10), (1,11), (1,12);

-- 4. Limpieza de tablas duplicadas English
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS user_role;
DROP TABLE IF EXISTS role_permission;
DROP TABLE IF EXISTS temporary_permissions;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS audit_log;

SET FOREIGN_KEY_CHECKS = 1;
