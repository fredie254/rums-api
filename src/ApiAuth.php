<?php
/**
 * RUMS API — Authentication & Rate Limiting
 *
 * Performance design:
 *  - Token resolved once per request and stored in static props
 *  - APCu used as an L1 cache for token DB lookups (60 s TTL)
 *  - last_used updated lazily: only when stale > 5 min, in shutdown
 *  - Request logging written in a shutdown function (after response sent)
 *  - Rate limit uses a single atomic MySQL round-trip via LAST_INSERT_ID()
 */
class ApiAuth
{
    private static ?array $currentToken = null;
    private static ?array $currentUser  = null;

    private const APCU_TTL          = 60;   // seconds — token cache lifetime
    private const LAST_USED_STALE   = 300;  // seconds — min gap between last_used writes

    // ── Token resolution ──────────────────────────────────────

    public static function resolve(PDO $db): ?array
    {
        // Already resolved this request
        if (self::$currentToken !== null) return self::$currentToken;

        $header = $_SERVER['HTTP_AUTHORIZATION']
               ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
               ?? '';

        // Never accept tokens via URL query string — they appear in server logs and browser history.
        $raw = ($header && str_starts_with($header, 'Bearer '))
            ? trim(substr($header, 7))
            : '';

        if (strlen($raw) < 16) return null;

        // ── L1: APCu token cache ──────────────────────────────
        $cacheKey = 'rums_tok_' . hash('sha256', $raw);
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $hit);
            if ($hit) {
                if ($cached['user_status'] !== 'active') return null;
                self::hydrate($cached);
                self::scheduleLog($db, $cached['id'], (int)$cached['user_id']);
                return $cached;
            }
        }

        // ── L2: DB lookup ─────────────────────────────────────
        $stmt = $db->prepare(
            "SELECT t.*,
                u.id     AS user_id,
                u.name   AS user_name,
                u.email  AS user_email,
                u.role   AS user_role,
                u.status AS user_status
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token = ?
               AND t.revoked = 0
               AND (t.expires_at IS NULL OR t.expires_at > NOW())"
        );
        $stmt->execute([$raw]);
        $token = $stmt->fetch();

        if (!$token || $token['user_status'] !== 'active') return null;

        // Store in APCu — next request for this token skips the DB join
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $token, self::APCU_TTL);
        }

        // last_used: only write when stale to reduce write amplification
        $lastUsed = $token['last_used'] ? strtotime($token['last_used']) : 0;
        if ((time() - $lastUsed) > self::LAST_USED_STALE) {
            self::scheduleLastUsed($db, (int)$token['id']);
        }

        self::scheduleLog($db, (int)$token['id'], (int)$token['user_id']);
        self::hydrate($token);

        return $token;
    }

    // ── Guards ────────────────────────────────────────────────

    public static function require(PDO $db): array
    {
        $token = self::resolve($db);
        if (!$token) ApiResponse::unauthorized('Valid Bearer token required.');
        return $token;
    }

    public static function requireScope(PDO $db, string $scope): array
    {
        $token = self::require($db);
        if (!self::hasScope($token, $scope)) {
            ApiResponse::forbidden("Insufficient scope. Required: $scope");
        }
        return $token;
    }

    public static function requireRole(PDO $db, string ...$roles): array
    {
        $token = self::require($db);
        if (!in_array(self::$currentUser['role'] ?? '', $roles, true)) {
            ApiResponse::forbidden('Your role does not have access to this resource.');
        }
        return $token;
    }

    // ── Scope helpers ─────────────────────────────────────────

    public static function hasScope(array $token, string $scope): bool
    {
        if (($token['user_role'] ?? '') === 'admin') return true;
        // Use exact token matching on a split list — str_contains() is a substring check
        // and would falsely pass e.g. "read:main" against a scope of "read:maintenance".
        $scopeList = array_map('trim', explode(',', $token['scopes'] ?? ''));
        return in_array('admin', $scopeList, true) || in_array($scope, $scopeList, true);
    }

    // ── Accessors ─────────────────────────────────────────────

    public static function token(): ?array     { return self::$currentToken; }
    public static function user(): ?array      { return self::$currentUser; }
    public static function userId(): ?int      { return self::$currentUser ? (int)self::$currentUser['id'] : null; }
    public static function userRole(): ?string { return self::$currentUser['role'] ?? null; }

    /**
     * Return the tenants.id for the currently authenticated tenant user.
     * Result is cached per-request (static variable) — the DB is only hit once
     * even when multiple endpoints call this within the same request.
     * Returns null for non-tenant roles.
     */
    public static function tenantId(PDO $db): ?int
    {
        static $resolved = false;
        static $tid      = null;

        if ($resolved) return $tid;
        $resolved = true;

        if (self::userRole() !== 'tenant') return null;

        $stmt = $db->prepare("SELECT id FROM tenants WHERE user_id = ? LIMIT 1");
        $stmt->execute([self::userId()]);
        $row = $stmt->fetchColumn();
        $tid = $row !== false ? (int)$row : null;

        return $tid;
    }

    // ── Rate limiting ─────────────────────────────────────────

    public static function rateLimit(PDO $db): void
    {
        $limit  = defined('API_RATE_LIMIT')  ? API_RATE_LIMIT  : 120;
        $window = defined('API_RATE_WINDOW') ? API_RATE_WINDOW :  60;

        $identifier  = self::$currentToken
            ? 'token:' . self::$currentToken['id']
            : 'ip:'    . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        $windowStart = date('Y-m-d H:i:s', intdiv((int)time(), $window) * $window);

        // Single atomic round-trip: increment + read via LAST_INSERT_ID()
        $db->prepare(
            "INSERT INTO api_rate_limits (identifier, window_start, request_count)
             VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE request_count = LAST_INSERT_ID(request_count + 1)"
        )->execute([$identifier, $windowStart]);

        $count = (int)$db->lastInsertId();
        $reset = intdiv((int)time(), $window) * $window + $window;

        header("X-RateLimit-Limit: $limit");
        header("X-RateLimit-Remaining: " . max(0, $limit - $count));
        header("X-RateLimit-Reset: $reset");

        if ($count > $limit) {
            ApiResponse::tooManyRequests($window);
        }
    }

    // ── Token issuance ────────────────────────────────────────

    public static function issueToken(
        PDO    $db,
        int    $userId,
        string $name    = 'API Token',
        string $scopes  = 'read:properties',
        int    $ttlDays = 0
    ): string {
        $length    = defined('API_TOKEN_LENGTH') ? API_TOKEN_LENGTH : 64;
        $token     = bin2hex(random_bytes((int)($length / 2)));
        $expiresAt = $ttlDays > 0 ? date('Y-m-d H:i:s', strtotime("+$ttlDays days")) : null;

        $db->prepare(
            "INSERT INTO api_tokens (user_id, token, name, scopes, expires_at) VALUES (?, ?, ?, ?, ?)"
        )->execute([$userId, $token, $name, $scopes, $expiresAt]);

        return $token;
    }

    /**
     * Evict a single raw token string from APCu.
     * Call this on logout so the cached entry doesn't linger.
     */
    public static function invalidateCache(string $raw): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete('rums_tok_' . hash('sha256', $raw));
        }
    }

    /**
     * Evict ALL cached tokens for a given user.
     * Call this whenever a user's role, status, or name changes so stale
     * permissions are never served from cache.
     */
    public static function invalidateUserTokens(PDO $db, int $userId): void
    {
        if (!function_exists('apcu_delete')) return;

        $stmt = $db->prepare(
            "SELECT token FROM api_tokens WHERE user_id = ? AND revoked = 0"
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tokenValue) {
            apcu_delete('rums_tok_' . hash('sha256', $tokenValue));
        }
    }

    // ── Private helpers ───────────────────────────────────────

    private static function hydrate(array $token): void
    {
        self::$currentToken = $token;
        self::$currentUser  = [
            'id'    => (int)$token['user_id'],
            'name'  => $token['user_name'],
            'email' => $token['user_email'],
            'role'  => $token['user_role'],
        ];
    }

    /**
     * Write last_used after the response is sent.
     * On PHP-FPM with fastcgi_finish_request() the client is already gone.
     */
    private static function scheduleLastUsed(PDO $db, int $tokenId): void
    {
        register_shutdown_function(static function () use ($db, $tokenId) {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            try {
                $db->prepare("UPDATE api_tokens SET last_used = NOW() WHERE id = ?")
                   ->execute([$tokenId]);
            } catch (Throwable) {}
        });
    }

    /**
     * Write request log after the response is sent.
     * Guarded by a static flag — runs exactly once per request even if
     * resolve() is called multiple times (scope/role re-checks).
     */
    private static function scheduleLog(PDO $db, int $tokenId, int $userId): void
    {
        static $scheduled = false;
        if ($scheduled) return;
        $scheduled = true;

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        register_shutdown_function(
            static function () use ($db, $tokenId, $userId, $method, $path, $ip, $ua) {
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                try {
                    $db->prepare(
                        "INSERT INTO api_request_logs
                            (token_id, user_id, method, endpoint, status_code, ip_address, user_agent)
                         VALUES (?, ?, ?, ?, 0, ?, ?)"
                    )->execute([$tokenId, $userId, $method, $path, $ip, $ua]);
                } catch (Throwable) {}
            }
        );
    }
}
