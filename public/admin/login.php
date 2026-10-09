<?php
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/auth.php';
[$css] = bootstrap_assets();
auth_start();
if (auth_user()) { header('Location: /admin/'); exit; }
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (auth_login(db(), trim($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        header('Location: /admin/'); exit;
    }
    $error = 'Invalid username or password.';
}
?><!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin sign in</title><link rel="stylesheet" href="<?= h($css) ?>">
</head><body class="bg-light">
<div class="container" style="max-width:380px"><div class="card shadow-sm mt-5"><div class="card-body">
  <h1 class="h4 mb-3">Traffic Lights Admin</h1>
  <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" autofocus required></div>
    <div class="mb-3"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required></div>
    <button class="btn btn-primary w-100">Sign in</button>
  </form>
</div></div></div></body></html>
