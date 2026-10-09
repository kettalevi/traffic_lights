<?php
declare(strict_types=1);

/**
 * Signal engine.
 *
 * The controller is a small state machine persisted in `signal_state`. Phases for
 * each enabled road, in order:   GREEN -> ORANGE -> RED (all-red clearance) -> next road GREEN ...
 *
 * Nothing runs in the background: every caller (screens, hardware controllers,
 * the admin page) hits state.php, which "catches up" the state machine to the
 * current time inside a transaction. Because the single DB row is the source of
 * truth, every screen and light shows exactly the same thing.
 *
 * Green duration is decided at the moment a green phase starts:
 *   clamp(road base seconds + sum of matching active rules, min, max)
 */

const MODES = ['auto', 'flashing', 'all_red', 'hold'];
const MAX_GAP_MS = 60000; // if nobody polled for this long, restart the cycle instead of replaying it

function now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function get_settings(PDO $pdo): array
{
    $s = DEFAULT_SETTINGS;
    foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $r) {
        $s[$r['setting_key']] = $r['setting_value'];
    }
    return $s;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $st = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key = ?');
    $st->execute([$key]);
    if ($st->fetchColumn()) {
        $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
    } else {
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
    }
}

function get_roads(PDO $pdo, bool $enabledOnly = false): array
{
    $sql = 'SELECT * FROM roads' . ($enabledOnly ? ' WHERE enabled = 1' : '') . ' ORDER BY sort_order, id';
    return $pdo->query($sql)->fetchAll();
}

/** Does this rule apply at the given local moment? Supports overnight windows (22:00-06:00). */
function rule_matches(array $rule, DateTimeInterface $at): bool
{
    if (!(int) $rule['active']) {
        return false;
    }
    $days = array_map('intval', explode(',', (string) $rule['days']));
    $t    = $at->format('H:i');
    $s    = $rule['start_time'];
    $e    = $rule['end_time'];
    if ($s <= $e) {
        // Same-day window, e.g. 07:00-09:00 on selected days.
        return in_array((int) $at->format('N'), $days, true) && $t >= $s && $t < $e;
    }
    // Overnight window: the part after midnight belongs to the previous day's rule.
    if ($t >= $s) {
        return in_array((int) $at->format('N'), $days, true);
    }
    if ($t < $e) {
        $prev = (int) $at->format('N') === 1 ? 7 : (int) $at->format('N') - 1;
        return in_array($prev, $days, true);
    }
    return false;
}

function active_rules(PDO $pdo, int $roadId, int $atMs): array
{
    $at = (new DateTimeImmutable('@' . intdiv($atMs, 1000)))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $st = $pdo->prepare('SELECT * FROM rules WHERE active = 1 AND (road_id IS NULL OR road_id = ?) ORDER BY id');
    $st->execute([$roadId]);
    return array_values(array_filter($st->fetchAll(), fn ($r) => rule_matches($r, $at)));
}

/** Green time (seconds) a road would get if its green started at $atMs. */
function effective_green_seconds(PDO $pdo, array $road, array $settings, int $atMs): int
{
    $secs = (int) $road['green_seconds'];
    foreach (active_rules($pdo, (int) $road['id'], $atMs) as $r) {
        $secs += (int) $r['adjust_seconds'];
    }
    return max((int) $settings['min_green_seconds'], min((int) $settings['max_green_seconds'], $secs));
}

/** Road that follows $roadId in the cycle (wraps; tolerates $roadId no longer being enabled). */
function next_road_id(array $enabledRoads, int $roadId): int
{
    $ids = array_map(fn ($r) => (int) $r['id'], $enabledRoads);
    $i   = array_search($roadId, $ids, true);
    if ($i === false) {
        return $ids[0];
    }
    return $ids[($i + 1) % count($ids)];
}

/**
 * Pure transition function: what comes after the given phase?
 * @param callable(array,int):int $greenSeconds  fn(road, startMs) => seconds
 * @return array{road:int,type:string,duration_ms:int}
 */
function next_phase(array $enabledRoads, array $settings, string $type, int $roadId, int $startMs, callable $greenSeconds): array
{
    $orange = max(1, (int) $settings['orange_seconds']) * 1000;
    $allRed = max(1, (int) $settings['all_red_seconds']) * 1000;
    if ($type === 'green') {
        return ['road' => $roadId, 'type' => 'orange', 'duration_ms' => $orange];
    }
    if ($type === 'orange') {
        return ['road' => $roadId, 'type' => 'red', 'duration_ms' => $allRed];
    }
    $nextId = next_road_id($enabledRoads, $roadId);
    $road   = null;
    foreach ($enabledRoads as $r) {
        if ((int) $r['id'] === $nextId) {
            $road = $r;
        }
    }
    return ['road' => $nextId, 'type' => 'green', 'duration_ms' => max(1000, $greenSeconds($road, $startMs) * 1000)];
}

