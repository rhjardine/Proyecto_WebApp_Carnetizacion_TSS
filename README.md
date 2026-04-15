# SCI-TSS (Sistema de Carnetización Inteligente)

Sistema de gestión y emisión de carnets institucionales para la **Tesorería de Seguridad Social (TSS)**.

## Características
- Gestión de personal y solicitudes de carnetización.
*   Editor visual con plantillas institucionales (2024/2025).
*   Exportación a PDF optimizada para impresoras PVC.
*   Control de acceso basado en roles (RBAC).
*   Validación de carnets mediante códigos QR.

## Requisitos
- Servidor Web (Apache/Nginx).
- PHP 8.0 o superior.
- MySQL 5.7 o superior.
- XAMPP recomendado para despliegue local.

## Instalación
1. Clonar el repositorio.
2. Configurar la base de datos importando el esquema inicial.
3. Copiar `.env.example` a `.env` y configurar las credenciales.
4. Apuntar el DocumentRoot a la carpeta del proyecto.

## Uso
- El sistema cuenta con roles de Administrador, Coordinador, Analista y Usuario.
- El login por defecto requiere cambio de contraseña en el primer ingreso.

---
© 2026 Tesorería de Seguridad Social - República Bolivariana de Venezuela