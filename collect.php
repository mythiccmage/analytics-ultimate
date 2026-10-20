<?php
// Receives pageviews, download clicks and custom events from a.js.
// Answers 204 for anything it chooses not to count, so a tracked site never sees an error.

require __DIR__ . '/lib/bootstrap.php';

$cfg = auc_config();
$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
$originHost = $origin !== '' ? (string) parse_url($origin, PHP_URL_HOST) : '';

if ($cfg && $originHost !== '' && auc_host_allowed($originHost, $cfg['sites'])) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin');
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    http_response_code(405);
    exit;
}
if (!$cfg) {
    http_response_code(204);
    exit;
}

$raw = (string) file_get_contents('php://input', false, null, 0, 4097);
if (strlen($raw) > 4096) {
    http_response_code(413);
    exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    exit;
}

http_response_code(204);

$type = (string) ($data['t'] ?? '');
if (!in_array($type, ['pageview', 'download', 'event'], true)) {
    exit;
}
if (auc_is_ignored_request() || auc_is_bot((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
    exit;
}

$host = auc_norm_host((string) ($data['h'] ?? $originHost));
if (!auc_host_allowed($host, $cfg['sites'])) {
    exit;
}
if ($originHost !== '' && auc_norm_host($originHost) !== $host) {
    exit;
}

$path = (string) ($data['p'] ?? '/');
$path = (string) preg_replace('/[?#].*$/', '', $path);
$path = substr($path === '' || $path[0] !== '/' ? '/' . $path : $path, 0, 300);

$uid = (string) ($data['u'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $uid)) {
    $uid = 'anon';
}

$ref = '';
$source = strtolower((string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($data['s'] ?? '')));
if ($source !== '') {
    $ref = substr($source, 0, 60);
} elseif (!empty($data['r'])) {
    $rh = auc_norm_host((string) parse_url((string) $data['r'], PHP_URL_HOST));
    if ($rh !== '' && $rh !== $host && !auc_host_allowed($rh, $cfg['sites'])) {
        $ref = substr($rh, 0, 100);
    }
}

$label = '';
if ($type !== 'pageview') {
    $label = substr(trim((string) ($data['l'] ?? '')), 0, 200);
    if ($label === '') {
        exit;
    }
}

try {
    if ($type === 'pageview') {
        $st = auc_db()->prepare('SELECT 1 FROM events WHERE kind = ? AND uid = ? AND host = ? AND path = ? AND ts > ? LIMIT 1');
        $st->execute(['pageview', $uid, $host, $path, time() - 2]);
        if ($st->fetch()) {
            exit;
        }
    }
    auc_record($type, $host, $path, $ref, $uid, $label, $type === 'download' ? 'page' : '');
} catch (Throwable $e) {
    error_log('analytics-ultimate collect: ' . $e->getMessage());
}
