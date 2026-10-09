<?php
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/auth.php';
$user = auth_require();
$pdo  = db();
[$css, $js] = bootstrap_assets();

function flash(string $msg, string $type = 'success'): void { $_SESSION['flash'] = [$msg, $type]; }
function int_in(string $key, int $min, int $max): ?int
{
    $v = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    return ($v === false || $v === null || $v < $min || $v > $max) ? null : $v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $S      = get_settings($pdo);
    $min    = (int) $S['min_green_seconds'];
    $max    = (int) $S['max_green_seconds'];
    $step   = (int) $S['step_seconds'];
    $clamp  = fn (int $v) => max($min, min($max, $v));
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'mode':
            $mode = $_POST['mode'] ?? '';
            $hold = (int) ($_POST['hold_road'] ?? 0);
            if (!in_array($mode, MODES, true)) { flash('Unknown mode.', 'danger'); break; }
            set_mode($pdo, $mode, $hold);
            audit($pdo, $user, 'mode', $mode . ($mode === 'hold' ? " road $hold" : ''));
            flash("Mode set to $mode.");
            break;

        case 'road_adjust':   // the +/- buttons
            $id  = (int) $_POST['road_id'];
            $dir = ($_POST['dir'] ?? '') === 'down' ? -1 : 1;
            $cur = (int) $pdo->query("SELECT green_seconds FROM roads WHERE id = $id")->fetchColumn();
            $new = $clamp($cur + $dir * $step);
            $pdo->prepare('UPDATE roads SET green_seconds = ? WHERE id = ?')->execute([$new, $id]);
            audit($pdo, $user, 'green_time', "road $id: $cur -> $new s");
            flash("Road $id green time is now {$new}s (applies from its next green).");
            break;

        case 'all_adjust':
            $dir = ($_POST['dir'] ?? '') === 'down' ? -1 : 1;
            foreach (get_roads($pdo) as $r) {
                $pdo->prepare('UPDATE roads SET green_seconds = ? WHERE id = ?')->execute([$clamp((int) $r['green_seconds'] + $dir * $step), $r['id']]);
            }
            audit($pdo, $user, 'green_time_all', ($dir > 0 ? '+' : '-') . "{$step}s");
            flash('All roads ' . ($dir > 0 ? 'increased' : 'decreased') . " by {$step}s.");
            break;

        case 'reset_green':
            $pdo->prepare('UPDATE roads SET green_seconds = ?')->execute([(int) $S['default_green_seconds']]);
            audit($pdo, $user, 'green_time_reset', $S['default_green_seconds'] . 's');
            flash('All roads reset to the default green time.');
            break;

        case 'road_save':
            $id = (int) $_POST['road_id'];
            $g  = int_in('green_seconds', $min, $max);
            $name = trim($_POST['name'] ?? '');
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $othersEnabled = (int) $pdo->query("SELECT COUNT(*) FROM roads WHERE enabled = 1 AND id <> $id")->fetchColumn();
            if ($g === null || $name === '' || mb_strlen($name) > 80) { flash("Green time must be between {$min} and {$max} seconds and a name is required.", 'danger'); break; }
            if (!$enabled && !$othersEnabled) { flash('At least one road must stay enabled.', 'danger'); break; }
            $pdo->prepare('UPDATE roads SET name = ?, green_seconds = ?, enabled = ? WHERE id = ?')->execute([$name, $g, $enabled, $id]);
            audit($pdo, $user, 'road_save', "road $id: $name, {$g}s, " . ($enabled ? 'enabled' : 'disabled'));
            flash("Saved road $id.");
            break;

        case 'settings_save':
            $vals = [
                'default_green_seconds' => int_in('default_green_seconds', 5, 3600),
                'orange_seconds'        => int_in('orange_seconds', 1, 30),
                'all_red_seconds'       => int_in('all_red_seconds', 1, 30),
                'min_green_seconds'     => int_in('min_green_seconds', 5, 3600),
                'max_green_seconds'     => int_in('max_green_seconds', 5, 3600),
                'step_seconds'          => int_in('step_seconds', 1, 300),
            ];
            if (in_array(null, $vals, true) || $vals['min_green_seconds'] > $vals['max_green_seconds']) {
                flash('Invalid settings: check ranges, and that minimum is not above maximum.', 'danger'); break;
            }
            foreach ($vals as $k => $v) { set_setting($pdo, $k, (string) $v); }
            audit($pdo, $user, 'settings', json_encode($vals));
            flash('Settings saved.');
            break;

        case 'rule_add':
            $name = trim($_POST['name'] ?? '');
            $days = array_values(array_unique(array_filter(array_map('intval', $_POST['days'] ?? []), fn ($d) => $d >= 1 && $d <= 7)));
            sort($days);
            $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
            $secs = int_in('seconds', 1, 3600);
            $road = ($_POST['road_id'] ?? '') === '' ? null : (int) $_POST['road_id'];
            if ($name === '' || !$days || $secs === null || !preg_match($time, $_POST['start_time'] ?? '') || !preg_match($time, $_POST['end_time'] ?? '') || ($_POST['start_time'] === $_POST['end_time'])) {
                flash('Rule needs a name, at least one day, valid different start/end times and a number of seconds.', 'danger'); break;
            }
            $adj = ($_POST['direction'] ?? '') === 'decrease' ? -$secs : $secs;
            $pdo->prepare('INSERT INTO rules (name, road_id, days, start_time, end_time, adjust_seconds, active) VALUES (?,?,?,?,?,?,1)')
                ->execute([mb_substr($name, 0, 120), $road, implode(',', $days), $_POST['start_time'], $_POST['end_time'], $adj]);
            audit($pdo, $user, 'rule_add', $name);
            flash('Rule added.');
            break;

        case 'rule_toggle':
            $pdo->prepare('UPDATE rules SET active = 1 - active WHERE id = ?')->execute([(int) $_POST['rule_id']]);
            audit($pdo, $user, 'rule_toggle', (string) (int) $_POST['rule_id']);
            flash('Rule updated.');
            break;

        case 'rule_delete':
            $pdo->prepare('DELETE FROM rules WHERE id = ?')->execute([(int) $_POST['rule_id']]);
            audit($pdo, $user, 'rule_delete', (string) (int) $_POST['rule_id']);
            flash('Rule deleted.');
            break;

        case 'password':
            $st = $pdo->prepare('SELECT password_hash FROM users WHERE username = ?');
            $st->execute([$user]);
            $new = (string) ($_POST['new_password'] ?? '');
            if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $st->fetchColumn())) { flash('Current password is wrong.', 'danger'); break; }
            if (strlen($new) < 8) { flash('New password must be at least 8 characters.', 'danger'); break; }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE username = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user]);
            audit($pdo, $user, 'password', '');
            flash('Password changed.');
            break;

        case 'user_add':
            $u = trim($_POST['username'] ?? '');
            $p = (string) ($_POST['password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $u) || strlen($p) < 8) { flash('Username (3-64 letters/digits) and a password of 8+ characters are required.', 'danger'); break; }
            try { create_user($pdo, $u, $p); audit($pdo, $user, 'user_add', $u); flash("Admin $u created."); }
            catch (PDOException $e) { flash('That username already exists.', 'danger'); }
            break;
    }
    header('Location: /admin/');
    exit;
}

