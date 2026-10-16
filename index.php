<?php
// Analytics Ultimate dashboard and settings.

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/report.php';

if (auc_data_dir() === null) {
    header('Location: setup.php');
    exit;
}
$cfg = auc_config();
auc_session();

/* ------------------------------------------------------------------ sign in */
if (isset($_GET['logout'])) {
    auc_logout();
    header('Location: index.php');
    exit;
}
if (!auc_is_admin()) {
    $err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
        $wait = auc_login_blocked();
        if ($wait > 0) {
            $err = 'Too many attempts. Try again in ' . ceil($wait / 60) . ' minute(s).';
        } elseif (auc_login((string) $_POST['password'])) {
            header('Location: index.php');
            exit;
        } else {
            $err = 'Wrong password.';
        }
    }
    auc_head('Sign in', true);
    echo '<h1>Analytics Ultimate</h1><form method="post" class="panel"><label for="pw">Password</label>'
        . '<input id="pw" type="password" name="password" autofocus autocomplete="current-password">'
        . ($err ? '<div class="msg bad" style="margin-top:12px">' . auc_h($err) . '</div>' : '')
        . '<p style="margin-top:16px"><button type="submit">Sign in</button></p></form>';
    auc_foot();
    exit;
}

/* ------------------------------------------------------------------ actions */
function auc_flash(string $msg, bool $ok = true): void
{
    $_SESSION['auc_flash'] = [$msg, $ok];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $tab = (string) ($_POST['tab'] ?? 'overview');
    if (!auc_csrf_ok()) {
        auc_flash('Your session expired. Please try again.', false);
    } else {
        $act = (string) ($_POST['action'] ?? '');
        $new = $cfg;
        if ($act === 'save_settings') {
            $sites = [];
            foreach (preg_split('/[\s,]+/', (string) ($_POST['sites'] ?? '')) as $s) {
                $s = strtolower(trim((string) $s));
                $s = (string) preg_replace('#^https?://#', '', $s);
                $s = rtrim(explode('/', $s)[0], '.');
                if ($s !== '' && preg_match('/^(\*\.)?[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d+)?$/', $s)) {
                    $sites[auc_norm_host($s)] = true;
                }
            }
            $tz = (string) ($_POST['timezone'] ?? 'UTC');
            if (!$sites) {
                auc_flash('Keep at least one website in the list.', false);
            } elseif (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                auc_flash('Pick a timezone from the list.', false);
            } else {
                $new['sites'] = array_keys($sites);
                $new['timezone'] = $tz;
                $new['retention_months'] = max(0, min(120, (int) ($_POST['retention'] ?? 0)));
                auc_save_config($new) ? auc_flash('Settings saved.') : auc_flash('Could not write settings. Check folder permissions.', false);
            }
        } elseif ($act === 'save_groups') {
            $new['groups'] = auc_groups_from_text((string) ($_POST['groups'] ?? ''));
            auc_save_config($new) ? auc_flash('Projects saved.') : auc_flash('Could not write settings.', false);
        } elseif ($act === 'add_download') {
            $key = strtolower(trim((string) ($_POST['key'] ?? '')));
            $target = trim((string) ($_POST['target'] ?? ''));
            $label = substr(trim((string) ($_POST['label'] ?? '')), 0, 80);
            if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,80}$/', $key)) {
                auc_flash('The short name may only use letters, numbers, dots, dashes and underscores.', false);
            } elseif (!preg_match('#^(https?://|/)#i', $target)) {
                auc_flash('The file address must start with https:// or /', false);
            } else {
                $new['downloads'][$key] = ['label' => $label, 'target' => $target];
                auc_save_config($new) ? auc_flash('Download link saved.') : auc_flash('Could not write settings.', false);
            }
        } elseif ($act === 'delete_download') {
            unset($new['downloads'][(string) ($_POST['key'] ?? '')]);
            auc_save_config($new) ? auc_flash('Download link removed.') : auc_flash('Could not write settings.', false);
        } elseif ($act === 'change_password') {
            $cur = (string) ($_POST['current'] ?? '');
            $pw = (string) ($_POST['password'] ?? '');
            if (!password_verify($cur, (string) $cfg['password_hash'])) {
                auc_flash('Your current password is not right.', false);
            } elseif (strlen($pw) < 10 || $pw !== (string) ($_POST['password2'] ?? '')) {
                auc_flash('The new password must match and be at least 10 characters.', false);
            } else {
                $new['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
                auc_save_config($new) ? auc_flash('Password changed.') : auc_flash('Could not write settings.', false);
            }
        } elseif ($act === 'prune') {
            $months = max(1, (int) ($_POST['months'] ?? 24));
            $st = auc_db()->prepare('DELETE FROM events WHERE day < ?');
            $st->execute([auc_day(strtotime('-' . $months . ' months'))]);
            auc_flash('Deleted ' . $st->rowCount() . ' records older than ' . $months . ' months.');
        } elseif ($act === 'delete_test_data') {
            $from = (string) ($_POST['from'] ?? '');
            $to = (string) ($_POST['to'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $from <= $to) {
                $st = auc_db()->prepare('DELETE FROM events WHERE day BETWEEN ? AND ?');
                $st->execute([$from, $to]);
                auc_flash('Deleted ' . $st->rowCount() . ' records from ' . $from . ' to ' . $to . '.');
            } else {
                auc_flash('Enter both dates as YYYY-MM-DD.', false);
            }
        }
    }
    header('Location: index.php?tab=' . rawurlencode($tab));
    exit;
}

