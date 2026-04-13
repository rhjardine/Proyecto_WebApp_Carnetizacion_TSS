# SCI-TSS (Sistema de Carnetización Inteligente)
Estado actual de desarrollo (Pre-Producción):

## Tareas de Seguridad Realizadas (Pre-Producción):
- **Delegaciones de Roles:** Se agregó columna `rol_temporal_expira_en`. `users.php` y `auth_check.php` actualizados para setear (24h) y revocar delegaciones vencidas. `delegado_por` garantizado.
- **Políticas de Contraseña:** Columnas `clave_ultima_rotacion` y `requiere_cambio_clave` agregadas. Endpoint `force-password-change.php` creado y validaciones de 90 días aplicadas. Hashes de BD rotados.
- **Hardcoded Credentials:** Desacopladas mediante variables de entorno (plantilla `.env.example` lista).

## Priorización de Seguridad (Ajustada)
- **T8 (Cambio forzado de contraseña) se considera CRÍTICA** y se ejecuta antes de continuar con flujos operativos.
- El frontend respeta `requires_password_change` desde login y el middleware bloquea llamadas mutantes hasta completar la rotación.

## HTTPS local (XAMPP) — Advertencias operativas
1. Configurar primero certificados y VirtualHost SSL (`:443`).
2. **Solo después** activar `session.cookie_secure=1` y `ENFORCE_HTTPS=true`.
3. Registrar en `C:\\Windows\\System32\\drivers\\etc\\hosts`:
   - `127.0.0.1 sci-tss.local`
4. En navegadores Chrome/Edge el certificado autofirmado mostrará advertencia inicial; para preproducción se acepta continuar manualmente.
