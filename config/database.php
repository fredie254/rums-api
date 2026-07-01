<?php
/**
 * RUMS API — Database Configuration
 *
 * env() is available because bootstrap.php is always included first.
 */

define('DB_HOST',    env('DB_HOST',    'localhost'));
define('DB_PORT',    env('DB_PORT',    '3306'));
define('DB_NAME',    env('DB_NAME',    'rums'));
define('DB_USER',    env('DB_USER',    'root'));
define('DB_PASS',    env('DB_PASS',    ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

/**
 * PDO singleton — one connection per request.
 */
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // Persistent connections: each PHP-FPM worker reuses its MySQL socket
        // — eliminates the TCP/socket handshake on every request.
        // Safe on FPM (one connection per worker). Disable if using ProxySQL/pgBouncer.
        PDO::ATTR_PERSISTENT         => true,
        // charset= in DSN already sets names; INIT_COMMAND is only needed for
        // collation overrides or session variables.
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION', time_zone='+03:00'",
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('[DB] Connection failed: ' . $e->getMessage());
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'Database unavailable. Please try again shortly.',
        ]);
        exit;
    }

    return $pdo;
}
