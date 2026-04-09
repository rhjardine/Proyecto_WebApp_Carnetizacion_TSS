-- Carnetización TSS - PostgreSQL Schema

-- Extension for UUID generation if needed (optional, but good practice)
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ROLES ENUM
CREATE TYPE user_role AS ENUM ('ADMIN', 'COORD', 'ANALISTA', 'USUARIO');

-- TABLE: gerencias
CREATE TABLE gerencias (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- TABLE: users (Usuarios del sistema)
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150),
    role user_role DEFAULT 'USUARIO',
    failed_attempts INTEGER DEFAULT 0,
    is_locked BOOLEAN DEFAULT FALSE,
    temporary_role user_role NULL,
    delegated_by INTEGER NULL REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- TABLE: employees (Funcionarios a carnetizar)
CREATE TABLE employees (
    id SERIAL PRIMARY KEY,
    cedula VARCHAR(20) NOT NULL UNIQUE,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    cargo VARCHAR(150) NOT NULL,
    gerencia_id INTEGER REFERENCES gerencias(id),
    nacionalidad CHAR(1) DEFAULT 'V' CHECK (nacionalidad IN ('V', 'E')),
    nivel_permiso VARCHAR(50) DEFAULT 'Nivel 1',
    status VARCHAR(50) DEFAULT 'Pendiente por Imprimir',
    forma_entrega VARCHAR(50) NULL,
    fecha_ingreso DATE,
    photo_path TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- TABLE: audit_logs (Historial de acciones)
CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    employee_id INTEGER NULL REFERENCES employees(id),
    action VARCHAR(100) NOT NULL,
    details JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default Gerencias
INSERT INTO gerencias (nombre) VALUES
('Dirección General'),
('Finanzas'),
('Recursos Humanos'),
('Tecnología e Informática'),
('Contraloría Interna'),
('Operaciones'),
('Recaudación y Cobros'),
('Asuntos Jurídicos'),
('Planificación y Presupuesto');

-- Default Admin User (Password should be hashed in production, this is a placeholder)
-- Placeholder password is 'admin123'
INSERT INTO users (username, password_hash, full_name, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador Principal', 'ADMIN');
