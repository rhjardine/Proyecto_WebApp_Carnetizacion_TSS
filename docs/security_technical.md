# Documentación Técnica de Seguridad — SCI-TSS

Este documento contiene detalles de implementación interna y arquitectura de seguridad. **No debe hacerse público.**

## Implementación de Base de Datos
- **Delegaciones de Roles:** Se utiliza la columna `rol_temporal_expira_en` para control temporal. Los archivos `users.php` y `auth_check.php` gestionan la lógica de expiración (24h).
- **Políticas de Contraseña:**
    - Columnas: `clave_ultima_rotacion`, `requiere_cambio_clave`.
    - Endpoint: `force-password-change.php`.
    - Vigencia: 90 días para rotación forzada.

## Gestión de Credenciales
- Uso de variables de entorno mediante archivo `.env` (basado en `.env.example`).
- Los hashes de base de datos han sido rotados para cumplir con el estándar actual.

## Configuración de Entorno Seguro
- Middleware implementado para bloquear peticiones mutantes hasta que se complete el cambio de contraseña obligatorio.
- Validación de sesiones y tokens CSRF en endpoints críticos.
