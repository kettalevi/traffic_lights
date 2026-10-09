<?php
declare(strict_types=1);

function auth_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
        session_name('tl_admin');
        session_start();
    }
}

function auth_user(): ?string
{
    auth_start();
    return $_SESSION['user'] ?? null;
}

function auth_require(): string
{
    $u = auth_user();
    if (!$u) {
        header('Location: /admin/login.php');
        exit;
    }
    return $u;
}

function auth_login(PDO $pdo, string $username, string $password): bool
{
    $st = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    // Always run a hash check so timing doesn't reveal whether the user exists.
    $ok = password_verify($password, $u['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
    if ($u && $ok) {
        auth_start();
        session_regenerate_id(true);
        $_SESSION['user'] = $u['username'];
        return true;
    }
    usleep(600000); // slow down guessing
    return false;
}

function csrf_token(): string
{
    auth_start();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
}

function csrf_check(): void
{
    auth_start();
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('Invalid CSRF token. Go back and reload the page.');
    }
}

function audit(PDO $pdo, string $user, string $action, string $detail = ''): void
{
    $pdo->prepare('INSERT INTO audit_log (username, action, detail, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$user, $action, mb_substr($detail, 0, 255), date('Y-m-d H:i:s')]);
}

function create_user(PDO $pdo, string $username, string $password): void
{
    $pdo->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
}
