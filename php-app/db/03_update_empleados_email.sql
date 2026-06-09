-- Migration Script: 03_update_empleados_email.sql
-- Description: Extends the 'empleados' table to include the email address of the employee,
--              required for handling credentials/recovery or communications.
--              Allows NULL to prevent breaking existing records, and maintains uniqueness.

USE `control_carnet`; -- Asegurar el contexto de la base de datos (por si se ejecuta en consola general)

ALTER TABLE `empleados`
ADD COLUMN `email` VARCHAR(150) NULL UNIQUE AFTER `cargo` 
COMMENT 'Correo electrónico institucional o personal del funcionario';
