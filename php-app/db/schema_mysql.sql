-- ============================================================
-- SISTEMA DE CARNETIZACIÓN INTELIGENTE (SCI-TSS)
-- Esquema de Base de Datos — MySQL/InnoDB
-- Motor: MySQL 8.x | InnoDB | UTF8MB4
-- Esquema destino: carnetizacion_tss
-- ============================================================
-- Nomenclatura: 100% en Español (normativa institucional TSS)
-- Conformidad ACID garantizada por InnoDB
-- Integridad referencial mediante FOREIGN KEY constraints
-- ============================================================

-- ── PRE-REQUISITO: Crear el esquema si no existe ─────────────
CREATE DATABASE IF NOT EXISTS carnetizacion_tss
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE carnetizacion_tss;

-- ── ASEGURAR LIMPIEZA ORDENADA (respeta dependencias FK) ─────
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS auditoria_logs;
DROP TABLE IF EXISTS empleados;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS gerencias;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- TABLA 1: gerencias
-- Catálogo de unidades organizativas del ente público.
-- Se crea ANTES que usuarios y empleados porque ambas la referencian.
-- ============================================================
CREATE TABLE gerencias (
    id            INT           NOT NULL AUTO_INCREMENT,
    nombre        VARCHAR(150)  NOT NULL,
    creado_el     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_el TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_gerencias_nombre (nombre)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de gerencias y unidades organizativas del ente público';

-- ============================================================
-- TABLA 2: usuarios
-- Gestión de acceso, roles y delegación de permisos.
-- ============================================================
CREATE TABLE usuarios (
    id               INT          NOT NULL AUTO_INCREMENT,
    usuario          VARCHAR(50)  NOT NULL,
    clave_hash       VARCHAR(255) NOT NULL                        COMMENT 'Hash bcrypt generado con PHP password_hash()',
    nombre_completo  VARCHAR(100) NOT NULL,
    rol              ENUM('ADMIN','COORD','ANALISTA','USUARIO','CONSULTA')
                                  NOT NULL DEFAULT 'USUARIO'      COMMENT 'Rol principal permanente del operador',
    rol_temporal     ENUM('ADMIN','COORD','ANALISTA','USUARIO','CONSULTA')
                                  NULL     DEFAULT NULL            COMMENT 'Permiso delegado temporalmente; NULL si no hay delegación activa',
    delegado_por     INT          NULL     DEFAULT NULL            COMMENT 'FK al usuario ADMIN que otorgó la delegación',
    rol_temporal_expira_en TIMESTAMP NULL  DEFAULT NULL            COMMENT 'Expiración automática del rol delegado',
    bloqueado        TINYINT(1)   NOT NULL DEFAULT 0               COMMENT '0 = activa, 1 = bloqueada por intentos fallidos o admin',
    intentos_fallidos INT         NOT NULL DEFAULT 0               COMMENT 'Contador de fallos consecutivos para mitigar fuerza bruta',
    clave_ultima_rotacion DATE    DEFAULT (CURRENT_DATE)           COMMENT 'Fecha de última actualización de contraseña',
    requiere_cambio_clave TINYINT(1) NOT NULL DEFAULT 0            COMMENT 'Obliga rotación en el próximo login (1=Sí, 0=No)',
    creado_el        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_el   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_usuario (usuario),

    -- Auto-referencia: quién delegó el rol temporal
    CONSTRAINT fk_usuarios_delegado_por
        FOREIGN KEY (delegado_por)
        REFERENCES usuarios (id)
        ON DELETE SET NULL   -- Si se elimina el admin delegador, la trazabilidad queda en NULL (no se pierde el registro)
        ON UPDATE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Operadores del sistema con control de roles, bloqueos y delegación';

-- ============================================================
-- TABLA 3: empleados
-- Sujetos de carnetización. Entidad central del sistema.
-- ============================================================
CREATE TABLE empleados (
    id               INT          NOT NULL AUTO_INCREMENT,
    nacionalidad     CHAR(1)      NOT NULL DEFAULT 'V'            COMMENT 'V = Venezolano, E = Extranjero',
    cedula           VARCHAR(20)  NOT NULL                        COMMENT 'Solo dígitos (0-9). El prefijo V/E se gestiona en el campo nacionalidad',
    primer_nombre    VARCHAR(50)  NOT NULL,
    segundo_nombre   VARCHAR(50)  NULL     DEFAULT NULL,
    primer_apellido  VARCHAR(50)  NOT NULL,
    segundo_apellido VARCHAR(50)  NULL     DEFAULT NULL,
    cargo            VARCHAR(150) NOT NULL                        COMMENT 'Denominación oficial del puesto de trabajo',
    gerencia_id      INT          NULL     DEFAULT NULL,
    fecha_ingreso    DATE         NOT NULL                        COMMENT 'Antigüedad en la institución',
    estado_laboral   ENUM('Activo','Inactivo')
                                  NOT NULL DEFAULT 'Activo',
    foto_url         VARCHAR(1000) NULL     DEFAULT NULL
        COMMENT 'URL pública o data:image/jpeg;base64,... para preproducción',
    nivel_permiso    VARCHAR(50)  NULL     DEFAULT 'Nivel 1'
        COMMENT 'Nivel de acceso institucional del funcionario',
    foto_ruta        VARCHAR(255) NULL     DEFAULT NULL           COMMENT 'Ruta absoluta en el sistema de archivos del servidor (para procesamiento GD)',
    estado_carnet    ENUM('Pendiente por Imprimir','Carnet Impreso','Carnet Entregado')
                                  NOT NULL DEFAULT 'Pendiente por Imprimir',
    forma_entrega    VARCHAR(50)  NULL     DEFAULT NULL           COMMENT 'Manual, Digital, etc.',
    creado_el        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_el   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_empleados_cedula (cedula),

    -- ── DIRECTIVA CRÍTICA: Validar que cédula sea numérica pura ──
    -- Previene el almacenamiento de prefijos 'V' o 'E' en la columna de cédula.
    -- La lógica de negocio (V/E) reside exclusivamente en el campo `nacionalidad`.
    CONSTRAINT chk_cedula_solo_numerica
        CHECK (cedula REGEXP '^[0-9]+$'),

    -- Validar que nacionalidad sea únicamente 'V' o 'E'
    CONSTRAINT chk_nacionalidad_valida
        CHECK (nacionalidad IN ('V', 'E')),

    -- FK hacia gerencias con SET NULL: si se elimina una gerencia,
    -- el empleado conserva su registro histórico (gerencia_id queda en NULL).
    CONSTRAINT fk_empleados_gerencia
        FOREIGN KEY (gerencia_id)
        REFERENCES gerencias (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Funcionarios y contratistas sujetos al proceso de carnetización';

-- ============================================================
-- TABLA 4: auditoria_logs
-- Registro inmutable de operaciones sensibles.
-- NEVER DELETE — Solo INSERT, nunca UPDATE/DELETE a nivel de aplicación.
-- ============================================================
CREATE TABLE auditoria_logs (
    id             INT          NOT NULL AUTO_INCREMENT,
    usuario_id     INT          NOT NULL                          COMMENT 'Operador que ejecutó la acción (FK a usuarios)',
    accion         VARCHAR(50)  NOT NULL                          COMMENT 'Código de operación: EMPLEADO_CREADO, FOTO_ACTUALIZADA, etc.',
    detalles       JSON         NULL                              COMMENT 'Payload JSON con los datos de la operación para análisis',
    direccion_ip   VARCHAR(45)  NULL     DEFAULT NULL             COMMENT 'IPv4 (max 15) o IPv6 (max 39) — 45 cubre ambos incluyendo dual-stack',
    agente_usuario TEXT         NULL                              COMMENT 'User-Agent del navegador/cliente HTTP',
    creado_el      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Marca de tiempo inmutable (sin ON UPDATE)',

    PRIMARY KEY (id),
    INDEX idx_auditoria_usuario_id (usuario_id),
    INDEX idx_auditoria_accion     (accion),
    INDEX idx_auditoria_creado_el  (creado_el),

    -- Integridad: todo log debe tener un autor válido
    CONSTRAINT fk_auditoria_usuario
        FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id)
        ON DELETE RESTRICT   -- No se puede eliminar un usuario con registros de auditoría
        ON UPDATE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro inmutable de todas las operaciones sensibles del sistema';

-- ============================================================
-- ÍNDICES ADICIONALES PARA OPTIMIZACIÓN DE CONSULTAS FRECUENTES
-- ============================================================

-- Búsqueda de empleados por nombre/apellido (dashboard principal)
CREATE INDEX idx_empleados_primer_apellido  ON empleados (primer_apellido);
CREATE INDEX idx_empleados_primer_nombre    ON empleados (primer_nombre);

-- Filtros por estado (conteos en el dashboard)
CREATE INDEX idx_empleados_estado_carnet    ON empleados (estado_carnet);
CREATE INDEX idx_empleados_estado_laboral   ON empleados (estado_laboral);

-- Búsqueda de usuarios para login (column ya es UNIQUE, el índice existe implícito)
-- Se agrega índice compuesto para validar usuario+bloqueado en un solo scan
CREATE INDEX idx_usuarios_usuario_bloqueado ON usuarios (usuario, bloqueado);
