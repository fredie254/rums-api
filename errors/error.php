<?php
/**
 * RUMS API — Error handler for Apache-level errors (403, 404, etc.)
 *
 * The API front controller (index.php) handles all application errors
 * via ApiResponse. This file only catches errors Apache raises itself
 * before PHP runs (e.g., blocked paths, missing files).
 *
 * Always returns JSON — never HTML — because this is a REST API.
 */
declare(strict_types=1);

$code = (int)(
    $_SERVER['REDIRECT_STATUS']
    ?? $_SERVER['HTTP_STATUS']
    ?? 500
);
if ($code < 100 || $code > 599) $code = 500;

$messages = [
    400 => 'Bad request.',
    403 => 'Access to this resource is forbidden.',
    404 => 'The requested endpoint does not exist.',
    405 => 'HTTP method not allowed.',
    429 => 'Too many requests. Please slow down.',
    500 => 'Internal server error.',
    502 => 'Bad gateway.',
    503 => 'Service temporarily unavailable.',
];

http_response_code($code);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

echo json_encode([
    'success' => false,
    'message' => $messages[$code] ?? 'Unexpected error.',
    'code'    => $code,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;
