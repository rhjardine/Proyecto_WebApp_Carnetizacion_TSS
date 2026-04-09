-- ============================================================
-- SISTEMA DE CARNETIZACIÓN INTELIGENTE (SCI-TSS)
-- Script DML de Semilla (Seed) — MySQL
-- Esquema: carnetizacion_tss
-- ============================================================
-- INSTRUCCIONES DE EJECUCIÓN:
--   1. Ejecutar PRIMERO schema_mysql.sql para crear las tablas.
--   2. Ejecutar este archivo: mysql -u root -p carnetizacion_tss < seed_mysql.sql
--   3. Los hashes bcrypt son reales ($2y$10$...) generados con PHP PASSWORD_BCRYPT.
-- ============================================================

USE carnetizacion_tss;

-- ── GERENCIAS INSTITUCIONALES DE LA TSS ─────────────────────
-- Inserción idempotente: si ya existen, no falla.
INSERT IGNORE INTO gerencias (nombre) VALUES
    ('DESPACHO'),
    ('AUDITORIA INTERNA'),
    ('CONSULTORIA JURIDICA'),
    ('OFICINA DE PLANIFICACION, ORGANIZACION Y PRESUPUESTO'),
    ('OFICINA DE ADMINISTRACION Y GESTION INTERNA'),
    ('OFICINA DE TECNOLOGIA DE LA INFORMACION Y COMUNICACION'),
    ('OFICINA DE COMUNICACION Y RELACIONES INSTITUCIONALES'),
    ('GERENCIA GENERAL DE REGISTRO Y AFILIACION'),
    ('GERENCIA GENERAL DE ESTUDIOS ACTUARIALES Y ECONOMICOS'),
    ('GERENCIA GENERAL DE INVERSIONES Y GESTION FINANCIERA'),
    ('OFICINA DE RELACIONES INTERINSTITUCIONALES');

-- ── USUARIOS DEL SISTEMA ─────────────────────────────────────
-- Hashes bcrypt generados con PHP: password_hash('contraseña', PASSWORD_BCRYPT)
-- CONTRASEÑAS:
--   admin        → admin123
--   coordinador  → coord123
--   analista     → analista123
--   usuario      → usuario123
--   consulta     → consulta123
--
-- ⚠️  CAMBIAR CONTRASEÑAS EN PRODUCCIÓN antes del despliegue.
-- ⚠️  Ejecutar en servidor PHP:
--     echo password_hash('nueva_clave', PASSWORD_BCRYPT);
-- ────────────────────────────────────────────────────────────

INSERT INTO usuarios (usuario, clave_hash, nombre_completo, rol, bloqueado, intentos_fallidos)
VALUES
    (
        'admin',
        '$2y$10$INF/JbG/i3qMWhb0sDogIOBvUobRwpDLVoD3jVJK8qve9A8lsbrFu',
        'Administrador Principal SCI-TSS',
        'ADMIN',
        0,
        0
    ),
    (
        'coordinador',
        '$2y$10$dsMn7j3TdK1zwt29bTn9gusCA9p2imAfOh.fx5H3pg5LlCYF1wSiK',
        'Coordinador de Carnetización',
        'COORD',
        0,
        0
    ),
    (
        'analista',
        '$2y$10$AF/Hmu2CCUIAOnZqLuLdC.8Gq03lpghhq2lauiIORXP7UwHDTwcpi',
        'Analista de Datos',
        'ANALISTA',
        0,
        0
    ),
    (
        'usuario',
        '$2y$10$ZG/PnP0ipOoS22F5MvjIHerQZhaARBtXwemdqZXeMm17bHQW1Q6ma',
        'Usuario Operativo',
        'USUARIO',
        0,
        0
    ),
    (
        'consulta',
        '$2y$10$SZgVlvnYF9ajomGyAGtGnektY7MnVztB8d6kkZi0h7ICHfsN93gyG',
        'Usuario Solo Consulta',
        'CONSULTA',
        0,
        0
    )
ON DUPLICATE KEY UPDATE
    -- En re-ejecución: NO sobreescribir hashes de producción,
    -- solo desbloquear y resetear intentos fallidos.
    bloqueado        = 0,
    intentos_fallidos = 0;