/* ----------------------------------------------------------------- filters */
$tab = (string) ($_GET['tab'] ?? 'overview');
if (!in_array($tab, ['overview', 'projects', 'downloads', 'settings'], true)) {
    $tab = 'overview';
}
$rangeKey = isset(auc_ranges()[$_GET['range'] ?? '']) ? (string) $_GET['range'] : '30';
$days = auc_range_days($rangeKey);
list($from, $to) = auc_range($days);
$sitesSeen = auc_sites_seen();
$site = (string) ($_GET['site'] ?? '');
if ($site !== '' && !in_array($site, $sitesSeen, true)) {
    $site = '';
}
$f = ['from' => $from, 'to' => $to, 'site' => $site, 'group' => null];

if (isset($_GET['export'])) {
    $p = [];
    $w = auc_where($f, $p);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="analytics-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['time', 'day', 'type', 'site', 'path', 'referrer', 'label']);
    $st = auc_db()->prepare("SELECT ts, day, kind, host, path, ref, label FROM events WHERE $w ORDER BY ts");
    $st->execute($p);
    while ($r = $st->fetch()) {
        $safe = function ($v) {
            return preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v;
        };
        fputcsv($out, [gmdate('c', (int) $r['ts']), $r['day'], $r['kind'], $r['host'], $safe($r['path']), $safe($r['ref']), $safe($r['label'])]);
    }
    exit;
}

function auc_url(array $over = []): string
{
    global $tab, $rangeKey, $site;
    $q = array_merge(['tab' => $tab, 'range' => $rangeKey, 'site' => $site], $over);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    return 'index.php?' . http_build_query($q);
}

