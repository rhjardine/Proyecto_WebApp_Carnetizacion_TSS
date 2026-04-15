<?php
/**
 * config_fixed.php - Configuración centralizada unificada
 * SCI-TSS v2.6.0
 */

if (!defined('APP_VERSION')) {
    define('APP_VERSION', getenv('APP_VERSION') ?: '2.6.0');
}
if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('APP_ENV') ?: 'development');
}
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: (APP_ENV !== 'production'), FILTER_VALIDATE_BOOLEAN));
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
}
if (!defined('DB_PORT')) {
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'carnetizacion_tss');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') ?: '');
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');
}

if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', (int) (getenv('SESSION_LIFETIME') ?: 3600));
}
if (!defined('SESSION_SECURE')) {
    define('SESSION_SECURE', filter_var(getenv('SESSION_SECURE') ?: (APP_ENV === 'production'), FILTER_VALIDATE_BOOLEAN));
}
if (!defined('SESSION_HTTPONLY')) {
    define('SESSION_HTTPONLY', filter_var(getenv('SESSION_HTTPONLY') ?: true, FILTER_VALIDATE_BOOLEAN));
}
if (!defined('SESSION_SAMESITE')) {
    define('SESSION_SAMESITE', getenv('SESSION_SAMESITE') ?: 'Strict');
}

if (!defined('ENFORCE_HTTPS')) {
    define('ENFORCE_HTTPS', filter_var(getenv('ENFORCE_HTTPS') ?: (APP_ENV === 'production'), FILTER_VALIDATE_BOOLEAN));
}
if (!defined('ENFORCE_CSRF')) {
    define('ENFORCE_CSRF', filter_var(getenv('ENFORCE_CSRF') ?: true, FILTER_VALIDATE_BOOLEAN));
}
if (!defined('CSRF_TOKEN_NAME')) {
    define('CSRF_TOKEN_NAME', 'csrf_token');
}
if (!defined('CSRF_TOKEN_LENGTH')) {
    define('CSRF_TOKEN_LENGTH', 32);
}
if (!defined('CSRF_TOKEN_EXPIRY')) {
    define('CSRF_TOKEN_EXPIRY', (int) (getenv('CSRF_TOKEN_EXPIRY') ?: 7200));
}

if (!defined('PASSWORD_MIN_LENGTH')) {
    define('PASSWORD_MIN_LENGTH', (int) (getenv('PASSWORD_MIN_LENGTH') ?: 12));
}
if (!defined('PASS_ROTATION_DAYS')) {
    define('PASS_ROTATION_DAYS', (int) (getenv('PASSWORD_EXPIRY_DAYS') ?: getenv('PASS_ROTATION_DAYS') ?: 90));
}
if (!defined('PASSWORD_HISTORY_COUNT')) {
    define('PASSWORD_HISTORY_COUNT', 5);
}

if (!defined('LOGIN_MAX_ATTEMPTS')) {
    define('LOGIN_MAX_ATTEMPTS', (int) (getenv('LOGIN_MAX_ATTEMPTS') ?: 5));
}
if (!defined('LOGIN_LOCKOUT_MINUTES')) {
    define('LOGIN_LOCKOUT_MINUTES', (int) (getenv('LOGIN_LOCKOUT_MINUTES') ?: 15));
}
if (!defined('API_RATE_LIMIT')) {
    define('API_RATE_LIMIT', (int) (getenv('API_RATE_LIMIT') ?: 100));
}

if (!defined('MAX_PHOTO_SIZE')) {
    define('MAX_PHOTO_SIZE', (int) (getenv('MAX_PHOTO_SIZE') ?: 5242880));
}
if (!defined('ALLOWED_PHOTO_TYPES')) {
    define('ALLOWED_PHOTO_TYPES', ['image/jpeg', 'image/png', 'image/webp']);
}

if (!defined('DEFAULT_PAGE_SIZE')) {
    define('DEFAULT_PAGE_SIZE', 50);
}
if (!defined('MAX_PAGE_SIZE')) {
    define('MAX_PAGE_SIZE', 200);
}
if (!defined('API_BASE_PATH')) {
    define('API_BASE_PATH', '/api');
}
if (!defined('API_VERSION')) {
    define('API_VERSION', 'v1');
}
if (!defined('APP_NAME')) {
    define('APP_NAME', getenv('APP_NAME') ?: 'SCI-TSS');
}
if (!defined('LOG_AUDIT')) {
    define('LOG_AUDIT', true);
}
if (!defined('LOG_FILE')) {
    define('LOG_FILE', getenv('LOG_PATH') ?: (__DIR__ . '/../../logs/audit.log'));
}

date_default_timezone_set('America/Caracas');

ini_set('session.cookie_httponly', SESSION_HTTPONLY ? '1' : '0');
ini_set('session.cookie_secure', SESSION_SECURE ? '1' : '0');
ini_set('session.cookie_samesite', SESSION_SAMESITE);
ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}