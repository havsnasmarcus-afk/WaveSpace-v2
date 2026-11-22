<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

api_method('GET');
LockerService::processDue(db());
json_out(['locations' => LockerService::availability(db()), 'server_time' => date('c')]);