$S      = get_settings($pdo);
$roads  = get_roads($pdo);
$rules  = $pdo->query('SELECT * FROM rules ORDER BY id')->fetchAll();
$log    = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 10')->fetchAll();
$state  = build_state($pdo);
$now    = now_ms();
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$roadNames = array_column($roads, 'name', 'id');
$effective = [];
foreach ($roads as $r) { $effective[$r['id']] = effective_green_seconds($pdo, $r, $S, $now); }
$csrf = csrf_token();

function fmt_secs(int $s): string { return intdiv($s, 60) . 'm ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . 's'; }
function post_form(string $csrf, string $action, array $fields, string $label, string $class = 'btn-outline-secondary', string $confirm = ''): string
{
    $out = '<form method="post" class="d-inline"' . ($confirm ? ' onsubmit="return confirm(\'' . h($confirm) . '\')"' : '') . '>'
         . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($fields as $k => $v) { $out .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">'; }
    return $out . '<button class="btn btn-sm ' . $class . '">' . $label . '</button></form>';
}
?><!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Traffic Lights Admin</title>
  <link rel="stylesheet" href="<?= h($css) ?>">
  <link rel="stylesheet" href="/assets/css/signals.css">
</head><body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-3"><div class="container-xl">
  <span class="navbar-brand">Traffic Lights Admin</span>
  <span class="text-light small">
    <a class="link-light me-3" href="/display.php" target="_blank">Open screen display</a>
    <?= h($user) ?> · <a class="link-light" href="/admin/logout.php">Sign out</a>
  </span>
</div></nav>

<main class="container-xl pb-5">
<?php if ($flash): ?><div class="alert alert-<?= h($flash[1]) ?> py-2"><?= h($flash[0]) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card shadow-sm"><div class="card-header fw-semibold">Live status <span class="badge bg-secondary ms-2" id="mode-badge"><?= h($state['mode']) ?></span></div>
      <div class="card-body bg-dark text-light"><div id="live"></div></div>
      <div class="card-footer">
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <?= post_form($csrf, 'mode', ['mode' => 'auto'], 'Automatic cycle', 'btn-success') ?>
          <?= post_form($csrf, 'mode', ['mode' => 'flashing'], 'Flashing orange', 'btn-warning', 'Switch all roads to flashing orange?') ?>
          <?= post_form($csrf, 'mode', ['mode' => 'all_red'], 'All red', 'btn-danger', 'Switch all roads to red?') ?>
          <form method="post" class="d-inline-flex gap-1" onsubmit="return confirm('Hold this road on green and stop the others?')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="mode"><input type="hidden" name="mode" value="hold">
            <select name="hold_road" class="form-select form-select-sm w-auto">
              <?php foreach ($roads as $r): if ($r['enabled']): ?><option value="<?= (int) $r['id'] ?>"><?= h($r['name']) ?></option><?php endif; endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary">Hold green</button>
          </form>
        </div>
        <div class="form-text">Overrides take effect immediately; use them for incidents or maintenance. Resuming automatic passes through an all-red gap.</div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm h-100"><div class="card-header fw-semibold">Global settings</div><div class="card-body">
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="settings_save">
        <?php foreach ([
          'default_green_seconds' => ['Default green (s)', 5, 3600], 'orange_seconds' => ['Orange (s)', 1, 30],
          'all_red_seconds' => ['All-red gap (s)', 1, 30], 'step_seconds' => ['+/- step (s)', 1, 300],
          'min_green_seconds' => ['Minimum green (s)', 5, 3600], 'max_green_seconds' => ['Maximum green (s)', 5, 3600]] as $k => [$label, $lo, $hi]): ?>
          <div class="col-6"><label class="form-label small mb-0"><?= h($label) ?></label>
            <input type="number" class="form-control form-control-sm" name="<?= h($k) ?>" min="<?= $lo ?>" max="<?= $hi ?>" value="<?= h($S[$k]) ?>" required></div>
        <?php endforeach; ?>
        <div class="col-12"><button class="btn btn-primary btn-sm">Save settings</button></div>
      </form>
    </div></div>
  </div>

  <div class="col-12">
    <div class="card shadow-sm"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span class="fw-semibold">Roads &amp; green time</span>
      <span>
        <?= post_form($csrf, 'all_adjust', ['dir' => 'down'], 'All −' . (int) $S['step_seconds'] . 's') ?>
        <?= post_form($csrf, 'all_adjust', ['dir' => 'up'], 'All +' . (int) $S['step_seconds'] . 's') ?>
        <?= post_form($csrf, 'reset_green', [], 'Reset all to ' . fmt_secs((int) $S['default_green_seconds']), 'btn-outline-dark', 'Reset every road to the default green time?') ?>
      </span></div>
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Road</th><th>Base green</th><th>Right now (with rules)</th><th>Quick adjust</th><th></th></tr></thead><tbody>
        <?php foreach ($roads as $r): $id = (int) $r['id']; ?>
          <tr>
            <td style="min-width:150px"><input class="form-control form-control-sm" name="name" form="rs<?= $id ?>" value="<?= h($r['name']) ?>" maxlength="80" required></td>
            <td style="width:130px"><div class="input-group input-group-sm"><input type="number" class="form-control" name="green_seconds" form="rs<?= $id ?>" value="<?= (int) $r['green_seconds'] ?>" min="<?= (int) $S['min_green_seconds'] ?>" max="<?= (int) $S['max_green_seconds'] ?>"><span class="input-group-text">s</span></div></td>
            <td><?= fmt_secs($effective[$id]) ?><?= $effective[$id] != $r['green_seconds'] ? ' <span class="badge text-bg-info">rule</span>' : '' ?></td>
            <td class="text-nowrap">
              <?= post_form($csrf, 'road_adjust', ['road_id' => $id, 'dir' => 'down'], '− ' . (int) $S['step_seconds'] . 's') ?>
              <?= post_form($csrf, 'road_adjust', ['road_id' => $id, 'dir' => 'up'], '+ ' . (int) $S['step_seconds'] . 's') ?>
            </td>
            <td class="text-nowrap">
              <form method="post" id="rs<?= $id ?>" class="d-inline-flex align-items-center gap-2">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="road_save"><input type="hidden" name="road_id" value="<?= $id ?>">
                <span class="form-check mb-0"><input class="form-check-input" type="checkbox" name="enabled" id="en<?= $id ?>" <?= $r['enabled'] ? 'checked' : '' ?>><label class="form-check-label small" for="en<?= $id ?>">In cycle</label></span>
                <button class="btn btn-sm btn-primary">Save</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <div class="card-footer small text-muted">A new green time applies from that road's next green phase. Result is always kept between the minimum and maximum green time.</div>
    </div>
  </div>

  <div class="col-12">
    <div class="card shadow-sm"><div class="card-header fw-semibold">Time-of-day rules</div>
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Rule</th><th>Road</th><th>Days</th><th>Time</th><th>Adjustment</th><th></th></tr></thead><tbody>
        <?php foreach ($rules as $ru): ?>
          <tr class="<?= $ru['active'] ? '' : 'text-muted' ?>">
            <td><?= h($ru['name']) ?><?= $ru['active'] ? '' : ' <span class="badge text-bg-secondary">off</span>' ?></td>
            <td><?= $ru['road_id'] === null ? 'All roads' : h($roadNames[$ru['road_id']] ?? ('Road ' . $ru['road_id'])) ?></td>
            <td><?= h(implode(' ', array_map(fn ($d) => $dayNames[(int) $d] ?? '', explode(',', $ru['days'])))) ?></td>
            <td><?= h($ru['start_time']) ?>–<?= h($ru['end_time']) ?></td>
            <td><?= (int) $ru['adjust_seconds'] > 0 ? '+' : '−' ?><?= abs((int) $ru['adjust_seconds']) ?>s</td>
            <td class="text-nowrap">
              <?= post_form($csrf, 'rule_toggle', ['rule_id' => $ru['id']], $ru['active'] ? 'Disable' : 'Enable') ?>
              <?= post_form($csrf, 'rule_delete', ['rule_id' => $ru['id']], 'Delete', 'btn-outline-danger', 'Delete this rule?') ?>
            </td>
          </tr>
        <?php endforeach; if (!$rules): ?><tr><td colspan="6" class="text-muted">No rules yet. Example: +40s for Road 1 from 07:00 to 09:00 on weekdays.</td></tr><?php endif; ?>
        </tbody></table></div>
      <div class="card-footer">
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="rule_add">
          <div class="col-md-3"><label class="form-label small mb-0">Name</label><input class="form-control form-control-sm" name="name" placeholder="Morning rush" required></div>
          <div class="col-md-2"><label class="form-label small mb-0">Road</label>
            <select class="form-select form-select-sm" name="road_id"><option value="">All roads</option>
              <?php foreach ($roads as $r): ?><option value="<?= (int) $r['id'] ?>"><?= h($r['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-2"><label class="form-label small mb-0">From</label><input type="time" class="form-control form-control-sm" name="start_time" required></div>
          <div class="col-md-2"><label class="form-label small mb-0">To</label><input type="time" class="form-control form-control-sm" name="end_time" required></div>
          <div class="col-md-3"><label class="form-label small mb-0">Adjustment</label>
            <div class="input-group input-group-sm"><select class="form-select" name="direction" style="max-width:110px"><option value="increase">Increase</option><option value="decrease">Decrease</option></select>
              <input type="number" class="form-control" name="seconds" min="1" max="3600" value="30" required><span class="input-group-text">s</span></div></div>
          <div class="col-12">
            <?php foreach ($dayNames as $n => $d): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="days[]" value="<?= $n ?>" id="d<?= $n ?>" <?= $n <= 5 ? 'checked' : '' ?>><label class="form-check-label" for="d<?= $n ?>"><?= $d ?></label></div><?php endforeach; ?>
            <button class="btn btn-primary btn-sm ms-3">Add rule</button>
            <span class="form-text ms-2">Overnight windows (e.g. 22:00–06:00) are supported. Matching rules add together.</span>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card shadow-sm h-100"><div class="card-header fw-semibold">Account</div><div class="card-body">
      <form method="post" class="row g-2 mb-3">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="password">
        <div class="col-6"><input type="password" class="form-control form-control-sm" name="current_password" placeholder="Current password" required></div>
        <div class="col-6"><input type="password" class="form-control form-control-sm" name="new_password" placeholder="New password (8+)" minlength="8" required></div>
        <div class="col-12"><button class="btn btn-outline-primary btn-sm">Change password</button></div>
      </form>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="user_add">
        <div class="col-6"><input class="form-control form-control-sm" name="username" placeholder="New admin username" required></div>
        <div class="col-6"><input type="password" class="form-control form-control-sm" name="password" placeholder="Password (8+)" minlength="8" required></div>
        <div class="col-12"><button class="btn btn-outline-secondary btn-sm">Add admin</button></div>
      </form>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card shadow-sm h-100"><div class="card-header fw-semibold">Recent changes</div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($log as $l): ?><li class="list-group-item"><span class="text-muted"><?= h($l['created_at']) ?></span> · <?= h($l['username']) ?> · <b><?= h($l['action']) ?></b> <?= h($l['detail']) ?></li><?php endforeach; ?>
        <?php if (!$log): ?><li class="list-group-item text-muted">Nothing yet.</li><?php endif; ?>
      </ul></div>
  </div>
</div>
</main>
<script src="<?= h($js) ?>"></script>
<script src="/assets/js/signals.js"></script>
<script>
  new TrafficBoard(document.getElementById('live'), {
    road: 'all', compact: true,
    onUpdate: function (s) { document.getElementById('mode-badge').textContent = s.mode; }
  });
</script>
</body></html>
