<?php
declare(strict_types=1);
// Temporary diagnostic page - open it in the browser, read the result, then DELETE this file.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$cfgDir = null;
foreach ([__DIR__ . '/../../config', __DIR__ . '/../config'] as $d) {
    if (is_file($d . '/db.php')) { $cfgDir = $d; break; }
}
$ok = static fn(bool $v, string $yes = 'OK', string $no = 'PROBLEM'): string => $v ? $yes : $no;

echo "== Server ==\n";
echo "PHP version:          " . PHP_VERSION . '  ' . $ok(version_compare(PHP_VERSION, '7.4', '>='), 'OK', 'TOO OLD (need 7.4+)') . "\n";
echo "PDO drivers:          " . (class_exists('PDO') ? implode(', ', PDO::getAvailableDrivers()) : 'PDO missing') . "\n";
echo "pdo_pgsql:            " . $ok(extension_loaded('pdo_pgsql'), 'loaded', 'NOT LOADED - PHP cannot talk to PostgreSQL on this server') . "\n";

echo "\n== Files ==\n";
echo "config folder:        " . ($cfgDir ? 'found' : 'MISSING (upload config/ next to public_html or inside it)') . "\n";
echo "src/LockerService:    " . ($cfgDir && is_file($cfgDir . '/../src/LockerService.php') ? 'found' : 'MISSING (src/ must sit next to config/)') . "\n";
echo "config/db.local.php:  " . ($cfgDir && is_file($cfgDir . '/db.local.php') ? 'found' : 'MISSING (copy db.local.example.php and fill it in)') . "\n";

echo "\n== Database ==\n";
if (!$cfgDir) { echo "cannot continue without config folder\n"; exit; }
require_once $cfgDir . '/db.php';
$cfg = ws_config();
$host = preg_match('/host=([^;]+)/', (string) $cfg['dsn'], $m1) ? $m1[1] : '?';
$port = preg_match('/port=(\d+)/', (string) $cfg['dsn'], $m2) ? (int) $m2[1] : 5432;
$dbn  = preg_match('/dbname=([^;]+)/', (string) $cfg['dsn'], $m3) ? $m3[1] : '?';
echo "target:               host=$host port=$port dbname=$dbn user=" . $cfg['user'] . " schema=" . (($cfg['schema'] ?? '') !== '' ? $cfg['schema'] : '(account default)') . "\n";
$errno = 0; $errstr = '';
$sock = @fsockopen($host, $port, $errno, $errstr, 5);
echo "network to DB server: " . ($sock ? 'reachable' : "NOT REACHABLE ($errno $errstr) - the web server cannot open a connection to $host:$port") . "\n";
if ($sock) { fclose($sock); }
try {
    $pdo = db();
    echo "login:                OK (" . $pdo->query('SELECT version()')->fetchColumn() . ")\n";
    $n = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name IN ('users','locations','lockers','subscriptions','payments','support_requests')")->fetchColumn();
    echo "tables schema:        " . $pdo->query('SELECT current_schema()')->fetchColumn() . "\n";
    echo "tables in schema:     $n " . ($n >= 6 ? '(all 6 found, OK)' : '(schema.sql has not been run yet, or it ran in a different schema!)') . "\n";
    if ($n >= 6) {
        echo "locations rows:       " . $pdo->query('SELECT COUNT(*) FROM locations')->fetchColumn() . "\n";
        echo "users rows:           " . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . "\n";
    }
} catch (Throwable $t) {
    echo "login/query:          FAILED -> " . $t->getMessage() . "\n";
}

echo "\n== Sessions (needed for login) ==\n";
require_once $cfgDir . '/app.php';
$_SESSION['check'] = 'ok';
echo "session name:         " . session_name() . "\n";
echo "save path:            " . session_save_path() . '  ' . $ok(is_writable(session_save_path() ?: sys_get_temp_dir()), 'writable', 'NOT WRITABLE') . "\n";
echo "cookie path:          " . session_get_cookie_params()['path'] . "\n";
echo "\nDelete public/api/check.php when everything works.\n";
