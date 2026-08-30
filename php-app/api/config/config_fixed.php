<?php
/**
 * config_fixed.php - Configuración centralizada unificada con carga JIT de .env
 * SCI-TSS v2.6.0
 */

// ── Carga de entorno: DEBE ejecutarse ANTES de evaluar cualquier constante ────
if (!function_exists('loadEnv')) {
    /**
     * Lee un archivo .env y publica sus variables en $_ENV y putenv().
     *
     * El parser normaliza el valor antes de publicarlo porque el archivo lo edita
     * a mano el administrador del despliegue (habitualmente en Bloc de notas sobre
     * Windows/XAMPP). Sin esta normalización, escribir DB_PASS="miClave" entrega a
     * PDO la contraseña con las comillas incluidas y la conexión falla con un
     * "Access denied" indistinguible de una credencial realmente equivocada.
     *
     * @return bool true si el archivo existía y fue leído.
     */
    function loadEnv($path)
    {
        if (!is_readable($path)) {
            error_log("[SCI-TSS WARNING] Archivo de configuración .env no encontrado o sin permisos de lectura en la ruta esperada: " . $path . ". Usando fallbacks por defecto.");
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            error_log("[SCI-TSS WARNING] No se pudo leer el archivo .env en: " . $path . ". Usando fallbacks por defecto.");
            return false;
        }

        // El BOM UTF-8 que agregan Bloc de notas y PowerShell corrompería la primera clave.
        if (isset($lines[0])) {
            $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);
        }

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';')
                continue;
            if (strpos($line, '=') === false)
                continue;

            list($name, $value) = explode('=', $line, 2);

            // Prefijo "export" heredado de la sintaxis de shell.
            $name = preg_replace('/^export\s+/i', '', trim($name));

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                error_log("[SCI-TSS WARNING] .env línea " . ($lineNumber + 1) . ": nombre de variable inválido ('" . $name . "'). Línea ignorada.");
                continue;
            }

            $value = trim($value);

            $isQuoted = strlen($value) >= 2
                && (($value[0] === '"' && substr($value, -1) === '"')
                    || ($value[0] === "'" && substr($value, -1) === "'"));

            if ($isQuoted) {
                // Comillas envolventes: se descartan y el contenido se respeta literalmente
                // (una contraseña puede legítimamente contener '#' o espacios).
                $value = substr($value, 1, -1);
            } else {
                // Sin comillas: un ' #' o ' ;' inicia un comentario al final de la línea.
                $value = preg_replace('/\s+[#;].*$/', '', $value);
                $value = rtrim($value);
            }

            $_ENV[$name] = $value;
            putenv($name . '=' . $value);
        }

        return true;
    }
}

// Carga resiliente del entorno antes de evaluar constantes.
// Se conserva la ruta resuelta y el resultado para que el diagnóstico pueda distinguir
// "el .env no se leyó" de "el .env se leyó pero la credencial es incorrecta".
if (!defined('ENV_FILE_PATH')) {
    // dirname(__DIR__, 2) = raíz de la aplicación (php-app/), sin segmentos '..' que
    // confundan al administrador cuando el diagnóstico le muestre la ruta esperada.
    define('ENV_FILE_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env');
}
if (!defined('ENV_FILE_LOADED')) {
    define('ENV_FILE_LOADED', loadEnv(ENV_FILE_PATH));
}

// Helper resiliente para extraer variables de entorno desde getenv() o $_ENV (Evita fallos en arquitecturas CGI/FastCGI)
if (!function_exists('get_env_resilient')) {
    function get_env_resilient(string $key, $default = null)
    {
        $val = getenv($key);
        if ($val !== false) {
            return $val;
        }
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        return $default;
    }
}

// ─────────────────────────────────────────────────────────────────────────────

if (!defined('APP_VERSION')) {
    define('APP_VERSION', get_env_resilient('APP_VERSION', '2.6.0'));
}
if (!defined('APP_ENV')) {
    define('APP_ENV', get_env_resilient('APP_ENV', 'development'));
}
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', filter_var(get_env_resilient('APP_DEBUG', (APP_ENV !== 'production')), FILTER_VALIDATE_BOOLEAN));
}

