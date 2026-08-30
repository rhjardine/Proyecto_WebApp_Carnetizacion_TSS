-- 02_update_recovery.sql
-- Script de actualización incremental del esquema de base de datos para la recuperación de contraseñas.
-- SCI-TSS v3.2.0
--
-- Idempotente: puede reimportarse sin fallar con "Duplicate column name" si ya se ejecutó.

USE carnetizacion_tss;

-- Agregar nuevos campos a la tabla usuarios sin afectar los datos existentes

SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'email') = 0,
    'ALTER TABLE usuarios ADD COLUMN email VARCHAR(150) UNIQUE NULL AFTER nombre_completo',
    'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'reset_token_hash') = 0,
    'ALTER TABLE usuarios ADD COLUMN reset_token_hash VARCHAR(64) NULL AFTER last_login_ip',
    'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'reset_token_expira') = 0,
    'ALTER TABLE usuarios ADD COLUMN reset_token_expira DATETIME NULL AFTER reset_token_hash',
    'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Crear índice de búsqueda rápida sobre el hash del token de recuperación

SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'idx_usuarios_reset_token') = 0,
    'ALTER TABLE usuarios ADD INDEX idx_usuarios_reset_token (reset_token_hash)',
    'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
