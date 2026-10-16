#!/usr/bin/env bash
# End-to-end test: runs the package in a throwaway Docker PHP server laid out like cPanel
# (package inside public_html, data folder created above it) and exercises it over HTTP.
#   tests/e2e.sh 7.4     tests/e2e.sh 8.3
set -u   # no pipefail: grep -q exits early and would make matching pipelines look failed
V="${1:-8.3}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT=$((18000 + RANDOM % 1000))
NAME="auc-e2e-$V-$$"
BASE="http://127.0.0.1:$PORT/analytics-ultimate"
JAR="$(mktemp)"
UA="Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0"
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31m✗ %s\033[0m\n' "$1"; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }

cleanup() { docker stop "$NAME" >/dev/null 2>&1; rm -f "$JAR"; }
trap cleanup EXIT

docker run -d --rm --name "$NAME" -p "127.0.0.1:$PORT:8080" -v "$ROOT":/srv/home/public_html/analytics-ultimate:ro \
  "php:$V-cli" php -d display_errors=1 -d error_reporting=E_ALL -S 0.0.0.0:8080 -t /srv/home/public_html >/dev/null
for i in $(seq 1 40); do curl -s -o /dev/null "$BASE/setup.php" && break; sleep 0.25; done

dbq() { docker exec "$NAME" php /srv/home/public_html/analytics-ultimate/tests/dbq.php "$1"; }
post_collect() { # origin json [extra curl args]
  local origin="$1" body="$2"; shift 2
  curl -s -o /dev/null -w '%{http_code}' -A "$UA" -H "Origin: $origin" -H 'Content-Type: text/plain;charset=UTF-8' --data "$body" "$@" "$BASE/collect.php"
}

echo "PHP $V"
echo "Setup"
check "setup page renders and server checks pass" 'curl -s "$BASE/setup.php" | grep -q "Server check" && ! curl -s "$BASE/setup.php" | grep -q "class=\"bad\""'
check "short password rejected" 'curl -s -d "sites=example.com&timezone=UTC&password=short&password2=short" "$BASE/setup.php" | grep -q "at least 10"'
R=$(curl -s -d "sites=Example.com, https://shop.example.com/&timezone=America/New_York&password=correct-horse-battery&password2=correct-horse-battery" "$BASE/setup.php")
check "setup completes" 'echo "$R" | grep -q "all set"'
check "snippet shows the script URL" 'echo "$R" | grep -q "analytics-ultimate/a.js"'
check "data folder created OUTSIDE the web root" 'docker exec "$NAME" sh -c "ls -d /srv/home/analytics-ultimate-data-*/config.php" >/dev/null'
check "setup refuses to run twice" 'curl -s "$BASE/setup.php" | grep -q "Already set up"'
check "config file is not world readable" '[ "$(docker exec "$NAME" sh -c "stat -c %a /srv/home/analytics-ultimate-data-*/config.php")" = "600" ]'

echo "Collecting"
check "pageview from allowed site -> 204" '[ "$(post_collect https://example.com "{\"t\":\"pageview\",\"h\":\"example.com\",\"p\":\"/pricing?x=1#top\",\"u\":\"visitor0000000001\",\"r\":\"https://www.google.com/search\"}")" = 204 ]'
check "CORS header only for allowed origin" 'curl -s -D - -o /dev/null -A "$UA" -H "Origin: https://example.com" --data "{}" "$BASE/collect.php" | grep -qi "access-control-allow-origin: https://example.com" && ! curl -s -D - -o /dev/null -A "$UA" -H "Origin: https://evil.test" --data "{}" "$BASE/collect.php" | grep -qi "access-control-allow-origin"'
post_collect https://shop.example.com '{"t":"pageview","h":"shop.example.com","p":"/shop/widget","u":"visitor0000000002","s":"Newsletter"}' >/dev/null
post_collect https://example.com '{"t":"download","h":"example.com","p":"/downloads","u":"visitor0000000001","l":"app-setup.exe"}' >/dev/null
post_collect https://example.com '{"t":"event","h":"example.com","p":"/","u":"visitor0000000001","l":"signup"}' >/dev/null
post_collect https://evil.test '{"t":"pageview","h":"evil.test","p":"/x","u":"visitor0000000003"}' >/dev/null
post_collect https://example.com '{"t":"pageview","h":"other.test","p":"/x","u":"visitor0000000003"}' >/dev/null
post_collect https://example.com '{"t":"pageview","h":"example.com","p":"/botted","u":"visitor0000000004"}' -A "Googlebot/2.1" >/dev/null
post_collect https://example.com '{"t":"pageview","h":"example.com","p":"/headless","u":"visitor0000000004"}' -A "Mozilla/5.0 HeadlessChrome/120" >/dev/null
post_collect https://example.com '{"t":"pageview","h":"example.com","p":"/cookie","u":"visitor0000000005"}' -b "auc_ignore=1" >/dev/null
post_collect https://example.com '{"t":"pageview","h":"example.com","p":"/header","u":"visitor0000000005"}' -H "X-Analytics-Ignore: 1" >/dev/null
post_collect https://example.com '{"t":"pageview","h":"example.com","p":"/pricing","u":"visitor0000000001"}' >/dev/null
check "oversized body rejected" '[ "$(post_collect https://example.com "$(head -c 5000 /dev/zero | tr "\0" a)")" = 413 ]'
check "garbage body rejected" '[ "$(post_collect https://example.com "not json")" = 400 ]'
check "GET not allowed" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/collect.php")" = 405 ]'
check "allowed pageviews stored (3: pricing, widget, + none duplicated)" '[ "$(dbq "select count(*) from events where kind=\"pageview\"")" = 2 ]'
check "query string and fragment stripped from path" '[ "$(dbq "select path from events where path like \"/pricing%\"")" = "/pricing" ]'
check "unlisted host, bots, headless, cookie, header all ignored" '[ "$(dbq "select count(*) from events where path in (\"/x\",\"/botted\",\"/headless\",\"/cookie\",\"/header\")")" = 0 ]'
check "utm_source/ref label stored, own-site referrer dropped" '[ "$(dbq "select ref from events where path=\"/shop/widget\"")" = "newsletter" ] && [ "$(dbq "select ref from events where path=\"/pricing\"")" = "google.com" ]'
check "download and event stored" '[ "$(dbq "select count(*) from events where kind in (\"download\",\"event\")")" = 2 ]'
check "days stored in the configured timezone" '[ "$(dbq "select day from events limit 1")" = "$(TZ=America/New_York date +%F)" ]'

