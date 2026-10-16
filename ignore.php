<?php
// Visit once in a browser to stop counting that browser (your own). ?off=1 turns counting back on.
// This sets a cookie for this stats site. For tracked sites on OTHER domains, add ?ua-ignore=1 to any page URL
// of that site instead (a.js remembers it in that browser).

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/ui.php';

$off = isset($_GET['off']);
setcookie('auc_ignore', $off ? '' : '1', [
    'expires' => $off ? time() - 3600 : time() + 2 * 365 * 86400,
    'path' => '/',
    'secure' => auc_is_https(),
    'samesite' => 'Lax',
]);

auc_head('Exclude this browser', true);
if ($off) {
    echo '<h1>Counting is back on</h1><p>This browser is counted again.</p><p><a href="ignore.php">Exclude this browser</a></p>';
} else {
    echo '<h1>This browser is excluded</h1><p>Your visits and downloads from this browser will not show up in your statistics.</p>'
        . '<p class="muted">This applies to sites on this domain. For a site on a different domain, open any page of it with <code>?ua-ignore=1</code> added to the address.</p>'
        . '<p><a href="ignore.php?off=1">Turn counting back on</a></p>';
}
auc_foot();
