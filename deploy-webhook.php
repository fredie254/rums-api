<?php
/**
 * GitHub webhook deployment endpoint.
 * Secured via ?token= query parameter (Authorization header stripped by Apache).
 */

// Parse DEPLOY_TOKEN from .env
$token = '';
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) === 'DEPLOY_TOKEN') {
                $token = trim($v);
                break;
            }
        }
    }
}

$provided = $_GET['token'] ?? '';

if (!$token || !hash_equals($token, $provided)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Forbidden']));
}

$dir    = escapeshellarg(__DIR__);
$output = shell_exec("cd $dir && git pull origin main 2>&1");

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['success' => true, 'output' => $output]);
