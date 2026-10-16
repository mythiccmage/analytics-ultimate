<?php
// First-run setup. Open this page once after uploading the folder. It refuses to run again once installed.

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/ui.php';

if (auc_data_dir() !== null) {
    auc_head('Already set up', true);
    echo '<h1>Already set up</h1><p>Analytics Ultimate is installed. <a href="index.php">Open the dashboard</a>.</p>'
        . '<p class="muted">For safety you can delete <code>setup.php</code> from this folder now.</p>';
    auc_foot();
    exit;
}

$errors = [];
$checks = [];
$checks[] = [version_compare(PHP_VERSION, '7.4.0', '>='), 'PHP 7.4 or newer', 'You have PHP ' . PHP_VERSION . '.'];
$checks[] = [extension_loaded('pdo_sqlite'), 'SQLite support (pdo_sqlite)', 'Ask your host to enable the pdo_sqlite PHP extension (in cPanel: Select PHP Version → Extensions).'];
$checks[] = [function_exists('random_bytes') && function_exists('password_hash'), 'Secure random and password hashing', 'Needs a normal PHP 7.4+ install.'];

$target = null;
foreach (auc_data_candidates() as $cand) {
    if (auc_prepare_data_dir($cand)) {
        $target = $cand;
        break;
    }
}
$checks[] = [$target !== null, 'A writable place to keep your data', 'Make this folder writable (permissions 755), or create a "data" folder inside it.'];
$outside = $target !== null && !auc_starts($target, AUC_ROOT . '/');
$ready = true;
foreach ($checks as $c) {
    $ready = $ready && $c[0];
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$sitesDefault = auc_norm_host($host);
$values = ['sites' => $sitesDefault, 'timezone' => 'UTC'];

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $pw = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');
    $values['sites'] = (string) ($_POST['sites'] ?? '');
    $values['timezone'] = (string) ($_POST['timezone'] ?? 'UTC');
    $sites = [];
    foreach (preg_split('/[\s,]+/', $values['sites']) as $s) {
        $s = strtolower(trim((string) $s));
        $s = (string) preg_replace('#^https?://#', '', $s);
        $s = rtrim(explode('/', $s)[0], '.');
        if ($s !== '' && preg_match('/^(\*\.)?[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d+)?$/', $s)) {
            $sites[auc_norm_host($s)] = true;
        }
    }
    $sites = array_keys($sites);
    if (strlen($pw) < 10) {
        $errors[] = 'Choose a password of at least 10 characters.';
    }
    if ($pw !== $pw2) {
        $errors[] = 'The two passwords do not match.';
    }
    if (!$sites) {
        $errors[] = 'Enter at least one website, for example example.com';
    }
    if (!in_array($values['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $errors[] = 'Pick a timezone from the list.';
    }
    if (!$errors) {
        $cfg = auc_default_config();
        $cfg['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
        $cfg['timezone'] = $values['timezone'];
        $cfg['sites'] = $sites;
        $cfg['salt'] = auc_random(16);
        try {
            auc_db($target);
            if (!auc_save_config($cfg, $target)) {
                throw new RuntimeException('Could not write the configuration file.');
            }
        } catch (Throwable $e) {
            $errors[] = 'Setup could not finish: ' . $e->getMessage();
        }
        if (!$errors) {
            $snippet = '<script defer src="' . auc_base_url() . 'a.js"></script>';
            auc_head('Setup complete', true);
            echo '<h1>You\'re all set</h1><p>Analytics Ultimate is installed and tracking is ready.</p>'
                . '<h3>1. Add this line to your website</h3>'
                . '<p>Paste it just before <code>&lt;/head&gt;</code> on every page you want counted (or into your theme\'s header). Use the same line on all of your sites and subdomains.</p>'
                . '<pre>' . auc_h($snippet) . '</pre>'
                . '<h3>2. Sign in</h3><p><a class="btn" href="index.php">Open the dashboard</a></p>'
                . '<h3>3. Keep your own visits out</h3><p>Visit <a href="ignore.php">ignore.php</a> once in each of your own browsers.</p>'
                . '<p class="muted">Your data is stored ' . ($outside ? 'above your web root, where it cannot be downloaded' : 'in the data folder here, protected by .htaccess') . '. '
                . 'For safety, delete <code>setup.php</code> from this folder.</p>';
            auc_foot();
            exit;
        }
    }
}

auc_head('Set up', true);
echo '<h1>Set up Analytics Ultimate</h1><p class="muted">Simple, private website statistics. This takes about a minute.</p>';
echo '<div class="panel"><h2>Server check</h2>';
foreach ($checks as $c) {
    echo '<div class="check"><b class="' . ($c[0] ? 'ok' : 'bad') . '">' . ($c[0] ? '✓' : '✗') . '</b><div>' . auc_h($c[1])
        . ($c[0] ? '' : '<div class="muted">' . auc_h($c[2]) . '</div>') . '</div></div>';
}
echo '</div>';
foreach ($errors as $e) {
    echo '<div class="msg bad">' . auc_h($e) . '</div>';
}
if ($ready) {
    echo '<form method="post" class="panel" autocomplete="off">'
        . '<label for="sites">Websites to track</label>'
        . '<textarea id="sites" name="sites" rows="3" placeholder="example.com">' . auc_h($values['sites']) . '</textarea>'
        . '<div class="muted">One per line. Add every domain and subdomain you will put the tracking line on. <code>*.example.com</code> covers all subdomains.</div>'
        . '<label for="timezone">Timezone for your reports</label><select id="timezone" name="timezone">';
    foreach (DateTimeZone::listIdentifiers() as $tz) {
        echo '<option' . ($tz === $values['timezone'] ? ' selected' : '') . '>' . auc_h($tz) . '</option>';
    }
    echo '</select><div class="muted">Days start and end at midnight in this timezone.</div>'
        . '<label for="password">Admin password</label><input id="password" type="password" name="password" minlength="10" required autocomplete="new-password">'
        . '<label for="password2">Repeat password</label><input id="password2" type="password" name="password2" minlength="10" required autocomplete="new-password">'
        . '<p style="margin-top:20px"><button type="submit">Finish setup</button></p></form>'
        . '<script>try{var z=Intl.DateTimeFormat().resolvedOptions().timeZone,s=document.getElementById("timezone");'
        . 'if(z&&s.value==="UTC"&&!' . (isset($_POST['timezone']) ? 'true' : 'false') . '){for(var i=0;i<s.options.length;i++){if(s.options[i].value===z){s.selectedIndex=i}}}}catch(e){}</script>';
} else {
    echo '<div class="msg bad">Fix the items marked ✗, then reload this page.</div>';
}
auc_foot();
