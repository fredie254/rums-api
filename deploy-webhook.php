<?php
/**
 * GitHub webhook deployment endpoint.
 * Served directly by Apache (bypasses the API router).
 * Reads DEPLOY_SECRET from .env manually since bootstrap isn't loaded here.
 */

// Parse .env to get DEPLOY_SECRET
$envFile = __DIR__ . '/.env';
$secret  = '';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) === 'DEPLOY_SECRET') {
                $secret = trim($v);
                break;
            }
        }
    }
}

// Verify HMAC signature
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload   = file_get_contents('php://input');
$expected  = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!$secret || !hash_equals($expected, $signature)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Forbidden']));
}

$data = json_decode($payload, true);

// Only deploy on push to main
if (($data['ref'] ?? '') !== 'refs/heads/main') {
    http_response_code(200);
    exit(json_encode(['success' => true, 'message' => 'Ignored: not main branch']));
}

$dir    = escapeshellarg(__DIR__);
$output = shell_exec("cd $dir && git pull origin main 2>&1");

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['success' => true, 'output' => $output]);
