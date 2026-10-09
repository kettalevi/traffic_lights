<?php
declare(strict_types=1);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/engine.php';

function app_config(): array
{
    static $config = null;
    return $config ??= require dirname(__DIR__) . '/config.php';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $c = app_config();
    if ($c['db_driver'] === 'sqlite') {
        @mkdir(dirname($c['db_sqlite']), 0775, true);
        $pdo = new PDO('sqlite:' . $c['db_sqlite']);
        $pdo->exec('PRAGMA busy_timeout = 5000');
    } else {
        $dsn = "mysql:host={$c['db_host']};port={$c['db_port']};dbname={$c['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['db_user'], $c['db_pass']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function db_driver(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

date_default_timezone_set(app_config()['timezone']);

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Use a locally vendored Bootstrap if present (offline screens), else the CDN. */
function bootstrap_assets(): array
{
    $local = __DIR__ . '/../public/assets/vendor/';
    if (is_file($local . 'bootstrap.min.css') && is_file($local . 'bootstrap.bundle.min.js')) {
        return ['/assets/vendor/bootstrap.min.css', '/assets/vendor/bootstrap.bundle.min.js'];
    }
    return [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    ];
}