echo "Sign in"
check "dashboard asks for a password" 'curl -s -c "$JAR" -b "$JAR" "$BASE/index.php" | grep -q "name=\"password\""'
check "wrong password rejected" 'curl -s -c "$JAR" -b "$JAR" -d "password=nope" "$BASE/index.php" | grep -q "Wrong password"'
for i in 1 2 3 4 5 6 7 8; do curl -s -o /dev/null -d "password=nope$i" "$BASE/index.php"; done
check "sign-in is throttled after repeated failures" 'curl -s -d "password=correct-horse-battery" "$BASE/index.php" | grep -q "Too many attempts"'
dbq 'delete from login_attempts' >/dev/null
curl -s -c "$JAR" -b "$JAR" -o /dev/null -d "password=correct-horse-battery" "$BASE/index.php"
PAGE=$(curl -s -c "$JAR" -b "$JAR" "$BASE/index.php")
check "correct password signs in" 'echo "$PAGE" | grep -q "Pageviews by day"'
check "overview shows the counts" 'echo "$PAGE" | grep -q ">2<" && echo "$PAGE" | grep -q "/shop/widget" && echo "$PAGE" | grep -q "google.com" && echo "$PAGE" | grep -q "app-setup.exe"'
check "no PHP warnings on the overview" '! echo "$PAGE" | grep -qiE "warning|notice|fatal|deprecated"'
CSRF=$(curl -s -b "$JAR" "$BASE/index.php?tab=settings" | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')

echo "Admin actions"
check "POST without CSRF token is refused" 'curl -s -c "$JAR" -b "$JAR" -L -d "tab=settings&action=save_groups&groups=Hack||/|" "$BASE/index.php" | grep -q "session expired"'
curl -s -c "$JAR" -b "$JAR" -o /dev/null -d "csrf=$CSRF&tab=projects&action=save_groups" --data-urlencode "groups=Shop | | /shop | shop-
Main site | example.com | / |" "$BASE/index.php"
check "projects tab shows groups" 'curl -s -c "$JAR" -b "$JAR" "$BASE/index.php?tab=projects" | grep -q "Shop" && ! curl -s -b "$JAR" "$BASE/index.php?tab=projects" | grep -qiE "warning|fatal|notice"'
curl -s -c "$JAR" -b "$JAR" -o /dev/null -d "csrf=$CSRF&tab=downloads&action=add_download&key=my-app&label=My%20App&target=https%3A%2F%2Fexample.com%2Ffiles%2Fmy%20app.exe" "$BASE/index.php"
check "projects: numbers first, editor underneath" 'P=$(curl -s -b "$JAR" "$BASE/index.php?tab=projects"); a=$(echo "$P" | grep -b -o "By month" | head -1 | cut -d: -f1); b=$(echo "$P" | grep -b -o "Edit projects" | head -1 | cut -d: -f1); [ -n "$a" ] && [ -n "$b" ] && [ "$a" -lt "$b" ]'
check "downloads: stats first, tracked-link manager underneath" 'P=$(curl -s -b "$JAR" "$BASE/index.php?tab=downloads"); a=$(echo "$P" | grep -b -o "All downloads in this range" | head -1 | cut -d: -f1); b=$(echo "$P" | grep -b -o "Add a tracked link" | head -1 | cut -d: -f1); [ -n "$a" ] && [ -n "$b" ] && [ "$a" -lt "$b" ]'
check "overview: stats first, tracking line underneath" 'P=$(curl -s -b "$JAR" "$BASE/index.php?tab=overview"); a=$(echo "$P" | grep -b -o "Pageviews by day" | head -1 | cut -d: -f1); b=$(echo "$P" | grep -b -o "Tracking line" | head -1 | cut -d: -f1); [ -n "$a" ] && [ -n "$b" ] && [ "$a" -lt "$b" ]'
check "settings has no projects editor" '! curl -s -b "$JAR" "$BASE/index.php?tab=settings" | grep -q "Edit projects"'
check "tracked download link saved" 'curl -s -b "$JAR" "$BASE/index.php?tab=downloads" | grep -q "go.php?l=my-app"'
check "open-redirect style target refused" 'curl -s -c "$JAR" -b "$JAR" -L -d "csrf=$CSRF&tab=downloads&action=add_download&key=bad&target=javascript:alert(1)" "$BASE/index.php" | grep -q "must start with"'
check "go.php redirects to the file" '[ "$(curl -s -o /dev/null -w "%{redirect_url}" -A "$UA" -e https://example.com/downloads "$BASE/go.php?l=my-app")" = "https://example.com/files/my app.exe" ]'
check "go.php refuses unknown keys" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/go.php?l=nope")" = 404 ]'
check "go.php click was counted on the server" '[ "$(dbq "select count(*) from events where kind=\"download\" and label=\"my-app\" and host=\"example.com\"")" = 1 ]'
curl -s -o /dev/null -A "$UA" -H "X-Analytics-Ignore: 1" "$BASE/go.php?l=my-app"
curl -s -o /dev/null -A "Googlebot" "$BASE/go.php?l=my-app"
check "go.php skips ignored and bot clicks" '[ "$(dbq "select count(*) from events where label=\"my-app\"")" = 1 ]'
check "CSV export works and escapes spreadsheet formulas" 'curl -s -b "$JAR" "$BASE/index.php?export=1" | head -1 | grep -q "time,day,type"'
check "settings page renders" 'curl -s -b "$JAR" "$BASE/index.php?tab=settings" | grep -q "Tracking line" && ! curl -s -b "$JAR" "$BASE/index.php?tab=settings" | grep -qiE "warning|fatal|notice"'
curl -s -c "$JAR" -b "$JAR" -o /dev/null -d "csrf=$CSRF&tab=settings&action=delete_test_data&from=2000-01-01&to=2100-01-01" "$BASE/index.php"
check "delete-by-date works" '[ "$(dbq "select count(*) from events")" = 0 ]'
check "sign out works" 'curl -s -c "$JAR" -b "$JAR" -L "$BASE/index.php?logout=1" | grep -q "name=\"password\""'

echo "Script"
check "a.js is served as JavaScript" 'curl -sI "$BASE/a.js" | grep -qi "javascript"'
check "container log has no PHP errors" '! docker logs "$NAME" 2>&1 | grep -qiE "PHP (Warning|Notice|Fatal|Deprecated)"'

echo
echo "PHP $V: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
