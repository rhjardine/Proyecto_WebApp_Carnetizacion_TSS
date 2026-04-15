-- fix_configuracion_sistema.sql - Remediación CR-02
-- Crea la tabla de configuración institucional faltante de forma idempotente.

CREATE TABLE IF NOT EXISTS configuracion_sistema (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(100) NOT NULL UNIQUE,
    valor TEXT NULL,
    seccion VARCHAR(50) NOT NULL DEFAULT 'global',
    tipo ENUM('string', 'number', 'boolean', 'json') NOT NULL DEFAULT 'string',
    descripcion VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_configuracion_seccion (seccion),
    INDEX idx_configuracion_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;