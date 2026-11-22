<?php
declare(strict_types=1);
// ONE-TIME installer: creates the tables + sample beaches by running schema.sql. DELETE THIS FILE afterwards.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
$cfgDir = null;
foreach ([__DIR__ . '/../../config', __DIR__ . '/../config'] as $d) {
    if (is_file($d . '/db.php')) { $cfgDir = $d; break; }
}
if (!$cfgDir) { exit("config folder not found.\n"); }
$sql = null;
foreach ([$cfgDir . '/../schema.sql', $cfgDir . '/schema.sql', __DIR__ . '/../schema.sql', __DIR__ . '/schema.sql'] as $f) {
    if (is_file($f)) { $sql = $f; break; }
}
if (!$sql) { exit("schema.sql not found. Upload it next to the config/ folder (or into config/).\n"); }
require_once $cfgDir . '/db.php';
try {
    $pdo = db();
    $has = $pdo->query("SELECT to_regclass('wavespace.users') IS NOT NULL")->fetchColumn();
    if ($has && ($_GET['force'] ?? '') !== 'yes') {
        exit("Tables already exist - nothing done (your data is safe).\nTo wipe and recreate everything add ?force=yes to the address.\n");
    }
    if (($_GET['go'] ?? '') !== '1' && !$has) {
        exit("Ready to create the WaveSpace tables from " . basename($sql) . ".\nAdd ?go=1 to the address to run it.\n");
    }
    $pdo->exec((string) file_get_contents($sql));
    $n = $pdo->query("SELECT COUNT(*) FROM wavespace.locations")->fetchColumn();
    echo "Done. Tables created, $n beaches loaded.\nNow DELETE install.php and check.php from the server.\n";
} catch (Throwable $t) {
    echo "FAILED: " . $t->getMessage() . "\n";
}