-- ── EMPLEADOS DE MUESTRA (datos ficticios para pruebas) ──────
-- Se insertan SOLO si la tabla está vacía para no duplicar en re-ejecución.
INSERT INTO empleados
    (nacionalidad, cedula, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido,
     cargo, gerencia_id, fecha_ingreso, estado_laboral, estado_carnet)
SELECT
    'V', '27798979', 'Nohely', 'Alexandra', 'Aponte', 'Contreras',
    'Apoyo Técnico',
    (SELECT id FROM gerencias WHERE nombre = 'OFICINA DE COMUNICACION Y RELACIONES INSTITUCIONALES' LIMIT 1),
    '2022-03-15', 'Activo', 'Carnet Impreso'
WHERE NOT EXISTS (SELECT 1 FROM empleados WHERE cedula = '27798979');

INSERT INTO empleados
    (nacionalidad, cedula, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido,
     cargo, gerencia_id, fecha_ingreso, estado_laboral, estado_carnet)
SELECT
    'V', '11929185', 'Jose', 'Luis', 'Cisneros', 'Medina',
    'Oficial de Seguridad',
    (SELECT id FROM gerencias WHERE nombre = 'OFICINA DE ADMINISTRACION Y GESTION INTERNA' LIMIT 1),
    '2010-07-01', 'Activo', 'Carnet Entregado'
WHERE NOT EXISTS (SELECT 1 FROM empleados WHERE cedula = '11929185');

INSERT INTO empleados
    (nacionalidad, cedula, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido,
     cargo, gerencia_id, fecha_ingreso, estado_laboral, estado_carnet)
SELECT
    'V', '12345678', 'Juan', 'Alejandro', 'Pérez', NULL,
    'Analista de Sistemas',
    (SELECT id FROM gerencias WHERE nombre = 'OFICINA DE TECNOLOGIA DE LA INFORMACION Y COMUNICACION' LIMIT 1),
    '2020-01-15', 'Activo', 'Pendiente por Imprimir'
WHERE NOT EXISTS (SELECT 1 FROM empleados WHERE cedula = '12345678');

INSERT INTO empleados
    (nacionalidad, cedula, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido,
     cargo, gerencia_id, fecha_ingreso, estado_laboral, estado_carnet)
SELECT
    'V', '87654321', 'María', 'Victoria', 'Gómez', NULL,
    'Coordinadora',
    (SELECT id FROM gerencias WHERE nombre = 'OFICINA DE ADMINISTRACION Y GESTION INTERNA' LIMIT 1),
    '2019-05-20', 'Activo', 'Carnet Impreso'
WHERE NOT EXISTS (SELECT 1 FROM empleados WHERE cedula = '87654321');

-- ── REGISTRO INICIAL DE AUDITORÍA ────────────────────────────
-- Documenta la instalación inicial del sistema en el log de auditoría.
INSERT INTO auditoria_logs (usuario_id, accion, detalles, direccion_ip, agente_usuario)
SELECT
    id,
    'SISTEMA_INICIALIZADO',
    JSON_OBJECT(
        'version',     '1.0.0-preproduccion',
        'descripcion', 'Instalación inicial del esquema SCI-TSS en MySQL',
        'motor',       'MySQL 8.x / InnoDB',
        'esquema',     'carnetizacion_tss',
        'fecha',       NOW()
    ),
    '127.0.0.1',
    'SCI-TSS Seed Script v1.0'
FROM usuarios
WHERE usuario = 'admin'
LIMIT 1;

-- ── VERIFICACIÓN POST-INSERCIÓN ──────────────────────────────
SELECT '✔ GERENCIAS'  AS entidad, COUNT(*) AS total FROM gerencias  UNION ALL
SELECT '✔ USUARIOS'   AS entidad, COUNT(*) AS total FROM usuarios   UNION ALL
SELECT '✔ EMPLEADOS'  AS entidad, COUNT(*) AS total FROM empleados  UNION ALL
SELECT '✔ AUDITORIA'  AS entidad, COUNT(*) AS total FROM auditoria_logs;
