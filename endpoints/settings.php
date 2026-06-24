<?php
/**
 * Settings endpoints
 *
 * GET  /api/v1/settings        get all settings as key-value map
 * PUT  /api/v1/settings        update one or many settings (admin)
 *
 * Credential keys are stored encrypted at rest and decrypted before
 * returning to admin callers. Non-admin callers never see them at all.
 */

const SETTINGS_ENCRYPTED_KEYS = [
    'mpesa_consumer_key',
    'mpesa_consumer_secret',
    'mpesa_passkey',
    'smtp_pass',
    'sms_api_key',
];

function registerSettingRoutes(Router $router, PDO $db): void
{
    // ── Get all ───────────────────────────────────────────────
    // All authenticated roles can read non-sensitive settings (currency, company info, etc.).
    // Credential keys are stripped for non-admin and decrypted for admin.
    $router->get('settings', function () use ($db) {
        ApiAuth::require($db);

        $rows = $db->query("SELECT setting_key, setting_value FROM settings ORDER BY setting_key")->fetchAll();
        $map  = array_column($rows, 'setting_value', 'setting_key');

        $user = ApiAuth::user();
        if ($user['role'] !== 'admin') {
            // Non-admins never see credential keys
            foreach (SETTINGS_ENCRYPTED_KEYS as $k) unset($map[$k]);
        } else {
            // Admin: decrypt credential keys before returning
            foreach (SETTINGS_ENCRYPTED_KEYS as $k) {
                if (isset($map[$k])) {
                    $map[$k] = Encryptor::decrypt($map[$k]);
                }
            }
        }

        ApiResponse::ok($map);
    });

    // ── Update ────────────────────────────────────────────────
    $router->put('settings', function () use ($db) {
        ApiAuth::requireRole($db, 'admin');

        $body = Router::body();
        if (empty($body) || !is_array($body)) {
            ApiResponse::unprocessable('Request body must be a JSON object of key-value pairs.');
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );

        $db->beginTransaction();
        try {
            foreach ($body as $key => $value) {
                $key   = trim((string)$key);
                $value = (string)$value;

                // Encrypt credential keys before persisting
                if (in_array($key, SETTINGS_ENCRYPTED_KEYS, true)) {
                    $value = Encryptor::encrypt($value);
                }

                $stmt->execute([$key, $value]);
            }
            $db->commit();
            ApiResponse::ok(null, 'Settings saved.');
        } catch (Throwable $e) {
            $db->rollBack();
            ApiResponse::serverError('Failed to save settings.', $e);
        }
    });
}
