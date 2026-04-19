-- ==============================================================================
-- 00_tablas_espanol.sql — Script Maestro de Tablas en Español
-- ==============================================================================
-- Sistema de Carnetización Inteligente (SCI-TSS)
-- EJECUTAR ANTES de 02_consolidacion_spanish.sql
-- Idempotente: usa CREATE TABLE IF NOT EXISTS en todas las tablas.
--
-- Tablas creadas:
--   usuarios        → consumida por auth.php, RBAC.php, auth_check.php
--   empleados       → consumida por employees.php, upload.php
--   auditoria_logs  → consumida por config/db.php (logAction)
--
-- Seed incluido:
--   - Usuario admin  (Admin123!)
--   - Usuario analista_demo (Admin123!)
--   Hash generado con: password_hash('Admin123!', PASSWORD_BCRYPT) en PHP 8.x
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ==============================================================================
-- TABLA: usuarios
-- Espejo de la estructura esperada por auth.php y RBAC.php
-- ==============================================================================
CREATE TABLE IF NOT EXISTS usuarios (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    usuario                 VARCHAR(50) UNIQUE NOT NULL,
    clave_hash              VARCHAR(255) NOT NULL,
    nombre_completo         VARCHAR(100) NOT NULL,
    rol                     ENUM('ADMIN','COORD','ANALISTA','USUARIO','CONSULTA') NOT NULL DEFAULT 'USUARIO',
    rol_temporal            ENUM('ADMIN','COORD','ANALISTA','USUARIO','CONSULTA') NULL DEFAULT NULL,
    rol_temporal_expira_en  DATETIME NULL DEFAULT NULL,
    delegado_por            INT NULL DEFAULT NULL,
    activa                  TINYINT(1) NOT NULL DEFAULT 1,
    bloqueado               TINYINT(1) NOT NULL DEFAULT 0,
    intentos_fallidos       INT UNSIGNED NOT NULL DEFAULT 0,
    requiere_cambio_clave   TINYINT(1) NOT NULL DEFAULT 1,
    clave_ultima_rotacion   DATE NOT NULL DEFAULT (CURRENT_DATE),
    last_login_at           DATETIME NULL DEFAULT NULL,
    last_login_ip           VARCHAR(45) NULL DEFAULT NULL,
    creado_el               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_el          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Clave foránea diferida (auto-referencial): delegado_por → id
    -- Se agrega como INDEX sin FK para evitar problemas de orden de creación
    INDEX idx_usuarios_rol (rol),
    INDEX idx_usuarios_activa (activa),
    INDEX idx_usuarios_delegado_por (delegado_por)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLA: gerencias (debe existir antes de empleados por FK)
-- ==============================================================================
CREATE TABLE IF NOT EXISTS gerencias (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(150) UNIQUE NOT NULL,
    siglas      VARCHAR(20) NULL,
    activa      BOOLEAN DEFAULT TRUE,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLA: empleados
-- Estructura disgregada (primer_nombre / primer_apellido) usada por employees.php
-- ==============================================================================
CREATE TABLE IF NOT EXISTS empleados (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    nacionalidad        ENUM('V','E') NOT NULL DEFAULT 'V',
    cedula              VARCHAR(20) UNIQUE NOT NULL,
    primer_nombre       VARCHAR(60) NOT NULL,
    segundo_nombre      VARCHAR(60) NULL DEFAULT NULL,
    primer_apellido     VARCHAR(60) NOT NULL,
    segundo_apellido    VARCHAR(60) NULL DEFAULT NULL,
    cargo               VARCHAR(150) NOT NULL,
    gerencia_id         INT NULL DEFAULT NULL,
    fecha_ingreso       DATE NOT NULL DEFAULT (CURRENT_DATE),
    estado_laboral      VARCHAR(30) NOT NULL DEFAULT 'Activo',
    estado_carnet       ENUM('Pendiente por Imprimir','Carnet Impreso','Carnet Entregado')
                        NOT NULL DEFAULT 'Pendiente por Imprimir',
    forma_entrega       ENUM('Manual','Digital') NULL DEFAULT NULL,
    nivel_permiso       VARCHAR(20) NOT NULL DEFAULT 'Nivel 1',
    foto_url            VARCHAR(500) NULL DEFAULT NULL,
    foto_ruta           VARCHAR(500) NULL DEFAULT NULL,
    creado_el           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_el      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_cedula_numerica CHECK (cedula REGEXP '^[0-9]+$'),
    FOREIGN KEY (gerencia_id) REFERENCES gerencias(id) ON DELETE SET NULL,
    INDEX idx_empleados_cedula (cedula),
    INDEX idx_empleados_estado_carnet (estado_carnet),
    INDEX idx_empleados_gerencia_id (gerencia_id),
    INDEX idx_empleados_nombres (primer_nombre, primer_apellido),
    INDEX idx_empleados_fecha_ingreso (fecha_ingreso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- TABLA: auditoria_logs
-- Usada por logAction() en api/config/db.php
-- ==============================================================================
CREATE TABLE IF NOT EXISTS auditoria_logs (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id      INT NULL,
    accion          VARCHAR(100) NOT NULL,
    detalles        JSON NULL,
    direccion_ip    VARCHAR(45) NULL,
    agente_usuario  TEXT NULL,
    creado_el       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_auditoria_logs_usuario (usuario_id),
    INDEX idx_auditoria_logs_accion (accion),
    INDEX idx_auditoria_logs_fecha (creado_el)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- SEED: Usuarios iniciales
-- Hash generado con: password_hash('Admin123!', PASSWORD_BCRYPT) en XAMPP PHP 8.x
-- Hash válido: $2y$10$iwLuo/S.3GwpZl3FKrHtz.LNWXrXeARP2U1X3uwrXEYlENS6MiTsK
-- INSTRUCCIÓN: cambiar contraseña inmediatamente en el primer inicio de sesión.
-- ==============================================================================
INSERT IGNORE INTO usuarios
    (id, usuario, clave_hash, nombre_completo, rol, activa, bloqueado, requiere_cambio_clave)
VALUES
    (1, 'admin',      '$2y$10$iwLuo/S.3GwpZl3FKrHtz.LNWXrXeARP2U1X3uwrXEYlENS6MiTsK',
     'Administrador del Sistema', 'ADMIN',    1, 0, 1),
    (2, 'coordinador','$2y$10$iwLuo/S.3GwpZl3FKrHtz.LNWXrXeARP2U1X3uwrXEYlENS6MiTsK',
     'Coordinador General',      'COORD',    1, 0, 1),
    (3, 'analista',   '$2y$10$iwLuo/S.3GwpZl3FKrHtz.LNWXrXeARP2U1X3uwrXEYlENS6MiTsK',
     'Analista Demo',            'ANALISTA', 1, 0, 1);

-- ==============================================================================
-- SEED: Gerencias de ejemplo
-- ==============================================================================
INSERT IGNORE INTO gerencias (nombre) VALUES
    ('Gerencia de Tecnología'),
    ('Gerencia de Recursos Humanos'),
    ('Gerencia de Operaciones'),
    ('Gerencia de Finanzas');

SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- FIN DE SCRIPT
-- Ejecutar a continuación: 02_consolidacion_spanish.sql → fix_indexes.sql
-- ==============================================================================
