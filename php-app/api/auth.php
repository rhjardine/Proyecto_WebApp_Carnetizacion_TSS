<?php
/**
 * api/auth.php — SHIM de Compatibilidad (Deprecated)
 * ====================================================
 * ESTE ARCHIVO ES UN REDIRECT DE COMPATIBILIDAD.
 * El endpoint canónico es: api/auth/login.php
 *
 * api.js fue actualizado para llamar directamente a api/auth/login.php.
 * Este shim garantiza que cualquier cliente legado que aún apunte
 * a api/auth.php siga funcionando sin errores.
 *
 * NO agregar lógica de negocio aquí. Toda la autenticación ocurre
 * en api/auth/login.php → Security::loginUser() en RBAC.php.
 */
require_once __DIR__ . '/auth/login.php';