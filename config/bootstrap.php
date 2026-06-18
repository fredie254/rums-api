<?php
/**
 * RUMS Standalone API — Bootstrap
 *
 * Loads environment, defines constants, connects to DB.
 * No sessions. No HTML helpers. Pure API context.
 */

declare(strict_types=1);

// ── Environment loader ────────────────────────────────────────

/**
 * Parse a .env file into $_ENV / getenv().
 * Supports: KEY=value, KEY="quoted", # comments, blank lines.
 */
function load_dotenv(string $path): void
{
    if (!file_exists($path)) return;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip inline comments
        if (str_contains($value, ' #')) {
            $value = trim(explode(' #', $value, 2)[0]);
        }

        // Strip surrounding quotes
        if (strlen($value) >= 2
            && in_array($value[0], ['"', "'"])
            && $value[0] === $value[strlen($value) - 1]) {
            $value = substr($value, 1, -1);
        }

        if (!isset($_ENV[$key]) && getenv($key) === false) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

/**
 * Read an env var with a typed default.
 */
function env(string $key, mixed $default = null): mixed
{
    $val = $_ENV[$key] ?? getenv($key);
    if ($val === false || $val === null || $val === '') return $default;

    if (is_string($val)) {
        $lower = strtolower($val);
        if ($lower === 'true')  return true;
        if ($lower === 'false') return false;
        if ($lower === 'null')  return null;
    }
    return $val;
}

// Load .env from project root (one level up from config/)
load_dotenv(dirname(__DIR__) . '/.env');

// ── Runtime settings ──────────────────────────────────────────

$tz = env('APP_TIMEZONE', 'Africa/Nairobi');
date_default_timezone_set($tz);

if (env('APP_DEBUG', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}

$logChannel = env('LOG_CHANNEL', 'file');
if ($logChannel === 'stderr') {
    ini_set('error_log', 'php://stderr');
} else {
    $logPath = env('LOG_PATH', dirname(__DIR__) . '/storage/logs/api.log');
    $logDir  = dirname($logPath);
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    ini_set('error_log', $logPath);
}

// ── Application constants ─────────────────────────────────────

define('BASE_PATH',    dirname(__DIR__));
define('APP_NAME',     env('APP_NAME',    'RUMS_API'));
define('APP_VERSION',  env('APP_VERSION', '1.0.0'));
define('APP_ENV',      env('APP_ENV',     'production'));
define('APP_TIMEZONE', env('APP_TIMEZONE','Africa/Nairobi'));
define('APP_KEY',      env('APP_KEY',     'changeme'));

// ── API constants ─────────────────────────────────────────────

define('API_RATE_LIMIT',      (int)env('API_RATE_LIMIT',      120));
define('API_RATE_WINDOW',     (int)env('API_RATE_WINDOW',       60));
define('API_TOKEN_LENGTH',    (int)env('API_TOKEN_LENGTH',      64));
define('API_TOKEN_EXPIRY_DAYS',(int)env('API_TOKEN_EXPIRY_DAYS',365));

// ── CORS ──────────────────────────────────────────────────────

define('CORS_ALLOWED_ORIGINS', env('CORS_ALLOWED_ORIGINS', '*'));

// ── Currency ──────────────────────────────────────────────────

if (!defined('CURRENCY_SYMBOL')) define('CURRENCY_SYMBOL', env('CURRENCY_SYMBOL', 'Ksh'));
if (!defined('CURRENCY_CODE'))   define('CURRENCY_CODE',   env('CURRENCY_CODE',   'KES'));

// ── Database ──────────────────────────────────────────────────

require_once __DIR__ . '/database.php';
