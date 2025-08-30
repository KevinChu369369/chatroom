<?php
$env = getenv('APP_ENV') ?: 'production';
if ($env !== 'production') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
    ini_set('display_errors', 0);
}

$host = getenv('DB_HOST') ?: "host.docker.internal";
$username = getenv('DB_USER') ?: "root";
$password = getenv('DB_PASSWORD') ?: "";
$db_name = getenv('DB_NAME') ?: "chatroom";
