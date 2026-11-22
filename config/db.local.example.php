<?php
// Copy this file to db.local.php and fill in YOUR details. db.local.php is the only file with secrets
// and is listed in .gitignore - never commit it.
return [
    'dsn'    => 'pgsql:host=db.fe.up.pt;port=5432;dbname=YOUR_DB_NAME',
    'user'   => 'YOUR_DB_USER',
    'pass'   => 'YOUR_DB_PASSWORD',
    'schema' => 'wavespace',   // set to '' if you cannot create a schema
    'debug'  => false,         // true shows raw DB errors in API responses - only while setting up
];
