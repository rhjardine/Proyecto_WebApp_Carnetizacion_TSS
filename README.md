# SCI-TSS (Sistema de Carnetización Inteligente)
Estado actual de desarrollo (Pre-Producción):

## Tareas de Seguridad Realizadas (Pre-Producción):
- **Delegaciones de Roles:** Se agregó columna `rol_temporal_expira_en`. `users.php` y `auth_check.php` actualizados para setear (24h) y revocar delegaciones vencidas. `delegado_por` garantizado.
- **Políticas de Contraseña:** Columnas `clave_ultima_rotacion` y `requiere_cambio_clave` agregadas. Endpoint `force-password-change.php` creado y validaciones de 90 días aplicadas. Hashes de BD rotados.
- **Hardcoded Credentials:** Desacopladas mediante variables de entorno (plantilla `.env.example` lista).