function auc_table(array $rows, array $cols, string $empty = 'Nothing yet.'): void
{
    if (!$rows) {
        echo '<p class="empty">' . auc_h($empty) . '</p>';
        return;
    }
    echo '<div class="scroll"><table><tr>';
    foreach ($cols as $c) {
        echo '<th' . (!empty($c[2]) ? ' class="num"' : '') . '>' . auc_h($c[0]) . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($cols as $c) {
            $v = $r[$c[1]] ?? '';
            echo '<td' . (!empty($c[2]) ? ' class="num"' : '') . '>' . (!empty($c[2]) ? number_format((int) $v) : auc_h($v)) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></div>';
}

function auc_tracking_panel(): void
{
    $snippet = '<script defer src="' . auc_base_url() . 'a.js"></script>';
    echo '<div class="panel"><h2>Tracking line</h2><p>Paste this just before <code>&lt;/head&gt;</code> on every page of every site you want counted, including subdomains and other domains.</p>'
        . '<pre>' . auc_h($snippet) . '</pre>'
        . '<p class="muted">Counted automatically: pageviews (including single-page apps), traffic sources, and file downloads. '
        . 'Optional: <code>data-ua-event="name"</code> on any button, or <code>AnalyticsUltimate.track("name")</code> in your own script.</p>'
        . '<p><a class="btn secondary" href="ignore.php">Exclude this browser from my stats</a></p>'
        . '<p class="muted">For a site on a different domain, open any of its pages with <code>?ua-ignore=1</code> added to the address.</p></div>';
}

function auc_projects_editor(array $cfg): void
{
    echo '<div class="panel"><h2>Edit projects</h2><p>Group pages and downloads into products or sections. One per line:</p>'
        . '<pre>Name | sites | page starts with | download names start with</pre>'
        . '<p class="muted">Leave a part empty to skip it. Example: <code>Shop | | /shop, /cart | shop-</code> or <code>Blog | blog.example.com | |</code></p>'
        . '<form method="post">' . auc_csrf_field() . '<input type="hidden" name="tab" value="projects"><input type="hidden" name="action" value="save_groups">'
        . '<textarea name="groups" rows="5">' . auc_h(auc_groups_to_text($cfg['groups'] ?? [])) . '</textarea>'
        . '<p style="margin-top:14px"><button type="submit">Save projects</button></p></form></div>';
}

/* -------------------------------------------------------------------- page */
auc_head('Dashboard');
echo '<header class="top"><div class="brand">Analytics Ultimate<small>' . auc_h(implode(', ', array_slice($cfg['sites'], 0, 3))) . '</small></div><nav class="tabs">';
foreach (['overview' => 'Overview', 'projects' => 'Projects', 'downloads' => 'Downloads', 'settings' => 'Settings'] as $k => $name) {
    echo '<a class="' . ($tab === $k ? 'on' : '') . '" href="' . auc_h(auc_url(['tab' => $k])) . '">' . auc_h($name) . '</a>';
}
echo '<a href="index.php?logout=1">Sign out</a></nav></header>';

if (!empty($_SESSION['auc_flash'])) {
    list($m, $ok) = $_SESSION['auc_flash'];
    unset($_SESSION['auc_flash']);
    echo '<div class="msg ' . ($ok ? 'ok' : 'bad') . '">' . auc_h($m) . '</div>';
}

if (in_array($tab, ['overview', 'projects', 'downloads'], true)) {
    echo '<div class="bar">';
    foreach (auc_ranges() as $k => $n) {
        echo '<a class="pill ' . ($rangeKey === (string) $k ? 'on' : '') . '" href="' . auc_h(auc_url(['range' => (string) $k])) . '">' . ($n === 365 ? '1 year' : $n . ' days') . '</a>';
    }
    if (count($sitesSeen) > 1 && $tab !== 'projects') {
        echo '<form method="get"><input type="hidden" name="tab" value="' . auc_h($tab) . '"><input type="hidden" name="range" value="' . auc_h($rangeKey) . '">'
            . '<select name="site" onchange="this.form.submit()"><option value="">All sites</option>';
        foreach ($sitesSeen as $h) {
            echo '<option' . ($h === $site ? ' selected' : '') . '>' . auc_h($h) . '</option>';
        }
        echo '</select></form>';
    }
    echo '<span class="muted">' . auc_h($from) . ' to ' . auc_h($to) . ' (' . auc_h($cfg['timezone']) . ')</span>';
    echo '<a class="pill" href="' . auc_h(auc_url(['export' => '1'])) . '">Export CSV</a></div>';
}

if ($tab === 'overview') {
    $t = auc_totals($f);
    $newPct = $t['visitors'] ? round($t['new'] / $t['visitors'] * 100) : 0;
    echo '<div class="cards">'
        . '<div class="card"><div class="label">Visitors</div><div class="value">' . number_format($t['visitors']) . '</div><div class="sub">unique browsers</div></div>'
        . '<div class="card"><div class="label">Pageviews</div><div class="value">' . number_format($t['views']) . '</div></div>'
        . '<div class="card"><div class="label">Came back</div><div class="value">' . number_format($t['came_back']) . '</div><div class="sub">visited on 2+ days</div></div>'
        . '<div class="card"><div class="label">New visitors</div><div class="value">' . number_format($t['new']) . '</div><div class="sub">' . $newPct . '% of visitors</div></div>'
        . '<div class="card"><div class="label">Downloads</div><div class="value">' . number_format($t['downloads']) . '</div><div class="sub">' . number_format($t['downloaders']) . ' people</div></div>'
        . '</div>';

    $byDay = auc_by_day($f);
    $max = 1;
    foreach ($byDay as $r) {
        $max = max($max, $r[0]);
    }
    echo '<div class="panel"><h2>Pageviews by day</h2><div class="chart">';
    foreach ($byDay as $day => $r) {
        echo '<div class="b" style="height:' . max(2, (int) round($r[0] / $max * 120)) . 'px" title="' . auc_h($day . ': ' . $r[0] . ' views, ' . $r[1] . ' visitors, ' . $r[2] . ' downloads') . '"></div>';
    }
    echo '</div><div class="labels">';
    $every = max(1, (int) ceil(count($byDay) / 10));
    $i = 0;
    foreach ($byDay as $day => $r) {
        echo '<span>' . ($i++ % $every === 0 ? auc_h(substr($day, 5)) : '') . '</span>';
    }
    echo '</div></div>';

    if (!$site && count($sitesSeen) > 1) {
        echo '<div class="panel"><h2>Sites</h2>';
        auc_table(auc_by_site($f), [['Site', 'host'], ['Visitors', 'visitors', 1], ['Pageviews', 'views', 1], ['Downloads', 'downloads', 1]]);
        echo '</div>';
    }
    echo '<div class="panel"><h2>Top pages</h2>';
    auc_table(auc_top($f, 'pageview', 'path'), [['Page', 'name'], ['Views', 'n', 1], ['Visitors', 'u', 1]], 'No pageviews yet in this range.');
    echo '</div><div class="panel"><h2>Where visitors come from</h2>';
    auc_table(auc_top($f, 'pageview', 'ref'), [['Source', 'name'], ['Views', 'n', 1], ['Visitors', 'u', 1]]);
    echo '</div><div class="panel"><h2>Downloads</h2>';
    auc_table(auc_top($f, 'download', 'label'), [['File', 'name'], ['Clicks', 'n', 1], ['People', 'u', 1]], 'No downloads recorded yet.');
    echo '</div>';
    $ev = auc_top($f, 'event', 'label');
    if ($ev) {
        echo '<div class="panel"><h2>Events</h2>';
        auc_table($ev, [['Event', 'name'], ['Count', 'n', 1], ['People', 'u', 1]]);
        echo '</div>';
    }
    auc_tracking_panel();
    if ($t['views'] === 0 && !$sitesSeen) {
        echo '<div class="msg">No data yet. Add the tracking line below to your website, then visit it.</div>';
    }
}

if ($tab === 'projects') {
    $groups = $cfg['groups'] ?? [];
    if (!$groups) {
        echo '<div class="panel"><h2>Projects</h2><p>Projects let you group pages and downloads into products or sections and compare them side by side, '
            . 'for example "Shop" = everything under <code>/shop</code>. Add your first one below.</p></div>';
    } else {
        $rows = [];
        foreach ($groups as $g) {
            $fg = $f;
            $fg['group'] = $g;
            $t = auc_totals($fg);
            $all = auc_totals(['all' => true, 'from' => '0000-00-00', 'to' => '9999-99-99', 'site' => '', 'group' => $g]);
            $rows[] = ['name' => $g['name'], 'visitors' => $t['visitors'], 'came_back' => $t['came_back'], 'views' => $t['views'], 'downloads' => $t['downloads'],
                'all_visitors' => $all['visitors'], 'all_downloads' => $all['downloads']];
        }
        echo '<div class="panel"><h2>Last ' . $days . ' days and all time</h2>';
        auc_table($rows, [['Project', 'name'], ['Visitors', 'visitors', 1], ['Came back', 'came_back', 1], ['Pageviews', 'views', 1],
            ['Downloads', 'downloads', 1], ['All-time visitors', 'all_visitors', 1], ['All-time downloads', 'all_downloads', 1]]);
        echo '<p class="muted" style="margin-top:12px">Visitors are browsers, not people. "Came back" = seen on two or more different days in this range.</p></div>';

        $monthly = [];
        $months = [];
        foreach ($groups as $i => $g) {
            $monthly[$i] = auc_monthly($g);
            foreach (array_keys($monthly[$i]) as $m) {
                $months[$m] = true;
            }
        }
        krsort($months);
        echo '<div class="panel"><h2>By month: visitors / downloads</h2><div class="scroll"><table><tr><th>Month</th>';
        foreach ($groups as $g) {
            echo '<th class="num">' . auc_h($g['name']) . '</th>';
        }
        echo '</tr>';
        foreach (array_keys($months) as $m) {
            echo '<tr><td>' . auc_h(date('M Y', strtotime($m . '-01'))) . '</td>';
            foreach ($groups as $i => $g) {
                $v = $monthly[$i][$m] ?? [0, 0];
                echo '<td class="num">' . number_format($v[0]) . ' / ' . number_format($v[1]) . '</td>';
            }
            echo '</tr>';
        }
        echo '</table></div></div>';
    }

    auc_projects_editor($cfg);
}

if ($tab === 'downloads') {
    $base = auc_base_url();
    $counts = [];
    foreach (auc_top($f, 'download', 'label', 500) as $r) {
        $counts[$r['name']] = $r;
    }
    echo '<div class="panel"><h2>All downloads in this range</h2>';
    auc_table(auc_top($f, 'download', 'label', 100), [['File or link', 'name'], ['Clicks', 'n', 1], ['People', 'u', 1]], 'No downloads recorded yet.');
    echo '<p class="muted" style="margin-top:12px">Links to common file types (zip, exe, dmg, pdf and more) on your pages are counted automatically when clicked. '
        . 'Tracked links (below) are the most reliable.</p></div>';

    echo '<div class="panel"><h2>Tracked download links</h2><p>Use these links on your website instead of linking to the file directly. '
        . 'The click is counted on the server, so it also catches "save link as", download managers and shared links, then visitors are sent straight to the file. '
        . 'They are also how you can move a file to another host (such as GitHub) without changing any page.</p>';
    if ($cfg['downloads']) {
        echo '<div class="scroll"><table><tr><th>Link to use on your site</th><th>Goes to</th><th class="num">Clicks</th><th></th></tr>';
        foreach ($cfg['downloads'] as $key => $d) {
            $n = $counts[$key]['n'] ?? 0;
            echo '<tr><td><code>' . auc_h($base . 'go.php?l=' . $key) . '</code>' . ($d['label'] ? '<div class="muted">' . auc_h($d['label']) . '</div>' : '') . '</td>'
                . '<td class="muted" style="word-break:break-all">' . auc_h($d['target']) . '</td><td class="num">' . number_format((int) $n) . '</td>'
                . '<td><form method="post" onsubmit="return confirm(\'Remove this link? Pages using it will stop working.\')">' . auc_csrf_field()
                . '<input type="hidden" name="tab" value="downloads"><input type="hidden" name="action" value="delete_download"><input type="hidden" name="key" value="' . auc_h($key) . '">'
                . '<button class="secondary" type="submit">Remove</button></form></td></tr>';
        }
        echo '</table></div>';
    } else {
        echo '<p class="empty">No tracked links yet.</p>';
    }
    echo '<h3>Add a tracked link</h3><form method="post">' . auc_csrf_field() . '<input type="hidden" name="tab" value="downloads"><input type="hidden" name="action" value="add_download">'
        . '<div class="row"><div style="flex:1;min-width:160px"><label>Short name</label><input type="text" name="key" placeholder="my-app-windows" required></div>'
        . '<div style="flex:2;min-width:240px"><label>File address</label><input type="text" name="target" placeholder="https://example.com/files/my-app-setup.exe" required></div></div>'
        . '<label>Description (optional)</label><input type="text" name="label" placeholder="My App for Windows">'
        . '<p style="margin-top:14px"><button type="submit">Add link</button></p></form></div>';
}

if ($tab === 'settings') {
    auc_tracking_panel();

    echo '<div class="panel"><h2>Sites and preferences</h2><form method="post">' . auc_csrf_field() . '<input type="hidden" name="tab" value="settings"><input type="hidden" name="action" value="save_settings">'
        . '<label>Websites to track (one per line)</label><textarea name="sites" rows="4">' . auc_h(implode("\n", $cfg['sites'])) . '</textarea>'
        . '<div class="muted">Only these sites can send data. <code>*.example.com</code> covers all subdomains.</div>'
        . '<label>Timezone</label><select name="timezone">';
    foreach (DateTimeZone::listIdentifiers() as $tz) {
        echo '<option' . ($tz === $cfg['timezone'] ? ' selected' : '') . '>' . auc_h($tz) . '</option>';
    }
    echo '</select><div class="muted">Applies to new data. Records already stored keep the day they were saved on.</div>'
        . '<label>Delete data older than (months, 0 = keep forever)</label><input type="number" name="retention" min="0" max="120" value="' . (int) $cfg['retention_months'] . '">'
        . '<p style="margin-top:14px"><button type="submit">Save</button></p></form></div>';

    echo '<div class="panel"><h2>Password</h2><form method="post">' . auc_csrf_field() . '<input type="hidden" name="tab" value="settings"><input type="hidden" name="action" value="change_password">'
        . '<label>Current password</label><input type="password" name="current" autocomplete="current-password">'
        . '<label>New password</label><input type="password" name="password" autocomplete="new-password">'
        . '<label>Repeat new password</label><input type="password" name="password2" autocomplete="new-password">'
        . '<p style="margin-top:14px"><button type="submit">Change password</button></p></form></div>';

    echo '<div class="panel"><h2>Clean up data</h2>'
        . '<form method="post" class="row" onsubmit="return confirm(\'Delete these records permanently?\')">' . auc_csrf_field() . '<input type="hidden" name="tab" value="settings"><input type="hidden" name="action" value="delete_test_data">'
        . '<div><label>Delete records from</label><input type="text" name="from" placeholder="2026-01-01"></div><div><label>to</label><input type="text" name="to" placeholder="2026-01-07"></div>'
        . '<div style="align-self:flex-end"><button class="danger" type="submit">Delete</button></div></form>'
        . '<p class="muted" style="margin-top:8px">Handy for removing test traffic from before you excluded your own browsers.</p>'
        . '<form method="post" class="row" onsubmit="return confirm(\'Delete old records permanently?\')">' . auc_csrf_field() . '<input type="hidden" name="tab" value="settings"><input type="hidden" name="action" value="prune">'
        . '<div><label>Delete everything older than (months)</label><input type="number" name="months" min="1" value="24"></div>'
        . '<div style="align-self:flex-end"><button class="danger" type="submit">Delete old data</button></div></form></div>';
}
auc_foot();
