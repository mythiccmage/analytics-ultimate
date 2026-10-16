<?php
// Test helper: php tests/dbq.php "SQL" -> prints the first column of the first row.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dirs = glob('/srv/home/analytics-ultimate-data-*');
$p = new PDO('sqlite:' . $dirs[0] . '/analytics.sqlite');
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = $argv[1] ?? 'select 1';
if (preg_match('/^\s*(delete|update|insert)/i', $sql)) { $p->exec($sql); exit; }
$v = $p->query($sql)->fetchColumn();
echo $v === false ? '' : $v;
