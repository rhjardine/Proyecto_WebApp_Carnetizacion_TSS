-- 02_update_recovery.sql
-- Script de actualización incremental del esquema de base de datos para la recuperación de contraseñas.
-- SCI-TSS v3.2.0

USE carnetizacion_tss;

-- Agregar nuevos campos a la tabla usuarios sin afectar los datos existentes
ALTER TABLE usuarios
    ADD COLUMN email VARCHAR(150) UNIQUE NULL AFTER nombre_completo,
    ADD COLUMN reset_token_hash VARCHAR(64) NULL AFTER last_login_ip,
    ADD COLUMN reset_token_expira DATETIME NULL AFTER reset_token_hash;

-- Crear índice de búsqueda rápida sobre el hash del token de recuperación
ALTER TABLE usuarios
    ADD INDEX idx_usuarios_reset_token (reset_token_hash);
