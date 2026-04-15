-- ==============================================================================
-- SCHEMA DE PRODUCCIÓN: SCI-TSS (Sistema de Carnetización Inteligente)
-- Motor: MySQL / InnoDB
-- Charset: utf8mb4_unicode_ci (Soporte completo nombres/caracteres especiales)
-- Incluye: RBAC, SUDO Pattern, Audit Log Inmutable, Reglas de Negocio
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ==============================================================================
-- 1. NÚCLEO DE SEGURIDAD (RBAC + HARDENING)
-- ==============================================================================

-- 1.1 Tabla de Usuarios (Hardened: SQL-004, SQL-007)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    
    -- Control de Estados
    requires_password_change BOOLEAN DEFAULT TRUE,
    active BOOLEAN DEFAULT TRUE,
    
    -- Seguridad y Throttleo (OWASP CWE-307)
    failed_attempts INT UNSIGNED DEFAULT 0,
    locked_until TIMESTAMP NULL DEFAULT NULL,
    password_changed_at TIMESTAMP NULL DEFAULT NULL,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    last_login_ip VARCHAR(45) DEFAULT NULL,
    
    -- Trazabilidad de registro
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.2 Tabla de Roles
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    description TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.3 Tabla de Permisos (Acciones específicas)
CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL, 
    description TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.4 Tablas Pivote (RBAC Estándar)
CREATE TABLE IF NOT EXISTS user_role (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permission (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.5 El Patrón SUDO: Privilegios Temporales (Optimizada: SQL-006)
CREATE TABLE IF NOT EXISTS temporary_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    granted_by INT NOT NULL, 
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE CASCADE,
    
    -- Índices compuestos para la consulta UNION y cron jobs
    INDEX idx_temp_perm_lookup (user_id, permission_id, expires_at),
    INDEX idx_temp_perm_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.6 Tabla de Auditoría Inmutable (SQL-005)
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,                      
    action VARCHAR(100) NOT NULL,          
    entity_type VARCHAR(50),               
    entity_id INT,
    old_values JSON DEFAULT NULL,          
    new_values JSON DEFAULT NULL,          
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- 2. NÚCLEO DE NEGOCIO Y OPERATIVA (SQL-009)
-- ==============================================================================

-- 2.1 Gerencias Institucionales
CREATE TABLE IF NOT EXISTS gerencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) UNIQUE NOT NULL,
    siglas VARCHAR(20) NULL,
    activa BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.2 Empleados (Data base para la carnetización)
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cedula VARCHAR(20) UNIQUE NOT NULL,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    gerencia_id INT NULL,
    cargo VARCHAR(150) NOT NULL,
    tipo_sangre VARCHAR(5) NULL,
    foto_url VARCHAR(255) NULL,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (gerencia_id) REFERENCES gerencias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.3 Solicitudes y Estados de Carnetización
CREATE TABLE IF NOT EXISTS carnet_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    requested_by INT NOT NULL, -- Usuario que hizo la solicitud
    estado ENUM('Solicitado', 'En Revisión', 'Aprobado', 'Impreso', 'Entregado', 'Anulado') DEFAULT 'Solicitado',
    motivo_solicitud ENUM('Nuevo Ingreso', 'Deterioro', 'Extravío/Robo', 'Cambio de Cargo') NOT NULL,
    observaciones TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_carnet_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==============================================================================
-- 3. SEEDING DE DATOS (SQL-008 y SQL-010)
-- ==============================================================================

-- 3.1 Roles y Permisos (Ajustado con self_password y gestión completa)
INSERT IGNORE INTO roles (id, name, description) VALUES 
(1, 'Administrador', 'Control total del sistema y seguridad'),
(2, 'Coordinador', 'Supervisión, aprobación de carnés y asignación SUDO'),
(3, 'Analista', 'Gestión operativa, actualización de estados de carné'),
(4, 'Usuario', 'Solicitante de carné estándar');

INSERT IGNORE INTO permissions (id, name, description) VALUES 
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

-- 3.2 Asignación de Permisos a Roles
-- Administrador: Todo (1 al 12)
INSERT IGNORE INTO role_permission (role_id, permission_id) VALUES 
(1,1), (1,2), (1,3), (1,4), (1,5), (1,6), (1,7), (1,8), (1,9), (1,10), (1,11), (1,12);

-- Coordinador: Gestión operativa, SUDO, propia clave y listados
INSERT IGNORE INTO role_permission (role_id, permission_id) VALUES 
(2,3), (2,4), (2,5), (2,7), (2,9), (2,11);

-- Analista: Ver/Avanzar estados y propia clave
INSERT IGNORE INTO role_permission (role_id, permission_id) VALUES 
(3,3), (3,4), (3,9);

-- Usuario: Crear, ver propio y propia clave
INSERT IGNORE INTO role_permission (role_id, permission_id) VALUES 
(4,1), (4,2), (4,9);

-- 3.3 Creación de Usuarios Iniciales
-- NOTA: Estos hashes BCRYPT corresponden a la contraseña 'Admin123!'
-- DEBEN SER CAMBIADOS INMEDIATAMENTE EN EL PRIMER INICIO DE SESIÓN
INSERT IGNORE INTO users (id, username, password, email, full_name, requires_password_change, active, password_changed_at) VALUES 
(1, 'admin', '$2y$10$wE6b9b2Zz7iU/P6.WkXF4uN/7QvQ/M.NqFp3Hn/u7y7o.K8m5Xq4G', 'admin@tss.gob.ve', 'Administrador del Sistema', TRUE, TRUE, NOW()),
(2, 'coordinador', '$2y$10$wE6b9b2Zz7iU/P6.WkXF4uN/7QvQ/M.NqFp3Hn/u7y7o.K8m5Xq4G', 'coord@tss.gob.ve', 'Coordinador General', TRUE, TRUE, NOW());

-- 3.4 Asignación de Usuarios a Roles
INSERT IGNORE INTO user_role (user_id, role_id) VALUES (1, 1);
INSERT IGNORE INTO user_role (user_id, role_id) VALUES (2, 2);

SET FOREIGN_KEY_CHECKS = 1;
