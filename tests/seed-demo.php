<?php
// Fills a throwaway local install with realistic made-up traffic for documentation screenshots.
// Run inside the test container: php tests/seed-demo.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['DOCUMENT_ROOT'] = '/srv/home/public_html';
require __DIR__ . '/../lib/bootstrap.php';
mt_srand(20261016);

$cfg = auc_config();
$cfg['groups'] = [
    ['name' => 'Acme Editor', 'hosts' => ['example.com'], 'paths' => ['/editor'], 'downloads' => ['acme-editor']],
    ['name' => 'Acme Shop', 'hosts' => ['shop.example.com'], 'paths' => [], 'downloads' => []],
    ['name' => 'Blog', 'hosts' => ['blog.example.com'], 'paths' => [], 'downloads' => []],
];
$cfg['downloads'] = [
    'acme-editor-windows' => ['label' => 'Acme Editor for Windows', 'target' => 'https://example.com/files/acme-editor-setup.exe'],
    'acme-editor-linux' => ['label' => 'Acme Editor for Linux', 'target' => 'https://example.com/files/acme-editor.deb'],
    'acme-editor-mac' => ['label' => 'Acme Editor for Mac', 'target' => 'https://example.com/files/acme-editor.dmg'],
    'brochure-pdf' => ['label' => 'Product brochure', 'target' => 'https://example.com/files/brochure.pdf'],
];
auc_save_config($cfg);

$pages = [
    'example.com' => [['/', 30], ['/editor', 24], ['/editor/download', 14], ['/pricing', 10], ['/features', 8], ['/about', 4], ['/contact', 3]],
    'shop.example.com' => [['/', 22], ['/products/desk-lamp', 18], ['/products/notebook', 16], ['/cart', 8], ['/checkout', 5], ['/products/pen-set', 12]],
    'blog.example.com' => [['/', 14], ['/how-we-test-our-editor', 20], ['/release-notes/2-0', 18], ['/tips/keyboard-shortcuts', 22], ['/tips/themes', 10]],
];
$refs = [['', 34], ['google.com', 30], ['news.ycombinator.com', 7], ['reddit.com', 8], ['twitter.com', 5], ['github.com', 6], ['newsletter', 6], ['duckduckgo.com', 4]];
$dls = [['acme-editor-windows', 52], ['acme-editor-linux', 18], ['acme-editor-mac', 20], ['brochure-pdf', 10]];

function pick(array $weighted) {
    $sum = 0; foreach ($weighted as $w) { $sum += $w[1]; }
    $r = mt_rand(1, $sum);
    foreach ($weighted as $w) { $r -= $w[1]; if ($r <= 0) { return $w[0]; } }
    return $weighted[0][0];
}
function uid(): string { return substr(md5((string) mt_rand()), 0, 20); }

$pdo = auc_db();
$pdo->beginTransaction();
$ins = $pdo->prepare('INSERT INTO events (ts, day, kind, host, path, ref, uid, label) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$pool = [];
$days = 75;
$tz = auc_tz();
for ($i = $days; $i >= 0; $i--) {
    $d = new DateTime('today', $tz);
    $d->modify("-$i days");
    $dow = (int) $d->format('N');
    $growth = 0.55 + 0.9 * (($days - $i) / $days);
    $base = (($dow >= 6) ? 22 : 38) * $growth;
    if ($i === 19) { $base *= 2.6; }                  // a launch-day spike
    $visitors = max(3, (int) round($base * (0.8 + mt_rand(0, 40) / 100)));
    for ($v = 0; $v < $visitors; $v++) {
        if ($pool && mt_rand(1, 100) <= 28) { $u = $pool[array_rand($pool)]; } else { $u = uid(); $pool[] = $u; }
        $host = pick([['example.com', 52], ['shop.example.com', 28], ['blog.example.com', 20]]);
        $ref = pick($refs);
        $n = mt_rand(1, 100) <= 45 ? mt_rand(2, 4) : 1;
        $t = (clone $d)->setTime(mt_rand(7, 22), mt_rand(0, 59), mt_rand(0, 59))->getTimestamp();
        for ($k = 0; $k < $n; $k++) {
            $path = pick($pages[$host]);
            $ins->execute([$t + $k * 40, auc_day($t), 'pageview', $host, $path, $k === 0 ? $ref : '', $u, '']);
            if ($host === 'example.com' && $path === '/editor/download' && mt_rand(1, 100) <= 55) {
                $ins->execute([$t + $k * 40 + 20, auc_day($t), 'download', $host, $path, '', $u, pick($dls)]);
            }
            if ($host === 'example.com' && $path === '/pricing' && mt_rand(1, 100) <= 18) {
                $ins->execute([$t + $k * 40 + 25, auc_day($t), 'event', $host, $path, '', $u, 'start-trial']);
            }
        }
    }
}
$pdo->commit();
echo $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() . " events\n";
