-- ==============================================================================
-- SCI-TSS: SCRIPT MAESTRO UNIFICADO (VERSIÓN DEFINITIVA DE PRODUCCIÓN)
-- ==============================================================================
-- Idioma: 100% Español | Motor: InnoDB | Charset: utf8mb4_unicode_ci
-- Este script NO FALLA. Crea todas las columnas necesarias (incluyendo 'nivel').
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. ESTRUCTURA DE SEGURIDAD (RBAC + HARDENING)
-- ------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) UNIQUE NOT NULL,
    clave_hash VARCHAR(255) NOT NULL,
    nombre_completo VARCHAR(100) NOT NULL,
    -- Seguridad NIST
    activa TINYINT(1) NOT NULL DEFAULT 1,
    bloqueado TINYINT(1) NOT NULL DEFAULT 0,
    intentos_fallidos INT UNSIGNED NOT NULL DEFAULT 0,
    requiere_cambio_clave TINYINT(1) NOT NULL DEFAULT 1,
    clave_ultima_rotacion DATE NOT NULL DEFAULT (CURRENT_DATE),
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    creado_el TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_el TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_usuarios_auth (usuario, activa, bloqueado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIX: Columna 'nivel' agregada y 'nombre' en lugar de 'name' para compatibilidad PHP
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    descripcion VARCHAR(255) NULL,
    nivel INT DEFAULT 0,
    creado_el TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

-- PATRÓN SUDO
CREATE TABLE IF NOT EXISTS permisos_temporales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    permiso_id INT NOT NULL,
    asignado_por INT NOT NULL,
    creado_el TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expira_el DATETIME NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE,
    FOREIGN KEY (asignado_por) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ESTRUCTURA DE NEGOCIO (TSS)
-- ------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS gerencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) UNIQUE NOT NULL,
    siglas VARCHAR(20) NULL,
    activa BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nacionalidad ENUM('V','E') NOT NULL DEFAULT 'V',
    cedula VARCHAR(20) UNIQUE NOT NULL,
    primer_nombre VARCHAR(60) NOT NULL,
    segundo_nombre VARCHAR(60) NULL,
    primer_apellido VARCHAR(60) NOT NULL,
    segundo_apellido VARCHAR(60) NULL,
    cargo VARCHAR(150) NOT NULL,
    gerencia_id INT NULL,
    fecha_ingreso DATE NOT NULL DEFAULT (CURRENT_DATE),
    estado_laboral VARCHAR(30) NOT NULL DEFAULT 'Activo',
    estado_carnet ENUM('Pendiente por Imprimir','Carnet Impreso','Carnet Entregado') 
        NOT NULL DEFAULT 'Pendiente por Imprimir',
    foto_url VARCHAR(500) NULL,
    creado_el TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_el TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (gerencia_id) REFERENCES gerencias(id) ON DELETE SET NULL,
    INDEX idx_empleados_busqueda (cedula, primer_nombre, primer_apellido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configuracion_sistema (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(100) NOT NULL UNIQUE,
    valor TEXT NULL,
    descripcion VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditoria_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    accion VARCHAR(100) NOT NULL,
    detalles JSON NULL,
    direccion_ip VARCHAR(45) NULL,
    agente_usuario TEXT NULL,
    creado_el TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auditoria_fecha (creado_el)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. SEMILLA DE DATOS (SEED INICIAL)
-- ------------------------------------------------------------------------------

-- Gerencias
INSERT IGNORE INTO gerencias (nombre, siglas) VALUES
('Tecnología de la Información', 'TI'),
('Recursos Humanos', 'RRHH'),
('Seguridad Institucional', 'SI');

-- Roles (Con nivel definido para evitar el error 1054)
INSERT IGNORE INTO roles (id, nombre, descripcion, nivel) VALUES 
(1, 'ADMIN', 'Administrador del Sistema', 100),
(2, 'COORD', 'Coordinador General', 80),
(3, 'ANALISTA', 'Analista Operativo', 50),
(4, 'USUARIO', 'Usuario Estándar', 10);

-- Permisos
INSERT IGNORE INTO permisos (id, nombre, descripcion, recurso, accion) VALUES
(1, 'carnet.create', 'Registrar empleado', 'carnet', 'create'),
(2, 'carnet.view_all', 'Ver listado', 'carnet', 'read'),
(3, 'carnet.approve', 'Aprobar carnet', 'carnet', 'approve'),
(4, 'security.sudo', 'Delegar permisos', 'security', 'sudo'),
(5, 'gerencia.manage', 'Gestionar gerencias', 'gerencias', 'manage'),
(6, 'user.manage', 'Gestionar usuarios', 'usuarios', 'manage');

-- Rol_Permiso (Admin tiene todo)
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) VALUES 
(1,1), (1,2), (1,3), (1,4), (1,5), (1,6),
(2,2), (2,3), (2,4),
(3,2);

-- Usuarios Iniciales (Password: Admin123!)
INSERT IGNORE INTO usuarios (id, usuario, clave_hash, nombre_completo, requiere_cambio_clave) VALUES
(1, 'admin', '$2y$10$iwLuo/S.3GwpZl3FKrHtz.LNWXrXeARP2U1X3uwrXEYlENS6MiTsK', 'Administrador TSS', 1),
(2, 'coordinador', '$2y$10$iwLuo/S.3GwpZl3FKrHtz.LNWXrXeARP2U1X3uwrXEYlENS6MiTsK', 'Coordinador TSS', 1),
(3, 'analista', '$2y$10$iwLuo/S.3GwpZl3FKrHtz.LNWXrXeARP2U1X3uwrXEYlENS6MiTsK', 'Analista TSS', 1);

-- Usuario_Rol
INSERT IGNORE INTO usuario_rol (usuario_id, rol_id) VALUES 
(1, 1), (2, 2), (3, 3);

SET FOREIGN_KEY_CHECKS = 1;