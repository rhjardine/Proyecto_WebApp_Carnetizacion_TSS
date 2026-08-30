-- 04_nomina_importaciones.sql
-- Base documental de importaciones de nómina — SCI-TSS
-- ==============================================================================
-- Registra CADA importación con su archivo original y el destino de cada fila.
-- Responde a la pregunta de auditoría "¿de dónde salieron los datos de este
-- carnet?", que hasta ahora no tenía respuesta: la importación anterior escribía
-- en `empleados` sin dejar rastro del archivo de origen.
--
-- Idempotente: puede reimportarse sin efectos secundarios.

USE `carnetizacion_tss`;

-- Cabecera: un registro por archivo cargado.
CREATE TABLE IF NOT EXISTS nomina_importaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL COMMENT 'Operador que cargó el archivo',
    nombre_archivo VARCHAR(255) NOT NULL COMMENT 'Nombre original tal como lo subió el operador',
    archivo_hash CHAR(64) NOT NULL COMMENT 'SHA-256 del contenido, para detectar recargas del mismo archivo',
    archivo_ruta VARCHAR(500) NULL COMMENT 'Ruta relativa dentro de storage/nomina',
    formato VARCHAR(10) NOT NULL COMMENT 'xlsx | csv | docx',
    tamano_bytes INT UNSIGNED NOT NULL DEFAULT 0,

    -- 'analizado' = leído y previsualizado, sin escribir en empleados todavía.
    estado ENUM('analizado','confirmado','descartado') NOT NULL DEFAULT 'analizado',

    mapeo_columnas JSON NULL COMMENT 'Correspondencia campo -> encabezado usada en la confirmación',
    encabezados JSON NULL COMMENT 'Encabezados detectados en el archivo',

    total_filas INT NOT NULL DEFAULT 0,
    nuevos INT NOT NULL DEFAULT 0,
    actualizados INT NOT NULL DEFAULT 0,
    sin_cambios INT NOT NULL DEFAULT 0,
    invalidos INT NOT NULL DEFAULT 0,

    creado_el TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmado_el DATETIME NULL,

    INDEX idx_nomina_estado (estado, creado_el),
    INDEX idx_nomina_hash (archivo_hash),
    CONSTRAINT fk_nomina_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Detalle: una fila del archivo y qué se decidió hacer con ella.
CREATE TABLE IF NOT EXISTS nomina_filas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    importacion_id INT NOT NULL,
    numero_fila INT NOT NULL COMMENT 'Número de fila en el archivo original, para ubicar el error',

    datos_originales JSON NOT NULL COMMENT 'La fila tal como venía, antes de normalizar',
    cedula VARCHAR(20) NULL,

    -- Decidido durante el análisis; se recalcula al confirmar por si la base
    -- cambió entre la vista previa y la confirmación.
    accion ENUM('nuevo','actualizar','sin_cambios','error') NOT NULL,
    motivo_error VARCHAR(255) NULL,
    empleado_id INT NULL COMMENT 'Empleado creado o actualizado por esta fila',

    INDEX idx_filas_importacion (importacion_id, accion),
    INDEX idx_filas_cedula (cedula),
    CONSTRAINT fk_filas_importacion FOREIGN KEY (importacion_id)
        REFERENCES nomina_importaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permiso propio: importar nómina masivamente no es lo mismo que registrar un
-- empleado suelto, y conviene poder delegarlo por separado.
INSERT IGNORE INTO permisos (id, nombre, descripcion, recurso, accion) VALUES
    (9, 'nomina.import', 'Importar nómina desde archivo', 'nomina', 'import');

-- Se otorga a ADMIN. Para COORD u otros roles, la asignación es una decisión
-- de política que debe documentarse (ver POL-03 del registro normativo).
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) VALUES (1, 9);
