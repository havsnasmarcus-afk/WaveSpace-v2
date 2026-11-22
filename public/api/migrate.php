<?php
declare(strict_types=1);
// ONE-TIME migration: adds the staff back office to an existing database WITHOUT wiping data.
// Open  .../api/migrate.php?go=1  once in the browser, then DELETE THIS FILE from the server.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
$cfgDir = null;
foreach ([__DIR__ . '/../../config', __DIR__ . '/../config'] as $d) {
    if (is_file($d . '/db.php')) { $cfgDir = $d; break; }
}
if (!$cfgDir) { exit("config folder not found.\n"); }
require_once $cfgDir . '/db.php';

if (($_GET['go'] ?? '') !== '1') {
    exit("Ready to add the staff columns (nif, phone, active) and the first admin account.\nYour existing data is kept.\nAdd ?go=1 to the address to run it.\n");
}

$sql = <<<'SQL'
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('customer','staff','admin'));
ALTER TABLE users ADD COLUMN IF NOT EXISTS nif    CHAR(9) UNIQUE CHECK (nif ~ '^[0-9]{9}$');
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone  VARCHAR(20);
ALTER TABLE users ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT TRUE;
INSERT INTO users (email, password_hash, full_name, nif, role)
VALUES ('admin@wavespace.pt', '$2y$10$0tndCNVyt5wRandvBkL16u831uEbXxJkwr1Gabn12irt6OShG74Wq', 'WaveSpace Admin', '123456789', 'admin')
ON CONFLICT (email) DO NOTHING;
SQL;

try {
    $pdo = db(); // already points at the wavespace schema
    $pdo->beginTransaction();
    $pdo->exec($sql);
    $pdo->commit();
    echo "Done. Staff columns added.\nLog in with admin@wavespace.pt / Staff2026! and change the password (Staff > Team).\n\nNow DELETE api/migrate.php from the server.\n";
} catch (Throwable $t) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    echo "FAILED (nothing was changed): " . $t->getMessage() . "\n";
}
