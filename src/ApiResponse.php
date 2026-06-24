<?php
/**
 * RUMS API — Standard JSON Response
 *
 * Success:  { "success": true,  "data": {...}, "meta": {...}, "message": "" }
 * Error:    { "success": false, "errors": [...], "message": "..." }
 * Timing:   _ms field appended when APP_DEBUG = true (always included)
 */
class ApiResponse
{
    private static float $startTime = 0.0;

    public static function init(): void
    {
        self::$startTime = microtime(true);
        header('Content-Type: application/json; charset=utf-8');

        // ── Security headers ──────────────────────────────────
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=()');

        // HSTS — only set over HTTPS (prevents downgrade attacks)
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        // Content Security Policy for API responses (JSON, no HTML rendered)
        header("Content-Security-Policy: default-src 'none'");

        // CORS
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = defined('CORS_ALLOWED_ORIGINS') ? CORS_ALLOWED_ORIGINS : '*';

        if ($allowed === '*') {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '') {
            $allowedList = array_map('trim', explode(',', $allowed));
            if (in_array($origin, $allowedList, true)) {
                header("Access-Control-Allow-Origin: $origin");
                header('Access-Control-Allow-Credentials: true');
            }
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
        header('Vary: Origin');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    // ── 2xx ──────────────────────────────────────────────────

    public static function ok(mixed $data = null, string $message = '', array $meta = []): never
    {
        self::send(200, true, $data, $message, [], $meta);
    }

    public static function created(mixed $data = null, string $message = 'Created.'): never
    {
        self::send(201, true, $data, $message);
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    // ── 4xx ──────────────────────────────────────────────────

    public static function badRequest(string $message = 'Bad request.', array $errors = []): never
    {
        self::send(400, false, null, $message, $errors);
    }

    public static function unauthorized(string $message = 'Authentication required.'): never
    {
        header('WWW-Authenticate: Bearer realm="RUMS API"');
        self::send(401, false, null, $message);
    }

    public static function forbidden(string $message = 'Access denied.'): never
    {
        self::send(403, false, null, $message);
    }

    public static function notFound(string $message = 'Resource not found.'): never
    {
        self::send(404, false, null, $message);
    }

    public static function methodNotAllowed(array $allowed = []): never
    {
        if ($allowed) header('Allow: ' . implode(', ', $allowed));
        self::send(405, false, null, 'Method not allowed.');
    }

    public static function conflict(string $message = 'Conflict.'): never
    {
        self::send(409, false, null, $message);
    }

    public static function unprocessable(string $message = 'Validation failed.', array $errors = []): never
    {
        self::send(422, false, null, $message, $errors);
    }

    public static function tooManyRequests(int $retryAfter = 60): never
    {
        header("Retry-After: $retryAfter");
        self::send(429, false, null, 'Too many requests. Please slow down.');
    }

    // ── 5xx ──────────────────────────────────────────────────

    public static function serverError(string $message = 'Internal server error.', ?\Throwable $e = null): never
    {
        if ($e !== null) {
            error_log('[API 500] ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
        $debug = (defined('APP_ENV') && APP_ENV !== 'production' && $e !== null)
            ? ['debug' => $e->getMessage()]
            : null;
        self::send(500, false, $debug, $message);
    }

    public static function serviceUnavailable(string $message = 'Service unavailable.'): never
    {
        self::send(503, false, null, $message);
    }

    // ── Paginated helper ──────────────────────────────────────

    public static function paginated(array $result, string $message = ''): never
    {
        self::ok($result['data'] ?? [], $message, $result['meta'] ?? []);
    }

    // ── Core sender ───────────────────────────────────────────

    private static function send(
        int    $code,
        bool   $success,
        mixed  $data    = null,
        string $message = '',
        array  $errors  = [],
        array  $meta    = []
    ): never {
        http_response_code($code);

        $body = ['success' => $success];

        if ($data    !== null) $body['data']    = $data;
        if ($message !== '')   $body['message'] = $message;
        if ($errors)           $body['errors']  = $errors;
        if ($meta)             $body['meta']    = $meta;

        if (self::$startTime > 0) {
            $body['_ms'] = round((microtime(true) - self::$startTime) * 1000, 2);
        }

        // JSON_PRETTY_PRINT only in non-production — saves ~40% response size in prod
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('APP_ENV') && APP_ENV !== 'production') $flags |= JSON_PRETTY_PRINT;
        echo json_encode($body, $flags);
        exit;
    }
}
