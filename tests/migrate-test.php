<?php
// Test helper: upgrades a database created by 1.0.x (no "via" column) and checks nothing is lost.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
$file = sys_get_temp_dir() . '/auc-migrate-' . bin2hex(random_bytes(4)) . '.sqlite';
$pdo = new PDO('sqlite:' . $file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT)');
$pdo->exec("INSERT INTO meta VALUES ('schema', '1')");
$pdo->exec('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, day TEXT NOT NULL, kind TEXT NOT NULL,
    host TEXT NOT NULL, path TEXT NOT NULL DEFAULT "", ref TEXT NOT NULL DEFAULT "", uid TEXT NOT NULL, label TEXT NOT NULL DEFAULT "")');
$pdo->exec('CREATE TABLE login_attempts (ip_hash TEXT NOT NULL, ts INTEGER NOT NULL)');
$pdo->exec("INSERT INTO events (ts, day, kind, host, path, uid, label) VALUES (1, '2026-01-01', 'download', 'example.com', '/', 'u1', 'old-file.zip')");

auc_migrate($pdo);
auc_migrate($pdo); // running it twice must be harmless

$cols = $pdo->query('PRAGMA table_info(events)')->fetchAll(PDO::FETCH_COLUMN, 1);
$row = $pdo->query('SELECT label, via FROM events')->fetch(PDO::FETCH_ASSOC);
$schema = $pdo->query("SELECT v FROM meta WHERE k = 'schema'")->fetchColumn();
$ok = in_array('via', $cols, true) && $row['label'] === 'old-file.zip' && $row['via'] === '' && (int) $schema === AUC_SCHEMA;
@unlink($file);
echo $ok ? "OK\n" : "FAIL\n";
exit($ok ? 0 : 1);
