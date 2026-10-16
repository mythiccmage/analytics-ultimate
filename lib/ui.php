<?php
// Shared page chrome and styling for setup, login and the dashboard.

function auc_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}

function auc_css(): string
{
    return <<<'CSS'
:root { --ink:#20202a; --paper:#f5f4f8; --card:#fff; --line:#dedbe7; --muted:#766b5f; --accent:#7428e8; --accent-ink:#fff; --bad:#b3261e; --ok:#1b7a3d; --bar:#7428e8; }
@media (prefers-color-scheme: dark) { :root { --ink:#ecebf3; --paper:#14121c; --card:#1d1a28; --line:#34304a; --muted:#a39fb8; --accent:#9a64f2; --accent-ink:#14121c; --bar:#9a64f2; --bad:#ff8a80; --ok:#7bd88f; } }
* { box-sizing:border-box; }
body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif; background:var(--paper); color:var(--ink); line-height:1.5; }
a { color:var(--accent); }
.wrap { width:min(1100px, calc(100% - 40px)); margin:0 auto; padding:28px 0 70px; }
.narrow { width:min(640px, calc(100% - 40px)); }
header.top { display:flex; flex-wrap:wrap; gap:14px 22px; align-items:center; justify-content:space-between; margin-bottom:22px; }
.brand { font-size:20px; font-weight:800; letter-spacing:-.02em; }
.brand small { font-weight:500; color:var(--muted); font-size:12px; margin-left:8px; }
nav.tabs { display:flex; gap:6px; flex-wrap:wrap; }
nav.tabs a, .pill { padding:7px 14px; border-radius:999px; border:1px solid var(--line); background:var(--card); color:var(--ink); text-decoration:none; font-size:13px; font-weight:600; }
nav.tabs a.on, .pill.on { background:var(--ink); color:var(--paper); border-color:var(--ink); }
.bar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:20px; }
.bar form { display:flex; gap:8px; align-items:center; }
.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:20px; }
.card, .panel { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:18px; }
.panel { margin-bottom:18px; }
.card .label { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); }
.card .value { font-size:28px; font-weight:800; letter-spacing:-.02em; }
.card .sub { font-size:12px; color:var(--muted); }
h1 { font-size:24px; margin:0 0 6px; letter-spacing:-.02em; }
h2 { font-size:13px; margin:0 0 14px; text-transform:uppercase; letter-spacing:.07em; color:var(--muted); }
h3 { font-size:15px; margin:18px 0 6px; }
p { margin:0 0 12px; }
.muted { color:var(--muted); font-size:13px; }
table { width:100%; border-collapse:collapse; font-size:14px; }
th, td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); vertical-align:top; }
th { color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:600; }
td.num, th.num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
.scroll { overflow-x:auto; }
.chart { display:flex; align-items:flex-end; gap:3px; height:130px; }
.chart .b { flex:1; background:var(--bar); border-radius:3px 3px 0 0; min-height:2px; }
.labels { display:flex; gap:3px; margin-top:6px; }
.labels span { flex:1; font-size:10px; color:var(--muted); text-align:center; white-space:nowrap; overflow:hidden; }
label { display:block; font-weight:600; font-size:13px; margin:14px 0 4px; }
input[type=text], input[type=password], input[type=number], input[type=url], select, textarea { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:10px; background:var(--card); color:var(--ink); font:inherit; }
textarea { min-height:90px; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:13px; }
button, .btn { border:0; border-radius:999px; background:var(--accent); color:var(--accent-ink); padding:10px 20px; font-weight:700; font-size:13px; cursor:pointer; text-decoration:none; display:inline-block; }
button.secondary, .btn.secondary { background:transparent; color:var(--ink); border:1px solid var(--line); }
button.danger { background:var(--bad); color:#fff; }
.row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.msg { padding:12px 14px; border-radius:10px; margin-bottom:16px; font-size:14px; border:1px solid var(--line); background:var(--card); }
.msg.ok { border-color:var(--ok); } .msg.bad { border-color:var(--bad); color:var(--bad); }
code, pre { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:13px; }
pre { background:var(--paper); border:1px solid var(--line); border-radius:10px; padding:12px 14px; overflow-x:auto; margin:6px 0 12px; }
.check { display:flex; gap:10px; padding:6px 0; font-size:14px; border-bottom:1px solid var(--line); }
.check b.ok { color:var(--ok); } .check b.bad { color:var(--bad); }
.empty { color:var(--muted); font-size:14px; }
CSS;
}

function auc_head(string $title, bool $narrow = false): void
{
    auc_security_headers();
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . auc_h($title) . ' — Analytics Ultimate</title><style>' . auc_css() . '</style></head><body>'
        . '<div class="wrap' . ($narrow ? ' narrow' : '') . '">';
}

function auc_foot(): void
{
    echo '<p class="muted" style="margin-top:34px">Analytics Ultimate ' . auc_h(AUC_VERSION) . ' · free and open source · no cookies, no IP addresses stored</p></div></body></html>';
}
