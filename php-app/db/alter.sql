USE carnetizacion_tss;

-- Cleanup previous hallucinations if any
ALTER TABLE usuarios DROP COLUMN IF EXISTS cambio_clave_obligatorio;
ALTER TABLE usuarios DROP COLUMN IF EXISTS ultimo_cambio_clave;
ALTER TABLE usuarios DROP COLUMN IF EXISTS rol_temporal_expira_en;

-- Real fields requested
ALTER TABLE usuarios
    ADD COLUMN rol_temporal_expira_en TIMESTAMP NULL AFTER rol_temporal,
    ADD COLUMN clave_ultima_rotacion DATE DEFAULT (CURRENT_DATE) AFTER clave_hash,
    ADD COLUMN requiere_cambio_clave TINYINT(1) DEFAULT 0 AFTER clave_ultima_rotacion;
