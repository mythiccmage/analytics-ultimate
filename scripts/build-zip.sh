#!/usr/bin/env bash
# Builds the release zip into ./dist/analytics-ultimate-<version>.zip
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -o "define('AUC_VERSION', '[^']*'" lib/bootstrap.php | sed "s/.*, '//;s/'//")
OUT="dist/analytics-ultimate-$VERSION.zip"
STAGE="$(mktemp -d)"
mkdir -p dist "$STAGE/analytics-ultimate"
cp -r a.js collect.php go.php ignore.php index.php setup.php lib README.md LICENSE CHANGELOG.md "$STAGE/analytics-ultimate/"
rm -f "$OUT"
(cd "$STAGE" && zip -qr - analytics-ultimate) > "$OUT"
echo "$OUT ($(du -h "$OUT" | cut -f1))"
unzip -l "$OUT" | tail -n +4 | awk '{print "  " $4}' | head -30
