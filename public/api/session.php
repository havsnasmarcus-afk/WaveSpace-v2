<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

api_method('GET');
json_out(['user' => current_user(), 'csrf' => csrf_token()]);
