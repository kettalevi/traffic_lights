<?php
// Run: php tests/engine_test.php   (uses in-memory SQLite; no MySQL needed)
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE=:memory:');
require __DIR__ . '/../src/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void
{
    global $fails;
    $ok = $actual === $expected;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok ? '' : "  got " . json_encode($actual) . " expected " . json_encode($expected)) . "\n";
    $fails += $ok ? 0 : 1;
}
function fresh(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    install_schema($pdo, 'sqlite');
    return $pdo;
}
/** Poll every 5s up to $toMs, like live screens would, and return the final state. */
function run_to(PDO $pdo, int $fromMs, int $toMs): array
{
    for ($t = $fromMs; $t < $toMs; $t += 5000) {
        build_state($pdo, $t);
    }
    return build_state($pdo, $toMs);
}
function lights(array $s): string
{
    return implode(',', array_map(fn ($r) => $r['light'], $s['roads']));
}

$T0 = 1_800_000_000_000; // arbitrary fixed epoch (ms)
$sec = 1000;

echo "Default timing\n";
$pdo = fresh();
check('default green is 2 minutes', get_settings($pdo)['default_green_seconds'], '120');
$s = build_state($pdo, $T0);                       // cold start -> all red gap
check('cold start is all red', lights($s), 'red,red,red,red');
$s = build_state($pdo, $T0 + 2 * $sec);            // gap over -> road 1 green
check('road 1 green after gap', lights($s), 'green,red,red,red');
check('green lasts 120s', $s['phase']['ends_ms'] - $s['phase']['started_ms'], 120 * $sec);
$g1 = $s['phase']['started_ms'];
$s = build_state($pdo, $g1 + 119 * $sec);
check('still green at 119s', lights($s), 'green,red,red,red');
$s = build_state($pdo, $g1 + 121 * $sec);
check('orange at 121s', lights($s), 'orange,red,red,red');
$s = build_state($pdo, $g1 + 125 * $sec);          // 120 + 4 orange + 1 into all-red
check('all red clearance', lights($s), 'red,red,red,red');
$s = build_state($pdo, $g1 + 127 * $sec);          // 120+4+2 -> road 2
check('road 2 green', lights($s), 'red,green,red,red');
check('next green estimate for road 3', $s['roads'][2]['green_at_ms'], $g1 + 126 * $sec + 120 * $sec + 6 * $sec);
// Full cycle continuity: no drift
$cycle = 4 * (120 + 4 + 2) * $sec;
$s = run_to($pdo, $g1 + 127 * $sec, $g1 + $cycle + 1 * $sec);
check('back to road 1 after full cycle', lights($s), 'green,red,red,red');
check('no drift', $s['phase']['started_ms'], $g1 + $cycle);

echo "Admin adjustments and bounds\n";
$pdo = fresh();
$pdo->exec('UPDATE roads SET green_seconds = 150 WHERE id = 2');
$pdo->exec("UPDATE settings SET setting_value = '140' WHERE setting_key = 'max_green_seconds'");
build_state($pdo, $T0);
$s = run_to($pdo, $T0 + 2 * $sec, $T0 + 2 * $sec + 126 * $sec + 1);
check('road 2 green capped by max', $s['phase']['ends_ms'] - $s['phase']['started_ms'], 140 * $sec);

echo "Rules\n";
$pdo = fresh();
$dt = new DateTimeImmutable('@' . intdiv($T0, 1000)); // UTC
$dow = $dt->format('N');
$hm  = $dt->format('H:i');
$pdo->prepare('INSERT INTO rules (name, road_id, days, start_time, end_time, adjust_seconds) VALUES (?,?,?,?,?,?)')
    ->execute(['rush', 1, $dow, '00:00', '23:59', 30]);
check('rule extends road 1', effective_green_seconds($pdo, get_roads($pdo)[0], get_settings($pdo), $T0), 150);
check('rule ignores road 2', effective_green_seconds($pdo, get_roads($pdo)[1], get_settings($pdo), $T0), 120);
$pdo->exec("UPDATE rules SET adjust_seconds = -200");
check('rule clamped to min', effective_green_seconds($pdo, get_roads($pdo)[0], get_settings($pdo), $T0), 10);
$night = ['active' => 1, 'days' => '1', 'start_time' => '22:00', 'end_time' => '06:00'];
check('overnight: Mon 23:00', rule_matches($night, new DateTimeImmutable('2026-10-05 23:00')), true);   // Monday
check('overnight: Tue 05:00 (Mon rule)', rule_matches($night, new DateTimeImmutable('2026-10-06 05:00')), true);
check('overnight: Mon 05:00 not Sun rule', rule_matches($night, new DateTimeImmutable('2026-10-05 05:00')), false);
check('overnight: Mon 12:00', rule_matches($night, new DateTimeImmutable('2026-10-05 12:00')), false);

echo "Modes and disabled roads\n";
$pdo = fresh();
build_state($pdo, $T0); build_state($pdo, $T0 + 3 * $sec);
set_mode($pdo, 'flashing');
check('flashing', lights(build_state($pdo, $T0 + 4 * $sec)), 'flash_orange,flash_orange,flash_orange,flash_orange');
set_mode($pdo, 'all_red');
check('all red', lights(build_state($pdo, $T0 + 5 * $sec)), 'red,red,red,red');
set_mode($pdo, 'hold', 3);
$s = build_state($pdo, $T0 + 999 * $sec);
check('hold road 3', lights($s), 'red,red,green,red');
check('hold has no timer', $s['roads'][2]['changes_at_ms'], null);
set_mode($pdo, 'auto', 0, $T0 + 1000 * $sec);
check('auto resumes with all red', lights(build_state($pdo, $T0 + 1000 * $sec + 500)), 'red,red,red,red');
check('then road 4 follows held road 3', lights(build_state($pdo, $T0 + 1000 * $sec + 2500)), 'red,red,red,green');
$pdo->exec('UPDATE roads SET enabled = 0 WHERE id = 2');
$pdo = fresh(); $pdo->exec('UPDATE roads SET enabled = 0 WHERE id = 2');
build_state($pdo, $T0); $g = build_state($pdo, $T0 + 2 * $sec)['phase']['started_ms'];
check('disabled road is off and skipped', lights(build_state($pdo, $g + 127 * $sec)), 'red,off,green,red');
check('long outage restarts cleanly', lights(build_state($pdo, $g + 100000 * $sec)), 'red,off,red,red');

echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
