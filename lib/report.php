<?php
// Reporting queries. Everything is aggregated in SQL so it stays fast on shared hosting.

function auc_all(string $sql, array $params = []): array
{
    $st = auc_db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function auc_one(string $sql, array $params = []): array
{
    $rows = auc_all($sql, $params);
    return $rows ? $rows[0] : [];
}

function auc_like(string $prefix): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
}

/** Range keys offered in the UI -> number of days. */
function auc_ranges(): array
{
    return ['7' => 7, '30' => 30, '90' => 90, '365' => 365];
}

function auc_range_days(string $key): int
{
    $r = auc_ranges();
    return $r[$key] ?? 30;
}

/** [fromDay, toDay] in the configured timezone, covering $days whole days ending today. */
function auc_range(int $days): array
{
    $now = new DateTime('now', auc_tz());
    $to = $now->format('Y-m-d');
    $from = (clone $now)->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    return [$from, $to];
}

/**
 * SQL condition for a project group. A group matches a pageview/event by host and/or path prefix,
 * and a download by label prefix (tracked links) or by the page it was clicked on.
 */
function auc_group_cond(array $g, array &$p): string
{
    $page = [];
    if (!empty($g['hosts'])) {
        $or = [];
        foreach ($g['hosts'] as $h) {
            $or[] = 'host = ?';
            $p[] = auc_norm_host((string) $h);
        }
        $page[] = '(' . implode(' OR ', $or) . ')';
    }
    if (!empty($g['paths'])) {
        $or = [];
        foreach ($g['paths'] as $path) {
            $or[] = "path LIKE ? ESCAPE '\\'";
            $p[] = auc_like((string) $path);
        }
        $page[] = '(' . implode(' OR ', $or) . ')';
    }
    $parts = [];
    if ($page) {
        $parts[] = '(' . implode(' AND ', $page) . ')';
    }
    if (!empty($g['downloads'])) {
        $or = [];
        foreach ($g['downloads'] as $pre) {
            $or[] = "label LIKE ? ESCAPE '\\'";
            $p[] = auc_like((string) $pre);
        }
        $parts[] = "(kind = 'download' AND (" . implode(' OR ', $or) . '))';
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '0';
}

/**
 * Builds " day BETWEEN ? AND ? [AND host = ?] [AND group]" for the filter $f:
 * ['from'=>, 'to'=>, 'site'=>'' , 'group'=>null|array]. Set 'all' => true to skip the date range.
 */
function auc_where(array $f, array &$p): string
{
    $c = ['1'];
    if (empty($f['all'])) {
        $c[] = 'day BETWEEN ? AND ?';
        $p[] = $f['from'];
        $p[] = $f['to'];
    }
    if (!empty($f['site'])) {
        $c[] = 'host = ?';
        $p[] = $f['site'];
    }
    if (!empty($f['group'])) {
        $c[] = auc_group_cond($f['group'], $p);
    }
    return implode(' AND ', $c);
}

function auc_totals(array $f): array
{
    $p = [];
    $w = auc_where($f, $p);
    $r = auc_one("SELECT SUM(kind = 'pageview') AS views,
        COUNT(DISTINCT CASE WHEN kind = 'pageview' THEN uid END) AS visitors,
        SUM(kind = 'download') AS downloads,
        COUNT(DISTINCT CASE WHEN kind = 'download' THEN uid END) AS downloaders,
        SUM(kind = 'event') AS events FROM events WHERE $w", $p);

    $p = [];
    $w = auc_where($f, $p);
    $back = auc_one("SELECT COUNT(*) AS c FROM (SELECT uid FROM events WHERE kind = 'pageview' AND $w GROUP BY uid HAVING COUNT(DISTINCT day) >= 2)", $p);

    // New = first ever pageview (within the same site/group filter) falls inside the range.
    $p = [];
    $w = auc_where(['all' => true, 'site' => $f['site'] ?? '', 'group' => $f['group'] ?? null], $p);
    $p[] = $f['from'];
    $p[] = $f['to'];
    $new = auc_one("SELECT COUNT(*) AS c FROM (SELECT uid, MIN(day) AS d FROM events WHERE kind = 'pageview' AND $w GROUP BY uid HAVING d BETWEEN ? AND ?)", $p);

    return [
        'views' => (int) $r['views'],
        'visitors' => (int) $r['visitors'],
        'downloads' => (int) $r['downloads'],
        'downloaders' => (int) $r['downloaders'],
        'events' => (int) $r['events'],
        'came_back' => (int) $back['c'],
        'new' => (int) $new['c'],
    ];
}

/** Every day in the range (zero-filled): day => [views, visitors, downloads]. */
function auc_by_day(array $f): array
{
    $out = [];
    $d = new DateTime($f['from'], auc_tz());
    $end = new DateTime($f['to'], auc_tz());
    while ($d <= $end) {
        $out[$d->format('Y-m-d')] = [0, 0, 0];
        $d->modify('+1 day');
    }
    $p = [];
    $w = auc_where($f, $p);
    $rows = auc_all("SELECT day, SUM(kind = 'pageview') AS v,
        COUNT(DISTINCT CASE WHEN kind = 'pageview' THEN uid END) AS u, SUM(kind = 'download') AS d
        FROM events WHERE $w GROUP BY day", $p);
    foreach ($rows as $r) {
        if (isset($out[$r['day']])) {
            $out[$r['day']] = [(int) $r['v'], (int) $r['u'], (int) $r['d']];
        }
    }
    return $out;
}

/** Top values of a column for one kind of event, e.g. pages, referrers, download labels. */
function auc_top(array $f, string $kind, string $col, int $limit = 15, bool $skipEmpty = false): array
{
    if (!in_array($col, ['path', 'ref', 'label', 'host'], true)) {
        return [];
    }
    $p = [$kind];
    $w = auc_where($f, $p);
    $empty = $skipEmpty ? " AND $col <> ''" : '';
    $label = $col === 'ref' ? "CASE WHEN ref = '' THEN 'Direct / unknown' ELSE ref END" : $col;
    return auc_all("SELECT $label AS name, COUNT(*) AS n, COUNT(DISTINCT uid) AS u FROM events
        WHERE kind = ? AND $w$empty GROUP BY name ORDER BY n DESC LIMIT " . (int) $limit, $p);
}

function auc_sites_seen(): array
{
    $rows = auc_all('SELECT DISTINCT host FROM events ORDER BY host');
    return array_map(function ($r) { return $r['host']; }, $rows);
}

function auc_by_site(array $f): array
{
    $f['site'] = '';
    $p = [];
    $w = auc_where($f, $p);
    return auc_all("SELECT host, SUM(kind = 'pageview') AS views,
        COUNT(DISTINCT CASE WHEN kind = 'pageview' THEN uid END) AS visitors, SUM(kind = 'download') AS downloads
        FROM events WHERE $w GROUP BY host ORDER BY views DESC", $p);
}

/** Month-by-month visitors and downloads for one group (or everything when $g is null). */
function auc_monthly(?array $g, string $site = ''): array
{
    $p = [];
    $w = auc_where(['all' => true, 'site' => $site, 'group' => $g], $p);
    $rows = auc_all("SELECT substr(day, 1, 7) AS m,
        COUNT(DISTINCT CASE WHEN kind = 'pageview' THEN uid END) AS v, SUM(kind = 'download') AS d
        FROM events WHERE $w GROUP BY m ORDER BY m", $p);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['m']] = [(int) $r['v'], (int) $r['d']];
    }
    return $out;
}

/* ------------------------------------------------- group text <-> config */

/** "Name | host1, host2 | /path1, /path2 | download-label-prefix" per line. */
function auc_groups_to_text(array $groups): string
{
    $lines = [];
    foreach ($groups as $g) {
        $lines[] = $g['name'] . ' | ' . implode(', ', $g['hosts'] ?? []) . ' | ' . implode(', ', $g['paths'] ?? []) . ' | ' . implode(', ', $g['downloads'] ?? []);
    }
    return implode("\n", $lines);
}

function auc_groups_from_text(string $text): array
{
    $split = function ($s) {
        $out = [];
        foreach (preg_split('/\s*,\s*/', trim((string) $s)) as $x) {
            if ($x !== '') {
                $out[] = $x;
            }
        }
        return $out;
    };
    $groups = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        $name = $parts[0] ?? '';
        if ($name === '') {
            continue;
        }
        $groups[] = [
            'name' => substr($name, 0, 60),
            'hosts' => $split($parts[1] ?? ''),
            'paths' => $split($parts[2] ?? ''),
            'downloads' => $split($parts[3] ?? ''),
        ];
    }
    return $groups;
}
