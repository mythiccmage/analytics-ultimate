<?php
// Admin sign-in: sessions, CSRF tokens and login throttling.

function auc_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('auc_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => auc_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function auc_is_admin(): bool
{
    auc_session();
    return !empty($_SESSION['auc_ok']);
}

function auc_csrf(): string
{
    auc_session();
    if (empty($_SESSION['auc_csrf'])) {
        $_SESSION['auc_csrf'] = auc_random(16);
    }
    return $_SESSION['auc_csrf'];
}

function auc_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . auc_h(auc_csrf()) . '">';
}

function auc_csrf_ok(): bool
{
    auc_session();
    return !empty($_POST['csrf']) && !empty($_SESSION['auc_csrf']) && hash_equals($_SESSION['auc_csrf'], (string) $_POST['csrf']);
}

function auc_ip_hash(): string
{
    $cfg = auc_config();
    return hash('sha256', ($cfg['salt'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? ''));
}

/** Seconds the caller must wait, or 0. 8 failures in 15 minutes locks sign-in for that visitor. */
function auc_login_blocked(): int
{
    $pdo = auc_db();
    $st = $pdo->prepare('SELECT COUNT(*) AS c, MIN(ts) AS first FROM login_attempts WHERE ip_hash = ? AND ts > ?');
    $st->execute([auc_ip_hash(), time() - 900]);
    $r = $st->fetch();
    return ((int) $r['c'] >= 8) ? max(1, 900 - (time() - (int) $r['first'])) : 0;
}

function auc_login_fail(): void
{
    $pdo = auc_db();
    $pdo->prepare('INSERT INTO login_attempts (ip_hash, ts) VALUES (?, ?)')->execute([auc_ip_hash(), time()]);
    $pdo->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 86400]);
}

function auc_login(string $password): bool
{
    $cfg = auc_config();
    if ($cfg && password_verify($password, (string) $cfg['password_hash'])) {
        auc_session();
        session_regenerate_id(true);
        $_SESSION['auc_ok'] = true;
        $_SESSION['auc_csrf'] = auc_random(16);
        $pdo = auc_db();
        $pdo->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([auc_ip_hash()]);
        return true;
    }
    auc_login_fail();
    return false;
}

function auc_logout(): void
{
    auc_session();
    $_SESSION = [];
    session_destroy();
}
