<?php

require_once __DIR__ . '/env.php';

return [
    'app_name'      => env_value('APP_NAME', 'CLARA'),
    'app_version'   => env_value('APP_VERSION', '4.9'),
    'app_env'       => env_value('APP_ENV', 'production'),
    'app_debug'     => env_value('APP_DEBUG', 'false') === 'true',
    'timezone'      => env_value('APP_TIMEZONE', 'Asia/Makassar'),
    'db_connection' => 'mysql',
    'db_host'       => env_value('DB_HOST', 'localhost'),
    'db_port'       => env_value('DB_PORT', '3306'),
    'db_database'   => env_value('DB_DATABASE', 'clara_unified'),
    'db_username'   => env_value('DB_USERNAME', 'root'),
    'db_password'   => env_value('DB_PASSWORD', ''),
    'display_token' => env_value('DISPLAY_TOKEN', 'change-this-display-token'),
    'base_url'      => env_value('APP_URL', ''),
];
