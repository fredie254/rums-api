<?php
/**
 * GitHub webhook deployment endpoint.
 * Called by GitHub Actions after tests pass — runs git pull on the server.
 *
 * Set DEPLOY_SECRET in .env (or hardcode below) and add the same value
 * as the DEPLOY_WEBHOOK_SECRET secret in GitHub Actions.
 */

$secret = getenv('DEPLOY_SECRET') ?: '';

// Verify HMAC signature from GitHub Actions
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload   = file_get_contents('php://input');
$expected  = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!$secret || !hash_equals($expected, $signature)) {
    http_response_code(403);
    exit('Forbidden');
}

$data = json_decode($payload, true);
$ref  = $data['ref'] ?? '';

// Only deploy on push to main
if ($ref !== 'refs/heads/main') {
    http_response_code(200);
    exit('Ignored: not main branch');
}

$repo = escapeshellcmd(__DIR__);
$output = shell_exec("cd {$repo} && git pull origin main 2>&1");

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'output' => $output]);
