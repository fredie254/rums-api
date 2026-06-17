<?php
/**
 * RUMS API — Lightweight Router
 *
 * Pattern syntax:
 *   properties            → literal match
 *   properties/{id}       → captures numeric id  (\d+)
 *   properties/{id}/units → nested resource
 *   items/{slug}          → alphanumeric slug    ([a-z0-9_-]+)
 *   files/{any}           → any single segment   ([^/]+)
 *
 * Usage:
 *   $router = new Router($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
 *   $router->get('properties',      fn() => ...);
 *   $router->get('properties/{id}', fn($id) => ...);
 *   $router->dispatch();
 */
class Router
{
    private string $method;
    private string $path;           // trimmed URI after /api/v1 prefix (if present)
    private array  $routes = [];    // mixed: route entries and guard entries

    public function __construct(string $method, string $rawPath)
    {
        $this->method = strtoupper($method);

        // Strip query string, URL-decode, remove optional /api/v1 prefix
        $path = parse_url(rawurldecode($rawPath), PHP_URL_PATH) ?? '/';
        $path = preg_replace('#^/api/v[0-9]+#i', '', $path);
        $this->path = trim($path, '/');
    }

    // ── Registrars ────────────────────────────────────────────

    public function get(string $pattern, callable $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): self
    {
        return $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): self
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): self
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    /** Register one handler for multiple methods. */
    public function any(array $methods, string $pattern, callable $handler): self
    {
        foreach ($methods as $m) $this->add(strtoupper($m), $pattern, $handler);
        return $this;
    }

    /**
     * Register a guard (middleware) that runs before all routes added after this call.
     * Guards are only executed when a matching route is found.
     */
    public function guard(callable $fn): self
    {
        $this->routes[] = ['type' => 'guard', 'fn' => $fn];
        return $this;
    }

    private function add(string $method, string $pattern, callable $handler): self
    {
        $this->routes[] = ['type' => 'route', 'method' => $method, 'pattern' => $pattern, 'handler' => $handler];
        return $this;
    }

    // ── Dispatch ──────────────────────────────────────────────

    public function dispatch(): void
    {
        $pendingGuards  = [];
        $allowedMethods = [];

        foreach ($this->routes as $entry) {
            if ($entry['type'] === 'guard') {
                $pendingGuards[] = $entry['fn'];
                continue;
            }

            $params = $this->match($entry['pattern'], $this->path);
            if ($params === null) continue;

            $allowedMethods[] = $entry['method'];

            if ($entry['method'] !== $this->method) continue;

            // Run guards accumulated before this route
            foreach ($pendingGuards as $guard) {
                $guard();
            }

            ($entry['handler'])(...array_values($params));
            return;
        }

        if ($allowedMethods) {
            ApiResponse::methodNotAllowed(array_unique($allowedMethods));
        }

        ApiResponse::notFound("Endpoint not found: {$this->method} /{$this->path}");
    }

    // ── Pattern matching ──────────────────────────────────────

    /**
     * Match route pattern against current path.
     * Returns captured params array (possibly empty) or null on no match.
     */
    private function match(string $pattern, string $path): ?array
    {
        $pattern = trim($pattern, '/');

        $regex = preg_replace_callback('/\{(\w+)\}/', function ($m) {
            return match ($m[1]) {
                'id'    => '(\d+)',
                'slug'  => '([a-z0-9_-]+)',
                'uuid'  => '([0-9a-f-]{36})',
                default => '([^/]+)',
            };
        }, $pattern);

        if (!preg_match('#^' . $regex . '$#i', $path, $matches)) return null;

        array_shift($matches);
        return $matches;
    }

    // ── Request helpers ───────────────────────────────────────

    /**
     * Decode JSON body or fall back to form POST body.
     */
    public static function body(): array
    {
        static $parsed = null;
        if ($parsed !== null) return $parsed;

        $raw = file_get_contents('php://input');
        $ct  = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($ct, 'application/json') && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                ApiResponse::badRequest('Invalid JSON: ' . json_last_error_msg());
            }
        } else {
            $parsed = $_POST;
        }

        return $parsed ?? [];
    }

    /** Integer query parameter with default. */
    public static function intParam(string $key, int $default = 0): int
    {
        return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
    }

    /** String query parameter with default. */
    public static function strParam(string $key, string $default = ''): string
    {
        return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    }

    /** Current page (minimum 1). */
    public static function page(): int
    {
        return max(1, self::intParam('page', 1));
    }

    /** Per-page limit (1–200). */
    public static function perPage(int $default = 20): int
    {
        return max(1, min(200, self::intParam('per_page', $default)));
    }
}
