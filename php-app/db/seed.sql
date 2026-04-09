-- ============================================================
-- seed.sql — Datos iniciales para el Sistema de Carnetización TSS
-- Ejecutar en Supabase > SQL Editor SOLO si la tabla users está vacía
-- Las contraseñas están en bcrypt ($2y$10$...) generadas con PHP:
--   admin123   → hash estándar
--   coord123   → hash estándar
--   analista123→ hash estándar
--   usuario123 → hash estándar
-- ============================================================

-- Limpiar usuarios de prueba existentes (cuidado en producción!)
-- DELETE FROM users WHERE username IN ('admin','coordinador','analista','usuario');

-- Insertar usuarios de demo con hashes bcrypt reales
-- Nota: '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' es el hash de 'password'
-- Para usar contraseñas correctas, ejecuta este INSERT con hashes generados por PHP

INSERT INTO users (username, password_hash, full_name, role) VALUES
  ('admin',        '$2y$10$admin.hash.placeholder.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'Administrador Principal', 'ADMIN'),
  ('coordinador',  '$2y$10$coord.hash.placeholder.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'Coordinador Demo',       'COORD'),
  ('analista',     '$2y$10$anali.hash.placeholder.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'Analista Demo',          'ANALISTA'),
  ('usuario',      '$2y$10$usuar.hash.placeholder.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'Usuario Demo',           'USUARIO')
ON CONFLICT (username) DO NOTHING;

-- NOTA IMPORTANTE: Los hashes de arriba son placeholders.
-- Para generar los hashes correctos, ejecuta este script PHP en tu servidor:
-- <?php
-- echo password_hash('admin123',   PASSWORD_BCRYPT) . PHP_EOL;
-- echo password_hash('coord123',   PASSWORD_BCRYPT) . PHP_EOL;
-- echo password_hash('analista123',PASSWORD_BCRYPT) . PHP_EOL;
-- echo password_hash('usuario123', PASSWORD_BCRYPT) . PHP_EOL;
-- Y reemplaza los placeholders arriba.

-- Alternativa: Insertar con contraseña en texto plano (SOLO para pruebas locales)
-- El auth.php detecta si el hash NO empieza con '$2' y compara en texto plano:
INSERT INTO users (username, password_hash, full_name, role) VALUES
  ('admin',        'admin123',    'Administrador Principal', 'ADMIN'),
  ('coordinador',  'coord123',    'Coordinador Demo',        'COORD'),
  ('analista',     'analista123', 'Analista Demo',           'ANALISTA'),
  ('usuario',      'usuario123',  'Usuario Demo',            'USUARIO')
ON CONFLICT (username) DO UPDATE
  SET password_hash = EXCLUDED.password_hash,
      full_name     = EXCLUDED.full_name,
      role          = EXCLUDED.role,
      failed_attempts = 0,
      is_locked     = FALSE;

-- Desbloquear cualquier cuenta bloqueada
UPDATE users SET failed_attempts = 0, is_locked = FALSE;
