<?php
/**
 * RUMS API — Authentication Middleware
 *
 * Bearer token validation, scope enforcement, rate limiting,
 * and token issuance.
 *
 * Usage:
 *   ApiAuth::require($db)               — 401 if no valid token
 *   ApiAuth::requireScope($db, 'read:properties')
 *   ApiAuth::requireRole($db, 'admin', 'manager')
 *   ApiAuth::rateLimit($db)             — 429 if over limit
 *   ApiAuth::issueToken($db, $userId, 'name', 'scope1,scope2', 365)
 */
class ApiAuth
{
    private static ?array $currentToken = null;
    private static ?array $currentUser  = null;

    // ── Token resolution ──────────────────────────────────────

    /**
     * Extract and validate a Bearer token.
     * Also accepts ?api_token= query param for read-only GET convenience.
     * Returns the token row (with joined user columns) or null.
     */
    public static function resolve(PDO $db): ?array
    {
        // Authorization: Bearer <token>
        $header = $_SERVER['HTTP_AUTHORIZATION']
               ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
               ?? '';

        if ($header && str_starts_with($header, 'Bearer ')) {
            $raw = trim(substr($header, 7));
        } else {
            // Fallback: GET ?api_token= (convenience, read-only)
            $raw = $_GET['api_token'] ?? '';
        }

        if (strlen($raw) < 16) return null;

        $stmt = $db->prepare(
            "SELECT t.*,
                u.id   AS user_id,
                u.name  AS user_name,
                u.email AS user_email,
                u.role  AS user_role,
                u.status AS user_status
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token = ?
               AND t.revoked = 0
               AND (t.expires_at IS NULL OR t.expires_at > NOW())"
        );
        $stmt->execute([$raw]);
        $token = $stmt->fetch();

        if (!$token)                             return null;
        if ($token['user_status'] !== 'active')  return null;

        // Touch last_used (fire-and-forget)
        try {
            $db->prepare("UPDATE api_tokens SET last_used = NOW() WHERE id = ?")
               ->execute([$token['id']]);
        } catch (Throwable) {}

        // Log the request
        self::logRequest($db, $token['id'], $token['user_id']);

        self::$currentToken = $token;
        self::$currentUser  = [
            'id'    => (int)$token['user_id'],
            'name'  => $token['user_name'],
            'email' => $token['user_email'],
            'role'  => $token['user_role'],
        ];

        return $token;
    }

    // ── Guards ────────────────────────────────────────────────

    /** Require a valid token or terminate with 401. */
    public static function require(PDO $db): array
    {
        $token = self::resolve($db);
        if (!$token) ApiResponse::unauthorized('Valid Bearer token required.');
        return $token;
    }

    /** Require a token that has a specific scope (admin bypasses). */
    public static function requireScope(PDO $db, string $scope): array
    {
        $token = self::require($db);
        if (!self::hasScope($token, $scope)) {
            ApiResponse::forbidden("Insufficient scope. Required: $scope");
        }
        return $token;
    }

    /** Require token AND the user must be one of the given roles. */
    public static function requireRole(PDO $db, string ...$roles): array
    {
        $token = self::require($db);
        if (!in_array(self::$currentUser['role'] ?? '', $roles, true)) {
            ApiResponse::forbidden('Your role does not have access to this resource.');
        }
        return $token;
    }

    // ── Scope helpers ─────────────────────────────────────────

    /** Admin role and admin scope bypass all scope checks. */
    public static function hasScope(array $token, string $scope): bool
    {
        if (($token['user_role'] ?? '') === 'admin')  return true;
        if (str_contains($token['scopes'] ?? '', 'admin')) return true;
        return str_contains($token['scopes'] ?? '', $scope);
    }

    // ── Accessors ─────────────────────────────────────────────

    public static function token(): ?array    { return self::$currentToken; }
    public static function user(): ?array     { return self::$currentUser; }
    public static function userId(): ?int     { return self::$currentUser ? (int)self::$currentUser['id'] : null; }
    public static function userRole(): ?string{ return self::$currentUser['role'] ?? null; }

    // ── Rate limiting (sliding window) ────────────────────────

    public static function rateLimit(PDO $db): void
    {
        $limit  = defined('API_RATE_LIMIT')  ? API_RATE_LIMIT  : 120;
        $window = defined('API_RATE_WINDOW') ? API_RATE_WINDOW :  60;

        $identifier = self::$currentToken
            ? 'token:' . self::$currentToken['id']
            : 'ip:'    . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        $windowStart = date('Y-m-d H:i:s', (int)(time() / $window) * $window);

        $db->prepare(
            "INSERT INTO api_rate_limits (identifier, window_start, request_count)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE request_count = request_count + 1"
        )->execute([$identifier, $windowStart]);

        $count = (int)$db->prepare(
            "SELECT request_count FROM api_rate_limits
             WHERE identifier = ? AND window_start = ?"
        )->execute([$identifier, $windowStart]) ? 0 : 0;

        // Fetch separately
        $cs = $db->prepare("SELECT request_count FROM api_rate_limits WHERE identifier=? AND window_start=?");
        $cs->execute([$identifier, $windowStart]);
        $count = (int)$cs->fetchColumn();

        $reset = (int)(time() / $window) * $window + $window;
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
        $expiresAt = $ttlDays > 0
            ? date('Y-m-d H:i:s', strtotime("+$ttlDays days"))
            : null;

        $db->prepare(
            "INSERT INTO api_tokens (user_id, token, name, scopes, expires_at)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$userId, $token, $name, $scopes, $expiresAt]);

        return $token;
    }

    // ── Request logging ───────────────────────────────────────

    private static function logRequest(PDO $db, int $tokenId, int $userId): void
    {
        try {
            $db->prepare(
                "INSERT INTO api_request_logs
                    (token_id, user_id, method, endpoint, status_code, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, 0, ?, ?)"
            )->execute([
                $tokenId,
                $userId,
                $_SERVER['REQUEST_METHOD'] ?? 'GET',
                parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
                $_SERVER['REMOTE_ADDR']     ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (Throwable) { /* non-fatal */ }
    }
}
