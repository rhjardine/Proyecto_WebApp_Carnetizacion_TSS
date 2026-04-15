-- fix_indexes.sql - Remediación de índices para despliegues con drift de schema
-- Ejecuta de forma idempotente sobre tablas English y/o Spanish si existen.

DROP PROCEDURE IF EXISTS add_index_if_missing;

DELIMITER $$
CREATE PROCEDURE add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_required_columns VARCHAR(255),
    IN p_ddl TEXT
)
BEGIN
    DECLARE v_needed INT DEFAULT 0;
    DECLARE v_present INT DEFAULT 0;

    SELECT 1 + LENGTH(p_required_columns) - LENGTH(REPLACE(p_required_columns, ',', ''))
      INTO v_needed;

    SELECT COUNT(*)
      INTO v_present
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = p_table_name
       AND FIND_IN_SET(column_name, p_required_columns) > 0;

    IF EXISTS (
        SELECT 1
          FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = p_table_name
    )
    AND NOT EXISTS (
        SELECT 1
          FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = p_table_name
           AND index_name = p_index_name
    )
    AND v_present = v_needed THEN
        SET @ddl = p_ddl;
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- Tablas Spanish en uso por la aplicación actual
CALL add_index_if_missing('empleados', 'idx_empleados_cedula', 'cedula', 'ALTER TABLE empleados ADD INDEX idx_empleados_cedula (cedula)');
CALL add_index_if_missing('empleados', 'idx_empleados_estado_carnet', 'estado_carnet', 'ALTER TABLE empleados ADD INDEX idx_empleados_estado_carnet (estado_carnet)');
CALL add_index_if_missing('empleados', 'idx_empleados_gerencia_id', 'gerencia_id', 'ALTER TABLE empleados ADD INDEX idx_empleados_gerencia_id (gerencia_id)');
CALL add_index_if_missing('empleados', 'idx_empleados_nombres', 'primer_nombre,primer_apellido', 'ALTER TABLE empleados ADD INDEX idx_empleados_nombres (primer_nombre, primer_apellido)');
CALL add_index_if_missing('empleados', 'idx_empleados_fecha_ingreso', 'fecha_ingreso', 'ALTER TABLE empleados ADD INDEX idx_empleados_fecha_ingreso (fecha_ingreso)');

CALL add_index_if_missing('usuarios', 'idx_usuarios_usuario', 'usuario', 'ALTER TABLE usuarios ADD INDEX idx_usuarios_usuario (usuario)');
CALL add_index_if_missing('usuarios', 'idx_usuarios_rol', 'rol', 'ALTER TABLE usuarios ADD INDEX idx_usuarios_rol (rol)');

CALL add_index_if_missing('auditoria_logs', 'idx_auditoria_logs_fecha', 'creado_el', 'ALTER TABLE auditoria_logs ADD INDEX idx_auditoria_logs_fecha (creado_el)');
CALL add_index_if_missing('auditoria_logs', 'idx_auditoria_logs_usuario', 'usuario_id', 'ALTER TABLE auditoria_logs ADD INDEX idx_auditoria_logs_usuario (usuario_id)');
CALL add_index_if_missing('auditoria_logs', 'idx_auditoria_logs_accion', 'accion', 'ALTER TABLE auditoria_logs ADD INDEX idx_auditoria_logs_accion (accion)');

-- Compatibilidad con tablas English aún presentes en algunos entornos
CALL add_index_if_missing('employees', 'idx_employees_cedula', 'cedula', 'ALTER TABLE employees ADD INDEX idx_employees_cedula (cedula)');
CALL add_index_if_missing('employees', 'idx_employees_gerencia_id', 'gerencia_id', 'ALTER TABLE employees ADD INDEX idx_employees_gerencia_id (gerencia_id)');
CALL add_index_if_missing('employees', 'idx_employees_nombres', 'nombres,apellidos', 'ALTER TABLE employees ADD INDEX idx_employees_nombres (nombres, apellidos)');

CALL add_index_if_missing('users', 'idx_users_username', 'username', 'ALTER TABLE users ADD INDEX idx_users_username (username)');

CALL add_index_if_missing('audit_log', 'idx_audit_log_created_at', 'created_at', 'ALTER TABLE audit_log ADD INDEX idx_audit_log_created_at (created_at)');
CALL add_index_if_missing('audit_log', 'idx_audit_log_user_id', 'user_id', 'ALTER TABLE audit_log ADD INDEX idx_audit_log_user_id (user_id)');
CALL add_index_if_missing('audit_log', 'idx_audit_log_action', 'action', 'ALTER TABLE audit_log ADD INDEX idx_audit_log_action (action)');

DROP PROCEDURE IF EXISTS add_index_if_missing;