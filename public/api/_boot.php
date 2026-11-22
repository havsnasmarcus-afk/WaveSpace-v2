<?php
declare(strict_types=1);
/** Locates the config folder (project-root layout, or config/ placed inside public_html) and loads it. */
foreach ([__DIR__ . '/../../config', __DIR__ . '/../config'] as $dir) {
    if (is_file($dir . '/api.php')) {
        require_once $dir . '/api.php';
        return;
    }
}
http_response_code(500);
header('Content-Type: application/json; charset=utf-8');
echo '{"error":"Server configuration folder not found.","errors":[]}';
exit;
