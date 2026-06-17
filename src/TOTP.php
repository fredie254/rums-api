<?php
/**
 * RUMS — TOTP Implementation (RFC 6238 / RFC 4226)
 *
 * Pure-PHP TOTP without external dependencies.
 * Compatible with Google Authenticator, Authy, Microsoft Authenticator, etc.
 *
 * Algorithm : HMAC-SHA1 (standard TOTP)
 * Digits    : 6
 * Period    : 30 seconds
 * Window    : ±1 step (tolerates 30-second clock drift)
 */
class TOTP
{
    private const BASE32  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const DIGITS  = 6;
    private const PERIOD  = 30;
    private const WINDOW  = 1; // steps either side

    // ── Secret generation ─────────────────────────────────────

    /**
     * Generate a cryptographically random Base32 secret (20 bytes = 160 bits).
     * Store this (encrypted) in mfa_secrets.secret.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    // ── Code generation & verification ───────────────────────

    /**
     * Return the current 6-digit TOTP code for a secret.
     * Primarily useful for testing.
     */
    public static function getCode(string $secret, ?int $timestamp = null): string
    {
        $counter = (int)floor(($timestamp ?? time()) / self::PERIOD);
        return self::hotp($secret, $counter);
    }

    /**
     * Verify a 6-digit code against a secret.
     * Accepts codes from ±WINDOW time steps to tolerate clock drift.
     * Strips spaces (user may copy a spaced "123 456" format).
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (strlen($code) !== self::DIGITS || !ctype_digit($code)) {
            return false;
        }

        $base = (int)floor(($timestamp ?? time()) / self::PERIOD);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (hash_equals(self::hotp($secret, $base + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    // ── otpauth URI ───────────────────────────────────────────

    /**
     * Build the otpauth://totp URI consumed by authenticator apps.
     * Encode this as a QR code on the setup page.
     */
    public static function getUri(string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $accountName);
        return 'otpauth://totp/' . $label
            . '?secret='    . rawurlencode($secret)
            . '&issuer='    . rawurlencode($issuer)
            . '&algorithm=SHA1'
            . '&digits='    . self::DIGITS
            . '&period='    . self::PERIOD;
    }

    // ── Backup codes ──────────────────────────────────────────

    /**
     * Generate $count single-use backup codes (8 uppercase hex chars each).
     * Returns ['plain' => [...], 'hashes' => [...]] —
     *   plain   → show once to the user, never store
     *   hashes  → store in mfa_backup_codes via password_hash()
     */
    public static function generateBackupCodes(int $count = 8): array
    {
        $plain  = [];
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $code     = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
            $plain[]  = $code;
            $hashes[] = password_hash($code, PASSWORD_BCRYPT, ['cost' => 10]);
        }
        return ['plain' => $plain, 'hashes' => $hashes];
    }

    /**
     * Verify a backup code against stored hashes.
     * Returns the index of the matching unused code, or -1 on failure.
     * Caller is responsible for marking the code as used.
     */
    public static function verifyBackupCode(string $code, array $hashes): int
    {
        $code = strtoupper(preg_replace('/[^A-F0-9]/i', '', $code));
        foreach ($hashes as $i => $hash) {
            if (password_verify($code, $hash)) {
                return $i;
            }
        }
        return -1;
    }

    // ── HOTP core ─────────────────────────────────────────────

    private static function hotp(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        // Counter as big-endian unsigned 64-bit int (two 32-bit halves)
        $msg = pack('N*', 0) . pack('N*', $counter);
        $mac = hash_hmac('sha1', $msg, $key, true);   // 20-byte SHA-1 digest

        // Dynamic truncation (RFC 4226 §5.3)
        $offset = ord($mac[19]) & 0x0f;
        $bin    = ((ord($mac[$offset])     & 0x7f) << 24)
                | ((ord($mac[$offset + 1]) & 0xff) << 16)
                | ((ord($mac[$offset + 2]) & 0xff) << 8)
                |  (ord($mac[$offset + 3]) & 0xff);

        return str_pad((string)($bin % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    // ── Base32 codec (RFC 4648) ───────────────────────────────

    private static function base32Encode(string $input): string
    {
        $output   = '';
        $alphabet = self::BASE32;
        $len      = strlen($input);
        $buffer   = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < $len; $i++) {
            $buffer    = ($buffer << 8) | ord($input[$i]);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output   .= $alphabet[($buffer >> $bitsLeft) & 0x1f];
            }
        }
        if ($bitsLeft > 0) {
            $output .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1f];
        }
        return $output;
    }

    private static function base32Decode(string $input): string
    {
        $input    = strtoupper(preg_replace('/[\s=]+/', '', $input));
        $alphabet = self::BASE32;
        $output   = '';
        $buffer   = 0;
        $bitsLeft = 0;

        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $val = strpos($alphabet, $input[$i]);
            if ($val === false) continue;
            $buffer    = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output   .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }
        return $output;
    }
}
