-- fix_rbac_schema.sql - Remediación CR-05 para el schema RBAC consumido por la app actual
-- Crea las tablas Spanish usadas por RBAC.php/sudo.php y siembra permisos base.

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    nivel INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permisos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    descripcion VARCHAR(255) NULL,
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
    expira_en DATETIME NULL,
    UNIQUE KEY uq_permiso_temporal (usuario_id, permiso_id),
    INDEX idx_permiso_temporal_lookup (usuario_id, permiso_id, expira_en),
    INDEX idx_permiso_temporal_expira (expira_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (id, name, description, nivel) VALUES
(1, 'ADMIN', 'Administrador del sistema', 100),
(2, 'COORD', 'Coordinador operativo', 80),
(3, 'ANALISTA', 'Analista de carnetización', 50),
(4, 'USUARIO', 'Operador básico', 30),
(5, 'CONSULTA', 'Solo lectura', 10);

INSERT IGNORE INTO permisos (id, nombre, descripcion, recurso, accion) VALUES
(1, 'carnet.create', 'Solicitar un nuevo carné', 'carnet', 'create'),
(2, 'carnet.view_own', 'Ver estado de carné propio', 'carnet', 'read_own'),
(3, 'carnet.view_all', 'Ver listado de todos los carnés', 'carnet', 'read'),
(4, 'carnet.update_status', 'Avanzar estado del carné', 'carnet', 'update_status'),
(5, 'carnet.approve', 'Aprobar o anular solicitudes de carné', 'carnet', 'approve'),
(6, 'user.manage', 'Crear, editar y desactivar usuarios', 'usuarios', 'manage'),
(7, 'security.sudo', 'Otorgar permisos temporales', 'security', 'sudo'),
(8, 'auth.change_password', 'Cambiar contraseña de otro usuario', 'auth', 'change_password'),
(9, 'auth.self_password', 'Cambiar propia contraseña', 'auth', 'self_password'),
(10, 'settings.manage', 'Gestionar configuración institucional', 'config', 'update'),
(11, 'gerencia.manage', 'Crear, editar y eliminar gerencias', 'gerencias', 'manage'),
(12, 'carnet.delete', 'Eliminar registros de carnet', 'carnet', 'delete');

INSERT IGNORE INTO usuario_rol (usuario_id, rol_id) VALUES (1, 1);

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) VALUES
(1,1), (1,2), (1,3), (1,4), (1,5), (1,6), (1,7), (1,8), (1,9), (1,10), (1,11), (1,12),
(2,3), (2,4), (2,5), (2,7), (2,9), (2,11),
(3,3), (3,4), (3,9),
(4,1), (4,2), (4,9);