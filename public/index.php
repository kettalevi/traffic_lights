<?php
require __DIR__ . '/../src/bootstrap.php';
[$css] = bootstrap_assets();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Traffic Lights</title><link rel="stylesheet" href="<?= h($css) ?>"></head>
<body class="bg-light"><div class="container py-5" style="max-width:640px">
  <h1 class="h3 mb-4">Traffic Light Control</h1>
  <div class="list-group">
    <a class="list-group-item list-group-item-action" href="/display.php">Screen display: all four roads</a>
    <?php for ($i = 1; $i <= 4; $i++): ?>
      <a class="list-group-item list-group-item-action" href="/display.php?road=<?= $i ?>&amp;kiosk=1">Screen display: Road <?= $i ?> only (kiosk)</a>
    <?php endfor; ?>
    <a class="list-group-item list-group-item-action" href="/api/state.php">JSON state (for hardware controllers)</a>
    <a class="list-group-item list-group-item-action fw-semibold" href="/admin/">Admin</a>
  </div></div></body></html>
