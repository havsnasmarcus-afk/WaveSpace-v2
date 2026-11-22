<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../src/LockerService.php';
require_once __DIR__ . '/../src/StaffService.php';

/**
 * Session setup for a SHARED host such as gnomo.fe.up.pt/~user/:
 *  - own cookie name + cookie path, so we never clash with other students' PHPSESSID cookies on the same domain
 *  - fall back to a writable session folder if the default one is not writable
 */
function ws_start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $save = (string) session_save_path();
    if (strpos($save, ';') !== false) {
        $save = substr($save, (int) strrpos($save, ';') + 1);
    }
    if ($save === '' || !is_dir($save) || !is_writable($save)) {
        $fallback = sys_get_temp_dir() . '/wavespace_sessions';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0700, true);
        }
        if (is_dir($fallback) && is_writable($fallback)) {
            session_save_path($fallback);
        }
    }
    // api scripts live in <site>/api/, so the site base path is two levels up from the script
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $base   = rtrim(dirname(dirname($script)), '/') . '/';
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('wavespace_sid');
    ini_set('session.use_strict_mode', '1');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $base,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, $base . '; samesite=Lax', '', $secure, true); // PHP 7.2 and older
    }
    session_start();
}
ws_start_session();

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    // A deactivated staff account is logged out on its very next request
    $st = db()->prepare('SELECT id, email, full_name, role FROM users WHERE id = :id AND active');
    $st->execute([':id' => $_SESSION['user_id']]);
    return $st->fetch() ?: null;
}
