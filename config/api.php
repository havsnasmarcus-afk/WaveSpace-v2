<?php
declare(strict_types=1);
/** Shared helpers for the JSON API (public/api/*.php). */

// Never let PHP warnings/notices leak into the JSON body (they would break the browser's JSON.parse).
ini_set('display_errors', '0');
ob_start();

// Registered BEFORE loading the rest, so even a failure while loading config comes back as clean JSON.
set_exception_handler(function (Throwable $t): void {
    error_log('WaveSpace API error: ' . get_class($t) . ': ' . $t->getMessage());
    $code  = 'server';
    $msg   = 'Something went wrong on the server. Please try again in a moment.';
    $state = (string) $t->getCode();
    if ($t instanceof DbConnectException) {
        $code = 'db_connect';
        $msg  = 'The server cannot connect to the database. Check config/db.local.php (host, database, user, password).';
    } elseif ($t instanceof PDOException && in_array($state, ['42P01', '42703', '3F000'], true)) {
        $code = 'db_schema';
        $msg  = 'The database tables are missing or out of date. Run schema.sql on your database.';
    }
    json_out([
        'error'  => $msg,
        'errors' => [],
        'code'   => $code,
        // Raw message only when 'debug' => true in config/db.local.php
        'detail' => (function_exists('ws_debug') && ws_debug()) ? $t->getMessage() : null,
    ], 500);
});

require_once __DIR__ . '/app.php';

function json_out(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function json_error(string $message, int $status = 400, array $errors = []): void
{
    json_out(['error' => $message, 'errors' => array_values($errors)], $status);
}

function api_method(string ...$allowed): string
{
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($m, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_error('Method not allowed.', 405);
    }
    return $m;
}

function json_body(): array
{
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            $data = [];
        } else {
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                json_error('Invalid JSON body.', 400);
            }
        }
    }
    return $data;
}

/**
 * State-changing requests must carry the session's CSRF token, either in the X-CSRF-Token header
 * or (fallback for servers that strip custom headers) as "csrf" in the JSON body.
 */
function api_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($sent === '') {
        $sent = (string) (json_body()['csrf'] ?? '');
    }
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        json_error('Your session expired. Please reload the page and try again.', 403);
    }
}

function api_user(): array
{
    $u = current_user();
    if (!$u) {
        json_error('Please log in to continue.', 401);
    }
    return $u;
}

function api_staff(): array
{
    $u = api_user();
    if (!StaffService::isStaff($u)) {
        json_error('This area is for WaveSpace staff only.', 403);
    }
    return $u;
}
