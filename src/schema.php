<?php
/**
 * Table definitions. Kept in PHP so the same schema can be created on MySQL
 * (production) and SQLite (demo/tests). schema.sql is generated from this via
 * `php setup.php --dump-sql`.
 */

function schema_statements(string $driver): array
{
    $mysql = $driver === 'mysql';
    $pk    = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $big   = $mysql ? 'BIGINT' : 'INTEGER';
    $tail  = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    return [
        "CREATE TABLE IF NOT EXISTS settings (
            setting_key   VARCHAR(64) NOT NULL PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS roads (
            id            INTEGER NOT NULL PRIMARY KEY,
            name          VARCHAR(80) NOT NULL,
            green_seconds INTEGER NOT NULL DEFAULT 120,
            enabled       TINYINT NOT NULL DEFAULT 1,
            sort_order    INTEGER NOT NULL DEFAULT 0
        )$tail",

        // adjust_seconds is signed: positive increases, negative decreases the green time.
        // road_id NULL = applies to every road. days = comma list of ISO weekdays (1=Mon..7=Sun).
        "CREATE TABLE IF NOT EXISTS rules (
            id             $pk,
            name           VARCHAR(120) NOT NULL,
            road_id        INTEGER NULL,
            days           VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
            start_time     CHAR(5) NOT NULL,
            end_time       CHAR(5) NOT NULL,
            adjust_seconds INTEGER NOT NULL,
            active         TINYINT NOT NULL DEFAULT 1
        )$tail",

        // Single-row table (id = 1) holding the live phase of the controller.
        "CREATE TABLE IF NOT EXISTS signal_state (
            id          INTEGER NOT NULL PRIMARY KEY,
            mode        VARCHAR(16) NOT NULL DEFAULT 'auto',
            hold_road   INTEGER NOT NULL DEFAULT 0,
            phase_road  INTEGER NOT NULL DEFAULT 0,
            phase_type  VARCHAR(8) NOT NULL DEFAULT 'red',
            started_ms  $big NOT NULL DEFAULT 0,
            duration_ms $big NOT NULL DEFAULT 0
        )$tail",

        "CREATE TABLE IF NOT EXISTS users (
            id            $pk,
            username      VARCHAR(64) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at    DATETIME NOT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS audit_log (
            id         $pk,
            username   VARCHAR(64) NOT NULL,
            action     VARCHAR(64) NOT NULL,
            detail     VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        )$tail",
    ];
}

const DEFAULT_SETTINGS = [
    'default_green_seconds' => '120', // 2 minutes
    'orange_seconds'        => '4',
    'all_red_seconds'       => '2',   // clearance gap between one road's orange and the next road's green
    'min_green_seconds'     => '10',
    'max_green_seconds'     => '600',
    'step_seconds'          => '15',  // size of the +/- buttons in the admin
];

function install_schema(PDO $pdo, string $driver): void
{
    foreach (schema_statements($driver) as $sql) {
        $pdo->exec($sql);
    }
    foreach (DEFAULT_SETTINGS as $k => $v) {
        if (!$pdo->query('SELECT 1 FROM settings WHERE setting_key = ' . $pdo->quote($k))->fetchColumn()) {
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$k, $v]);
        }
    }
    if (!(int) $pdo->query('SELECT COUNT(*) FROM roads')->fetchColumn()) {
        $ins = $pdo->prepare('INSERT INTO roads (id, name, green_seconds, enabled, sort_order) VALUES (?, ?, ?, 1, ?)');
        for ($i = 1; $i <= 4; $i++) {
            $ins->execute([$i, "Road $i", (int) DEFAULT_SETTINGS['default_green_seconds'], $i]);
        }
    }
    if (!(int) $pdo->query('SELECT COUNT(*) FROM signal_state')->fetchColumn()) {
        $pdo->exec("INSERT INTO signal_state (id, mode, hold_road, phase_road, phase_type, started_ms, duration_ms)
                    VALUES (1, 'auto', 0, 0, 'red', 0, 0)");
    }
}
