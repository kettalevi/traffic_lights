<?php
// Base configuration. Override any value with environment variables, or by
// creating config.local.php (git-ignored) that returns an array of overrides.
$config = [
    'db_driver' => getenv('DB_DRIVER') ?: 'mysql',      // mysql | sqlite (sqlite is for demos/tests)
    'db_host'   => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port'   => getenv('DB_PORT') ?: '3306',
    'db_name'   => getenv('DB_NAME') ?: 'traffic_lights',
    'db_user'   => getenv('DB_USER') ?: 'traffic',
    'db_pass'   => getenv('DB_PASS') ?: '',
    'db_sqlite' => getenv('DB_SQLITE') ?: __DIR__ . '/data/traffic.sqlite',
    // Time zone used to evaluate time-of-day rules.
    'timezone'  => getenv('APP_TIMEZONE') ?: 'UTC',
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, require __DIR__ . '/config.local.php');
}

return $config;
