<?php
/**
 * RUMS — Application-level Field Encryption
 *
 * Encrypts sensitive fields before DB storage and decrypts on read.
 * Algorithm: AES-256-GCM (authenticated encryption — no separate HMAC needed)
 * Key source: APP_KEY from .env, passed through SHA-256 to produce a 32-byte key.
 *
 * Encrypted values are prefixed with "enc1:" so the decrypt method can gracefully
 * handle legacy plaintext data without errors — existing rows are returned as-is
 * until overwritten by the application.
 *
 * Usage:
 *   $ciphertext = Encryptor::encrypt('sensitive value');   // store this
 *   $plaintext  = Encryptor::decrypt($ciphertext);         // read this
 *   $hash       = Encryptor::hash('12345678');             // for UNIQUE index checks
 */
class Encryptor
{
    private const CIPHER  = 'aes-256-gcm';
    private const TAG_LEN = 16;   // GCM authentication tag length in bytes
    private const IV_LEN  = 12;   // GCM standard nonce = 12 bytes
    private const PREFIX  = 'enc1:';

    // ── Key derivation ────────────────────────────────────────

    private static function key(): string
    {
        $appKey = defined('APP_KEY') ? APP_KEY : (getenv('APP_KEY') ?: '');
        if (!$appKey || $appKey === 'changeme') {
            throw new \RuntimeException(
                'APP_KEY is not configured or is using the default value. ' .
                'Set a strong 64-char random string in .env before enabling encryption.'
            );
        }
        // Derive a 32-byte AES key from the app key
        return hash('sha256', $appKey, true);
    }

    // ── Core operations ───────────────────────────────────────

    /**
     * Encrypt a value. Returns null/'' unchanged. Already-encrypted values are
     * returned as-is (idempotent). Throws on OpenSSL failure.
     */
    public static function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '') return $value;
        if (self::isEncrypted($value)) return $value;

        $key = self::key();
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ct  = openssl_encrypt(
            $value, self::CIPHER, $key,
            OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN
        );

        if ($ct === false) {
            throw new \RuntimeException('AES-256-GCM encryption failed.');
        }

        // Pack: prefix || base64(iv || tag || ciphertext)
        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    /**
     * Decrypt a value. Returns null/'' unchanged. Plaintext (no prefix) is
     * returned as-is — this makes the transition from unencrypted legacy data
     * transparent. Returns null if the ciphertext is corrupt.
     */
    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') return $value;
        if (!self::isEncrypted($value)) return $value; // legacy plaintext

        $raw = base64_decode(substr($value, strlen(self::PREFIX)));
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            error_log('[Encryptor] decrypt: invalid ciphertext length');
            return null;
        }

        $key = self::key();
        $iv  = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ct  = substr($raw, self::IV_LEN + self::TAG_LEN);

        $pt = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) {
            error_log('[Encryptor] decrypt: authentication tag mismatch — possible tampering or wrong key');
            return null;
        }

        return $pt;
    }

    /**
     * One-way deterministic hash for use as a searchable/unique index.
     * Uses plain SHA-256 (no HMAC) so MySQL can replicate it with SHA2() for
     * data-migration queries: UPDATE t SET col_hash = SHA2(LOWER(TRIM(col)), 256)
     */
    public static function hash(?string $value): ?string
    {
        if ($value === null || $value === '') return null;
        return hash('sha256', strtolower(trim($value)));
    }

    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }

    // ── Bulk helpers ──────────────────────────────────────────

    /** Encrypt a set of fields in an associative array. */
    public static function encryptFields(array $data, array $fields): array
    {
        foreach ($fields as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== null && $data[$f] !== '') {
                $data[$f] = self::encrypt((string)$data[$f]);
            }
        }
        return $data;
    }

    /** Decrypt a set of fields in an associative array. */
    public static function decryptFields(array $data, array $fields): array
    {
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $data[$f] = self::decrypt($data[$f]);
            }
        }
        return $data;
    }
}
