-- Migration Script: 03_update_empleados_email.sql
-- Description: Extends the 'empleados' table to include the email address of the employee,
--              required for handling credentials/recovery or communications.
--              Allows NULL to prevent breaking existing records, and maintains uniqueness.
--
-- IMPORTANTE: esta columna NO es opcional para la aplicación. api/employees.php la incluye
-- en el INSERT de alta de empleados, de modo que sin esta migración el registro de nómina
-- falla con "Unknown column 'email' in 'field list'".
--
-- Idempotente: puede reimportarse sin fallar con "Duplicate column name" si ya se ejecutó.

-- Contexto de base de datos (corregido: antes apuntaba a `control_carnet`, que no existe
-- en ningún despliegue de SCI-TSS; el esquema real es `carnetizacion_tss`).
USE `carnetizacion_tss`;

SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empleados' AND COLUMN_NAME = 'email') = 0,
    'ALTER TABLE `empleados` ADD COLUMN `email` VARCHAR(150) NULL UNIQUE AFTER `cargo` COMMENT ''Correo electrónico institucional o personal del funcionario''',
    'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
