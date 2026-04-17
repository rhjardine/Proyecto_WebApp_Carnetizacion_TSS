-- 03_cleanup_legacy.sql
-- Script de limpieza legacy (DROP) para entornos donde la migración
-- haya sido verificada y aprobada por DBA.
-- ADVERTENCIA: Este archivo NO debe ejecutarse en producción sin
-- revisión y respaldo previo. Ejecutar sólo después de completar
-- DB-1.2 y DB-1.3 y validar que no quedan clientes apuntando a endpoints legacy.

-- Ejemplo de DROP (descomentarlas SOLO tras validación completa):
-- DROP TABLE IF EXISTS employees;
-- DROP TABLE IF EXISTS users;
-- DROP TABLE IF EXISTS user_role;
-- DROP TABLE IF EXISTS role_permission;
-- DROP TABLE IF EXISTS temporary_permissions;

-- Recomendación: Realizar backup + comprobar que las tablas spanish
-- (`usuarios`, `empleados`, `usuario_rol`, `rol_permiso`) contienen
-- todos los datos y que no hay dependencias externas.
