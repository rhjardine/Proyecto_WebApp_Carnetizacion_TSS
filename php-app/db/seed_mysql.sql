-- ============================================================
-- SCI-TSS: seed_mysql.sql v2.1 — Script de Semilla Corregido
-- Esquema: carnetizacion_tss | Motor: MySQL 8.x / InnoDB
-- ============================================================
-- CAMBIOS v2.1:
--   - Columna de contraseña: clave_hash (NO password_hash)
--   - Columna de usuario:    usuario   (NO username)
--   - Inserción idempotente con ON DUPLICATE KEY UPDATE
-- ============================================================
-- INSTRUCCIONES:
--   1. Ejecutar PRIMERO schema_mysql.sql
--   2. Ejecutar este archivo:
--      mysql -u root -p carnetizacion_tss < seed_mysql.sql
-- ============================================================

USE carnetizacion_tss;

-- ── GERENCIAS INSTITUCIONALES DE LA TSS ─────────────────────
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
-- COLUMNA CORRECTA: clave_hash (no password_hash)
-- Hashes bcrypt generados con PHP password_hash('contraseña', PASSWORD_BCRYPT)
-- CONTRASEÑAS DE DEMO:
--   admin        → admin123
--   coordinador  → coord123
--   analista     → analista123
--   usuario      → usuario123
--   consulta     → consulta123
--
-- ⚠️  OBLIGATORIO: Cambiar contraseñas antes del despliegue a producción.
-- Para generar nuevos hashes: php -r "echo password_hash('nueva_clave', PASSWORD_BCRYPT);"
-- ────────────────────────────────────────────────────────────
INSERT INTO usuarios (usuario, clave_hash, nombre_completo, rol, bloqueado, intentos_fallidos)
VALUES
    ('admin',
     '$2y$10$INF/JbG/i3qMWhb0sDogIOBvUobRwpDLVoD3jVJK8qve9A8lsbrFu',
     'Administrador Principal SCI-TSS', 'ADMIN', 0, 0),

    ('coordinador',
     '$2y$10$dsMn7j3TdK1zwt29bTn9gusCA9p2imAfOh.fx5H3pg5LlCYF1wSiK',
     'Coordinador de Carnetizacion', 'COORD', 0, 0),

    ('analista',
     '$2y$10$AF/Hmu2CCUIAOnZqLuLdC.8Gq03lpghhq2lauiIORXP7UwHDTwcpi',
     'Analista de Datos', 'ANALISTA', 0, 0),

    ('usuario',
     '$2y$10$ZG/PnP0ipOoS22F5MvjIHerQZhaARBtXwemdqZXeMm17bHQW1Q6ma',
     'Usuario Operativo', 'USUARIO', 0, 0),

    ('consulta',
     '$2y$10$SZgVlvnYF9ajomGyAGtGnektY7MnVztB8d6kkZi0h7ICHfsN93gyG',
     'Usuario Solo Consulta', 'CONSULTA', 0, 0)

ON DUPLICATE KEY UPDATE
    -- En re-ejecución: NO sobreescribir hashes de producción.
    -- Solo desbloquear cuentas y resetear intentos.
    bloqueado         = 0,
    intentos_fallidos = 0;

-- ── EMPLEADOS DE MUESTRA ─────────────────────────────────────
INSERT INTO empleados
    (nacionalidad, cedula, primer_nombre, segundo_nombre,
     primer_apellido, segundo_apellido, cargo, gerencia_id,
     fecha_ingreso, estado_laboral, estado_carnet)
SELECT 'V', '27798979', 'Nohely', 'Alexandra', 'Aponte', 'Contreras',
    'Apoyo Tecnico',
    (SELECT id FROM gerencias WHERE nombre = 'OFICINA DE COMUNICACION Y RELACIONES INSTITUCIONALES' LIMIT 1),
    '2022-03-15', 'Activo', 'Carnet Impreso'
WHERE NOT EXISTS (SELECT 1 FROM empleados WHERE cedula = '27798979');

INSERT INTO empleados
    (nacionalidad, cedula, primer_nombre, segundo_nombre,
     primer_apellido, segundo_apellido, cargo, gerencia_id,
     fecha_ingreso, estado_laboral, estado_carnet)
SELECT 'V', '11929185', 'Jose', 'Luis', 'Cisneros', 'Medina',
    'Oficial de Seguridad',
    (SELECT id FROM gerencias WHERE nombre = 'OFICINA DE ADMINISTRACION Y GESTION INTERNA' LIMIT 1),
    '2010-07-01', 'Activo', 'Carnet Entregado'
WHERE NOT EXISTS (SELECT 1 FROM empleados WHERE cedula = '11929185');

INSERT INTO empleados
    (nacionalidad, cedula, primer_nombre, segundo_nombre,
     primer_apellido, segundo_apellido, cargo, gerencia_id,
     fecha_ingreso, estado_laboral, estado_carnet)
SELECT 'V', '12345678', 'Juan', 'Alejandro', 'Perez', NULL,
    'Analista de Sistemas',
    (SELECT id FROM gerencias WHERE nombre = 'OFICINA DE TECNOLOGIA DE LA INFORMACION Y COMUNICACION' LIMIT 1),
    '2020-01-15', 'Activo', 'Pendiente por Imprimir'
WHERE NOT EXISTS (SELECT 1 FROM empleados WHERE cedula = '12345678');

INSERT INTO empleados
    (nacionalidad, cedula, primer_nombre, segundo_nombre,
     primer_apellido, segundo_apellido, cargo, gerencia_id,
     fecha_ingreso, estado_laboral, estado_carnet)
SELECT 'V', '87654321', 'Maria', 'Victoria', 'Gomez', NULL,
    'Coordinadora',
    (SELECT id FROM gerencias WHERE nombre = 'OFICINA DE ADMINISTRACION Y GESTION INTERNA' LIMIT 1),
    '2019-05-20', 'Activo', 'Carnet Impreso'
WHERE NOT EXISTS (SELECT 1 FROM empleados WHERE cedula = '87654321');

-- ── REGISTRO INICIAL DE AUDITORÍA ────────────────────────────
INSERT INTO auditoria_logs (usuario_id, accion, detalles, direccion_ip, agente_usuario)
SELECT
    id,
    'SISTEMA_INICIALIZADO',
    JSON_OBJECT(
        'version',     '2.1.0-preproduccion',
        'descripcion', 'Seed v2.1 - Columnas corregidas al esquema en español',
        'motor',       'MySQL 8.x / InnoDB',
        'esquema',     'carnetizacion_tss',
        'fecha',       NOW()
    ),
    '127.0.0.1',
    'SCI-TSS Seed Script v2.1'
FROM usuarios
WHERE usuario = 'admin'
LIMIT 1;

-- ── VERIFICACIÓN POST-INSERCIÓN ──────────────────────────────
SELECT '✔ GERENCIAS' AS entidad, COUNT(*) AS total FROM gerencias  UNION ALL
SELECT '✔ USUARIOS'  AS entidad, COUNT(*) AS total FROM usuarios   UNION ALL
SELECT '✔ EMPLEADOS' AS entidad, COUNT(*) AS total FROM empleados  UNION ALL
SELECT '✔ AUDITORIA' AS entidad, COUNT(*) AS total FROM auditoria_logs;