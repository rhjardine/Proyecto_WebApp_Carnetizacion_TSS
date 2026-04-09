-- ============================================================
-- Sistema de Carnetización TSS — Database Schema
-- Run this once against your PostgreSQL `carnetizacion_db`
-- ============================================================

-- Users table (admin accounts)
CREATE TABLE IF NOT EXISTS users (
    id         SERIAL       PRIMARY KEY,
    username   VARCHAR(100) UNIQUE NOT NULL,
    password   VARCHAR(255) NOT NULL,       -- bcrypt hash
    role       VARCHAR(50)  NOT NULL DEFAULT 'ADMIN',
    created_at TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- Employees table
CREATE TABLE IF NOT EXISTS employees (
    id             SERIAL       PRIMARY KEY,
    cedula         VARCHAR(20)  UNIQUE NOT NULL,
    nombre         VARCHAR(150) NOT NULL,
    cargo          VARCHAR(150) NOT NULL,
    departamento   VARCHAR(150) NOT NULL,
    tipo_sangre    VARCHAR(5),
    nss            VARCHAR(50),
    fecha_ingreso  DATE         NOT NULL DEFAULT CURRENT_DATE,
    photo_url      VARCHAR(300),
    status         VARCHAR(30)  NOT NULL DEFAULT 'Pendiente',
    qr_code        TEXT,
    created_at     TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- Audit log
CREATE TABLE IF NOT EXISTS audit_log (
    id         SERIAL       PRIMARY KEY,
    action     VARCHAR(100) NOT NULL,
    details    TEXT,
    user_id    INT          REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- Default admin user (password: admin123)
INSERT INTO users (username, password, role)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ADMIN')
ON CONFLICT (username) DO NOTHING;

-- Trigger to auto-update `updated_at`
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
   NEW.updated_at = NOW();
   RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE TRIGGER update_employees_updated_at
    BEFORE UPDATE ON employees
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE OR REPLACE TRIGGER update_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
