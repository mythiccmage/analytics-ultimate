<?php
// Analytics Ultimate -- shared helpers. PHP 7.4+, SQLite (PDO), no other dependencies.

if (!defined('AUC_ROOT')) {
    define('AUC_ROOT', dirname(__DIR__));
}
define('AUC_VERSION', '1.0.1');
define('AUC_SCHEMA', 1);

function auc_h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function auc_starts(string $s, string $prefix): bool
{
    return strncmp($s, $prefix, strlen($prefix)) === 0;
}

function auc_ends(string $s, string $suffix): bool
{
    $n = strlen($suffix);
    return $n === 0 || substr($s, -$n) === $suffix;
}

/* ---------------------------------------------------------------- storage */

/**
 * Where data and config live. Preferred: a folder one level ABOVE the web root,
 * so the database can never be fetched over the web. Fallback: ./data inside
 * this folder, protected by .htaccess (Apache/LiteSpeed, i.e. normal cPanel).
 */
function auc_data_candidates(): array
{
    $out = [];
    $doc = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/') : '';
    if ($doc !== '') {
        $out[] = dirname($doc) . '/analytics-ultimate-data-' . substr(md5(AUC_ROOT), 0, 8);
    }
    $out[] = AUC_ROOT . '/data';
    return $out;
}

function auc_data_dir(): ?string
{
    foreach (auc_data_candidates() as $dir) {
        if (is_file($dir . '/config.php')) {
            return $dir;
        }
    }
    return null;
}

function auc_prepare_data_dir(string $dir): bool
{
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    if (!is_writable($dir)) {
        return false;
    }
    @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    @file_put_contents($dir . '/index.html', '');
    return true;
}

function auc_config(bool $reload = false): ?array
{
    static $cfg = false;
    if ($cfg === false || $reload) {
        $dir = auc_data_dir();
        $cfg = null;
        if ($dir !== null) {
            $loaded = include $dir . '/config.php';
            if (is_array($loaded)) {
                $cfg = $loaded;
            }
        }
    }
    return $cfg;
}

function auc_save_config(array $cfg, ?string $dir = null): bool
{
    $dir = $dir ?: auc_data_dir();
    if ($dir === null) {
        return false;
    }
    $tmp = $dir . '/config.tmp.' . bin2hex(random_bytes(4));
    $php = "<?php\n// Analytics Ultimate configuration. Edit from the Settings page.\nreturn " . var_export($cfg, true) . ";\n";
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $dir . '/config.php')) {
        @unlink($tmp);
        return false;
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($dir . '/config.php', true);
    }
    auc_config(true);
    return true;
}

function auc_default_config(): array
{
    return [
        'installed' => time(),
        'password_hash' => '',
        'timezone' => 'UTC',
        'sites' => [],
        'groups' => [],
        'downloads' => [],
        'retention_months' => 0,
    ];
}

/* --------------------------------------------------------------- database */

function auc_db(?string $dir = null): PDO
{
    static $pdo = null;
    if ($pdo === null || $dir !== null) {
        $dir = $dir ?: auc_data_dir();
        if ($dir === null) {
            throw new RuntimeException('Analytics Ultimate is not set up yet.');
        }
        $pdo = new PDO('sqlite:' . $dir . '/analytics.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        auc_migrate($pdo);
    }
    return $pdo;
}

function auc_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)');
    $row = $pdo->query("SELECT v FROM meta WHERE k = 'schema'")->fetch();
    $have = $row ? (int) $row['v'] : 0;
    if ($have < 1) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts INTEGER NOT NULL,
            day TEXT NOT NULL,
            kind TEXT NOT NULL,
            host TEXT NOT NULL,
            path TEXT NOT NULL DEFAULT "",
            ref TEXT NOT NULL DEFAULT "",
            uid TEXT NOT NULL,
            label TEXT NOT NULL DEFAULT ""
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_day ON events (day, kind)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_host ON events (host, day)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_uid ON events (uid)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (ip_hash TEXT NOT NULL, ts INTEGER NOT NULL)');
    }
    if ($have < AUC_SCHEMA) {
        $st = $pdo->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('schema', ?)");
        $st->execute([(string) AUC_SCHEMA]);
    }
}

/* ------------------------------------------------------------ time & hosts */

function auc_tz(): DateTimeZone
{
    $cfg = auc_config();
    try {
        return new DateTimeZone($cfg['timezone'] ?? 'UTC');
    } catch (Exception $e) {
        return new DateTimeZone('UTC');
    }
}

function auc_day(int $ts): string
{
    $d = new DateTime('@' . $ts);
    $d->setTimezone(auc_tz());
    return $d->format('Y-m-d');
}

function auc_norm_host(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('/:\d+$/', '', $host);
    return preg_replace('/^www\./', '', (string) $host);
}

/** True if $host is one of the configured sites. "*.example.com" matches any subdomain (and example.com itself). */
function auc_host_allowed(string $host, array $sites): bool
{
    $h = auc_norm_host($host);
    if ($h === '') {
        return false;
    }
    foreach ($sites as $site) {
        $s = auc_norm_host((string) $site);
        if ($s === '') {
            continue;
        }
        if (auc_starts($s, '*.')) {
            $apex = substr($s, 2);
            if ($h === $apex || auc_ends($h, '.' . $apex)) {
                return true;
            }
        } elseif ($h === $s) {
            return true;
        }
    }
    return false;
}

/* ---------------------------------------------------------- request checks */

function auc_is_bot(string $ua): bool
{
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|headless|playwright|puppeteer|selenium|lighthouse|monitor|uptime|curl|wget|python-requests|httpclient/i', $ua);
}

/** Owner's own browsers (cookie from ignore.php) and scripted tests (X-Analytics-Ignore header) are never counted. */
function auc_is_ignored_request(): bool
{
    return !empty($_COOKIE['auc_ignore']) || !empty($_SERVER['HTTP_X_ANALYTICS_IGNORE']);
}

function auc_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
}

/** Absolute URL of the folder these scripts are served from, with trailing slash. */
function auc_base_url(): string
{
    $scheme = auc_is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $scheme . '://' . $host . $dir . '/';
}

function auc_random(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

/** Insert one event. Used by collect.php and go.php. */
function auc_record(string $kind, string $host, string $path, string $ref, string $uid, string $label): void
{
    $now = time();
    $pdo = auc_db();
    $st = $pdo->prepare('INSERT INTO events (ts, day, kind, host, path, ref, uid, label) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$now, auc_day($now), $kind, $host, $path, $ref, $uid, $label]);

    $cfg = auc_config();
    $months = (int) ($cfg['retention_months'] ?? 0);
    if ($months > 0 && mt_rand(1, 300) === 1) {
        $cut = auc_day(strtotime('-' . $months . ' months'));
        $pdo->prepare('DELETE FROM events WHERE day < ?')->execute([$cut]);
    }
}