if (!defined('DB_HOST')) {
    define('DB_HOST', get_env_resilient('DB_HOST', '127.0.0.1'));
}
if (!defined('DB_PORT')) {
    define('DB_PORT', get_env_resilient('DB_PORT', '3306'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', get_env_resilient('DB_NAME', 'carnetizacion_tss'));
}
if (!defined('DB_USER')) {
    define('DB_USER', get_env_resilient('DB_USER', 'root'));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', get_env_resilient('DB_PASS', ''));
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', get_env_resilient('DB_CHARSET', 'utf8mb4'));
}

if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', (int) (get_env_resilient('SESSION_LIFETIME', 3600)));
}
if (!defined('SESSION_SECURE')) {
    define('SESSION_SECURE', filter_var(get_env_resilient('SESSION_SECURE', (APP_ENV === 'production')), FILTER_VALIDATE_BOOLEAN));
}
if (!defined('SESSION_HTTPONLY')) {
    define('SESSION_HTTPONLY', filter_var(get_env_resilient('SESSION_HTTPONLY', true), FILTER_VALIDATE_BOOLEAN));
}
if (!defined('SESSION_SAMESITE')) {
    define('SESSION_SAMESITE', get_env_resilient('SESSION_SAMESITE', 'Strict'));
}

if (!defined('ENFORCE_HTTPS')) {
    define('ENFORCE_HTTPS', filter_var(get_env_resilient('ENFORCE_HTTPS', (APP_ENV === 'production')), FILTER_VALIDATE_BOOLEAN));
}
if (!defined('ENFORCE_CSRF')) {
    define('ENFORCE_CSRF', filter_var(get_env_resilient('ENFORCE_CSRF', true), FILTER_VALIDATE_BOOLEAN));
}
if (!defined('CSRF_TOKEN_NAME')) {
    define('CSRF_TOKEN_NAME', 'csrf_token');
}
if (!defined('CSRF_TOKEN_LENGTH')) {
    define('CSRF_TOKEN_LENGTH', 32);
}
if (!defined('CSRF_TOKEN_EXPIRY')) {
    define('CSRF_TOKEN_EXPIRY', (int) (get_env_resilient('CSRF_TOKEN_EXPIRY', 7200)));
}

if (!defined('PASSWORD_MIN_LENGTH')) {
    define('PASSWORD_MIN_LENGTH', (int) (get_env_resilient('PASSWORD_MIN_LENGTH', 12)));
}
if (!defined('PASS_ROTATION_DAYS')) {
    define('PASS_ROTATION_DAYS', (int) (get_env_resilient('PASSWORD_EXPIRY_DAYS', get_env_resilient('PASS_ROTATION_DAYS', 90))));
}
if (!defined('PASSWORD_HISTORY_COUNT')) {
    define('PASSWORD_HISTORY_COUNT', 5);
}

if (!defined('LOGIN_MAX_ATTEMPTS')) {
    define('LOGIN_MAX_ATTEMPTS', (int) (get_env_resilient('LOGIN_MAX_ATTEMPTS', 5)));
}
if (!defined('LOGIN_LOCKOUT_MINUTES')) {
    define('LOGIN_LOCKOUT_MINUTES', (int) (get_env_resilient('LOGIN_LOCKOUT_MINUTES', 15)));
}
if (!defined('API_RATE_LIMIT')) {
    define('API_RATE_LIMIT', (int) (get_env_resilient('API_RATE_LIMIT', 100)));
}

if (!defined('MAX_PHOTO_SIZE')) {
    define('MAX_PHOTO_SIZE', (int) (get_env_resilient('MAX_PHOTO_SIZE', 5242880)));
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
    define('APP_NAME', get_env_resilient('APP_NAME', 'SCI-TSS'));
}
if (!defined('LOG_AUDIT')) {
    define('LOG_AUDIT', true);
}
if (!defined('LOG_FILE')) {
    define('LOG_FILE', get_env_resilient('LOG_PATH', __DIR__ . '/../../logs/audit.log'));
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