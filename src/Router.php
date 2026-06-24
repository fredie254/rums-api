<?php
/**
 * RUMS API — Lightweight Router
 *
 * Patterns:
 *   properties            → literal
 *   properties/{id}       → numeric   (\d+)
 *   properties/{slug}     → slug      ([a-z0-9_-]+)
 *   properties/{uuid}     → UUID      ([0-9a-f-]{36})
 *   files/{any}           → any segment ([^/]+)
 */
class Router
{
    private string $method;
    private string $path;

    /** @var array<int, array{type:'route',method:string,regex:string,handler:callable}|array{type:'guard',fn:callable}> */
    private array $routes = [];

    public function __construct(string $method, string $rawPath)
    {
        $this->method = strtoupper($method);

        $path = parse_url(rawurldecode($rawPath), PHP_URL_PATH) ?? '/';
        $path = preg_replace('#^/api/v[0-9]+#i', '', $path);
        $this->path = trim($path, '/');
    }

    // ── Registrars ────────────────────────────────────────────

    public function get(string $pattern, callable $handler): self    { return $this->add('GET',    $pattern, $handler); }
    public function post(string $pattern, callable $handler): self   { return $this->add('POST',   $pattern, $handler); }
    public function put(string $pattern, callable $handler): self    { return $this->add('PUT',    $pattern, $handler); }
    public function patch(string $pattern, callable $handler): self  { return $this->add('PATCH',  $pattern, $handler); }
    public function delete(string $pattern, callable $handler): self { return $this->add('DELETE', $pattern, $handler); }

    public function any(array $methods, string $pattern, callable $handler): self
    {
        foreach ($methods as $m) $this->add(strtoupper($m), $pattern, $handler);
        return $this;
    }

    /**
     * Register a guard (middleware). Guards run only when a matching route is found,
     * and only apply to routes registered after this call.
     */
    public function guard(callable $fn): self
    {
        $this->routes[] = ['type' => 'guard', 'fn' => $fn];
        return $this;
    }

    private function add(string $method, string $pattern, callable $handler): self
    {
        $this->routes[] = [
            'type'    => 'route',
            'method'  => $method,
            // Compile regex once at registration — not on every dispatch
            'regex'   => $this->compilePattern($pattern),
            'handler' => $handler,
        ];
        return $this;
    }

    /**
     * Compile a route pattern to a regex once, at registration time.
     * Result is cached in the route entry — dispatch never recompiles.
     */
    private function compilePattern(string $pattern): string
    {
        $p = preg_replace_callback('/\{(\w+)\}/', static fn($m) => match ($m[1]) {
            'id'    => '(\d+)',
            'slug'  => '([a-z0-9_-]+)',
            'uuid'  => '([0-9a-f-]{36})',
            default => '([^/]+)',
        }, trim($pattern, '/'));

        return '#^' . $p . '$#i';
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

            // Fast: pre-compiled regex, no recompilation on dispatch
            if (!preg_match($entry['regex'], $this->path, $matches)) continue;

            $allowedMethods[] = $entry['method'];

            if ($entry['method'] !== $this->method) continue;

            foreach ($pendingGuards as $guard) {
                $guard();
            }

            array_shift($matches);
            ($entry['handler'])(...$matches);
            return;
        }

        if ($allowedMethods) {
            ApiResponse::methodNotAllowed(array_unique($allowedMethods));
        }

        ApiResponse::notFound("Endpoint not found: {$this->method} /{$this->path}");
    }

    // ── Request helpers ───────────────────────────────────────

    /** Decode JSON body (memoised — reads php://input once). */
    public static function body(): array
    {
        static $parsed = null;
        if ($parsed !== null) return $parsed;

        $raw = file_get_contents('php://input');
        $ct  = $_SERVER['CONTENT_TYPE'] ?? '';

        if ($raw !== '' && str_contains($ct, 'application/json')) {
            $parsed = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                ApiResponse::badRequest('Invalid JSON: ' . json_last_error_msg());
            }
        } else {
            $parsed = $_POST;
        }

        return $parsed ?? [];
    }

    public static function intParam(string $key, int $default = 0): int
    {
        return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
    }

    public static function strParam(string $key, string $default = ''): string
    {
        return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    }

    public static function page(): int    { return max(1, self::intParam('page', 1)); }
    public static function perPage(int $default = 20): int { return max(1, min(200, self::intParam('per_page', $default))); }
}
