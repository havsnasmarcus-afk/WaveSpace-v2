<?php
declare(strict_types=1);

/** Thrown when PHP cannot open the PostgreSQL connection (wrong host/user/password, server unreachable, driver missing). */
final class DbConnectException extends RuntimeException
{
}

/**
 * Database configuration (PostgreSQL via PDO).
 * Real credentials live in config/db.local.php (copy db.local.example.php). Never share that file.
 * Fallbacks for local development: env vars WS_DB_DSN / WS_DB_USER / WS_DB_PASS.
 *
 * 'schema'  tables live in their own schema so they cannot clash with other tables in a shared database.
 *           Use '' to skip and use your account's default schema instead.
 * 'debug'   true = API errors also include the raw database message (handy while setting up; switch off afterwards).
 */
function ws_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'dsn'    => getenv('WS_DB_DSN') ?: 'pgsql:host=localhost;port=5432;dbname=wavespace',
            'user'   => getenv('WS_DB_USER') ?: (getenv('USER') ?: 'postgres'),
            'pass'   => getenv('WS_DB_PASS') ?: '',
            'schema' => 'wavespace',
            'debug'  => false,
        ];
        $local = __DIR__ . '/db.local.php';
        // When WS_DB_DSN is set (e.g. by phpunit.xml) the env vars win, so tests can never hit the real database.
        if (getenv('WS_DB_DSN') === false && is_file($local)) {
            $over = require $local;
            if (is_array($over)) {
                $cfg = array_merge($cfg, $over);
            }
        }
    }
    return $cfg;
}

function ws_debug(): bool
{
    return (bool) (ws_config()['debug'] ?? false);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $cfg    = ws_config();
    $schema = (string) ($cfg['schema'] ?? '');
    if ($schema !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
        throw new RuntimeException('Invalid schema name in db config.');
    }
    if (!extension_loaded('pdo_pgsql')) {
        throw new DbConnectException('The PHP extension pdo_pgsql is not available on this server.');
    }
    $dsn = (string) $cfg['dsn'];
    if (stripos($dsn, 'connect_timeout') === false) {
        $dsn .= ';connect_timeout=8'; // fail fast instead of hanging when the server is unreachable
    }
    try {
        $conn = new PDO($dsn, (string) $cfg['user'], (string) $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        throw new DbConnectException($e->getMessage(), 0, $e);
    }
    if ($schema !== '') {
        // Only our own schema: never fall back to "public", which is shared with other users on a school server
        $conn->exec('SET search_path TO ' . $schema);
    }
    $pdo = $conn;
    return $pdo;
}
