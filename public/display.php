<?php
// Screen display for locations where physical lights are unavailable.
//   /display.php            all four roads (2x2)
//   /display.php?road=3     one road, full screen (mount a screen facing that road)
//   add &kiosk=1            hides controls and the mouse cursor
require __DIR__ . '/../src/bootstrap.php';
[$css, $js] = bootstrap_assets();
$road  = $_GET['road'] ?? 'all';
$road  = ctype_digit((string) $road) && (int) $road >= 1 ? (int) $road : 'all';
$kiosk = !empty($_GET['kiosk']);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Traffic Signal Display</title>
  <link rel="stylesheet" href="<?= h($css) ?>">
  <link rel="stylesheet" href="/assets/css/signals.css">
</head>
<body class="display<?= $kiosk ? ' kiosk' : '' ?>">
  <div id="board"></div>
  <div class="display-tools"><button class="btn btn-sm btn-outline-light" id="fs" type="button">Fullscreen</button></div>
  <script src="/assets/js/signals.js"></script>
  <script>
    new TrafficBoard(document.getElementById('board'), { road: <?= json_encode($road) ?> });
    document.getElementById('fs').addEventListener('click', function () {
      (document.documentElement.requestFullscreen || function () {}).call(document.documentElement);
    });
    // Keep the screen awake where supported.
    if (navigator.wakeLock) { navigator.wakeLock.request('screen').catch(function () {}); }
  </script>
</body>
</html>
