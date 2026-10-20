<?php
// Tracked download links:  https://your-stats-domain/analytics-ultimate/go.php?l=KEY
// Counts the click on the server, then redirects to the file. Because the count happens here
// and not in the browser, it also catches "save link as", download managers and shared links.
// Only links you have added under Downloads can be used, so this can't be abused as an open redirect.

require __DIR__ . '/lib/bootstrap.php';

$cfg = auc_config();
$key = (string) ($_GET['l'] ?? '');
$link = ($cfg && isset($cfg['downloads'][$key])) ? $cfg['downloads'][$key] : null;
if (!$link || empty($link['target'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Unknown download link.');
}
$target = (string) $link['target'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($method === 'GET' && !auc_is_ignored_request() && !auc_is_bot($ua)) {
    $refHost = '';
    $refPath = '/';
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $refHost = auc_norm_host((string) parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_HOST));
        $refPath = (string) (parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_PATH) ?: '/');
    }
    // A real click on a download button carries one of your sites as the Referer; scanners, bots and
    // shared links usually don't. Recorded so the dashboard can show the two apart.
    $via = ($refHost !== '' && auc_host_allowed($refHost, $cfg['sites'])) ? 'page' : 'direct';
    if ($via === 'direct') {
        $refHost = auc_norm_host((string) ($cfg['sites'][0] ?? ($_SERVER['HTTP_HOST'] ?? 'unknown')));
        $refHost = ltrim($refHost, '*.');
        $refPath = '/';
    }
    $uid = substr(hash('sha256', ($cfg['salt'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? '') . $ua), 0, 32);
    try {
        auc_record('download', $refHost, substr($refPath, 0, 300), '', $uid, substr($key, 0, 200), $via);
    } catch (Throwable $e) {
        error_log('analytics-ultimate go: ' . $e->getMessage());
    }
}

if (preg_match('#^https?://#i', $target)) {
    $to = $target;
} else {
    // A path on the visitor's current site. Encode each segment but keep the slashes.
    $path = '/' . ltrim($target, '/');
    $to = implode('/', array_map('rawurlencode', explode('/', $path)));
}
header('Cache-Control: no-store');
header('Location: ' . $to, true, 302);