/** Bring the persisted state machine up to $nowMs. Returns the state row. */
function advance(PDO $pdo, int $nowMs): array
{
    $settings = get_settings($pdo);
    $roads    = get_roads($pdo, true);
    $lock     = db_driver($pdo) === 'mysql' ? ' FOR UPDATE' : '';

    $pdo->beginTransaction();
    try {
        $row = $pdo->query('SELECT * FROM signal_state WHERE id = 1' . $lock)->fetch();
        if (!$row) {
            $pdo->exec("INSERT INTO signal_state (id) VALUES (1)");
            $row = $pdo->query('SELECT * FROM signal_state WHERE id = 1' . $lock)->fetch();
        }
        if ($row['mode'] === 'auto' && $roads) {
            $orig    = $row;
            $greenFn = fn (array $road, int $at) => effective_green_seconds($pdo, $road, $settings, $at);
            $end     = (int) $row['started_ms'] + (int) $row['duration_ms'];

            if ($nowMs - $end > MAX_GAP_MS || (int) $row['duration_ms'] <= 0) {
                // Cold start or long outage: begin with an all-red gap, then the first road.
                $row['phase_type']  = 'red';
                $row['phase_road']  = (int) end($roads)['id'];
                $row['started_ms']  = $nowMs;
                $row['duration_ms'] = max(1, (int) $settings['all_red_seconds']) * 1000;
                $end = $nowMs + (int) $row['duration_ms'];
            }
            for ($guard = 0; $nowMs >= $end && $guard < 100; $guard++) {
                $n = next_phase($roads, $settings, $row['phase_type'], (int) $row['phase_road'], $end, $greenFn);
                $row['phase_road']  = $n['road'];
                $row['phase_type']  = $n['type'];
                $row['started_ms']  = $end; // continuous: no drift
                $row['duration_ms'] = $n['duration_ms'];
                $end += $n['duration_ms'];
            }
            if ($row != $orig) {
                $pdo->prepare('UPDATE signal_state SET phase_road = ?, phase_type = ?, started_ms = ?, duration_ms = ? WHERE id = 1')
                    ->execute([$row['phase_road'], $row['phase_type'], $row['started_ms'], $row['duration_ms']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $row;
}

/**
 * Admin override. 'auto' resumes the cycle through an all-red gap; the other modes
 * apply immediately (intended for incidents/maintenance).
 */
function set_mode(PDO $pdo, string $mode, int $holdRoad = 0, ?int $nowMs = null): void
{
    if (!in_array($mode, MODES, true)) {
        throw new InvalidArgumentException('Unknown mode');
    }
    $nowMs ??= now_ms();
    $settings = get_settings($pdo);
    $row = $pdo->query('SELECT * FROM signal_state WHERE id = 1')->fetch();
    if ($mode === 'auto') {
        // Resume with an all-red clearance after the road that was last shown green.
        $from = $row['mode'] === 'hold' ? (int) $row['hold_road'] : (int) $row['phase_road'];
        $pdo->prepare("UPDATE signal_state SET mode = 'auto', hold_road = 0, phase_road = ?, phase_type = 'red', started_ms = ?, duration_ms = ? WHERE id = 1")
            ->execute([$from, $nowMs, max(1, (int) $settings['all_red_seconds']) * 1000]);
    } else {
        $pdo->prepare('UPDATE signal_state SET mode = ?, hold_road = ? WHERE id = 1')
            ->execute([$mode, $mode === 'hold' ? $holdRoad : 0]);
    }
}

/**
 * Public snapshot consumed by screens and hardware controllers.
 *
 * light:  green | orange | red | flash_orange | off
 * changes_at_ms: when this road's light is expected to change (null = manual hold)
 * green_at_ms:   for stopped roads, estimated start of their next green (uses current rules)
 */
function build_state(PDO $pdo, ?int $nowMs = null): array
{
    $nowMs ??= now_ms();
    $row      = advance($pdo, $nowMs);
    $settings = get_settings($pdo);
    $all      = get_roads($pdo);
    $enabled  = get_roads($pdo, true);
    $mode     = $enabled ? $row['mode'] : 'flashing';

    $phase = null;
    $light = [];   // road id => light
    $until = [];   // road id => changes_at_ms
    $greenAt = []; // road id => green_at_ms

    foreach ($all as $r) {
        $light[(int) $r['id']] = (int) $r['enabled'] ? 'red' : 'off';
    }

    if ($mode === 'auto') {
        $end   = (int) $row['started_ms'] + (int) $row['duration_ms'];
        $phase = ['road' => (int) $row['phase_road'], 'type' => $row['phase_type'],
                  'started_ms' => (int) $row['started_ms'], 'ends_ms' => $end];
        if ($row['phase_type'] !== 'red') {
            $light[$phase['road']] = $row['phase_type'];
            $until[$phase['road']] = $end;
        }
        // Project forward to estimate each stopped road's next green.
        $greenFn = fn (array $road, int $at) => effective_green_seconds($pdo, $road, $settings, $at);
        $t = $end; $type = $row['phase_type']; $rid = (int) $row['phase_road'];
        for ($i = 0; $i < 3 * count($enabled) + 3; $i++) {
            $n = next_phase($enabled, $settings, $type, $rid, $t, $greenFn);
            if ($n['type'] === 'green' && !isset($greenAt[$n['road']])) {
                $greenAt[$n['road']] = $t;
            }
            $type = $n['type']; $rid = $n['road']; $t += $n['duration_ms'];
        }
        if ($row['phase_type'] === 'green') {
            unset($greenAt[$phase['road']]);
        }
    } elseif ($mode === 'flashing') {
        foreach ($enabled as $r) { $light[(int) $r['id']] = 'flash_orange'; }
    } elseif ($mode === 'hold') {
        $hold = (int) $row['hold_road'];
        if (isset($light[$hold]) && $light[$hold] !== 'off') { $light[$hold] = 'green'; }
    } // all_red: defaults already red

    $roadsOut = [];
    foreach ($all as $r) {
        $id = (int) $r['id'];
        $roadsOut[] = [
            'id'            => $id,
            'name'          => $r['name'],
            'light'         => $light[$id],
            'changes_at_ms' => $until[$id] ?? null,
            'green_at_ms'   => $greenAt[$id] ?? null,
        ];
    }

    return [
        'now_ms' => $nowMs,
        'mode'   => $mode,
        'phase'  => $phase,
        'roads'  => $roadsOut,
    ];
}
