#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST="$ROOT/dist"

rm -rf "$DIST"
mkdir -p "$DIST"

mkdir -p "$DIST/constituents"

( cd "$ROOT/com_lcomment" && zip -r -q "$DIST/com_lcomment.zip" . -x '.*' )
( cd "$ROOT/plg_content_lcomment" && zip -r -q "$DIST/plg_content_lcomment.zip" . -x '.*' )
( cd "$ROOT/plg_system_lcomment" && zip -r -q "$DIST/plg_system_lcomment.zip" . -x '.*' )
( cd "$ROOT/plg_task_lcomment" && zip -r -q "$DIST/plg_task_lcomment.zip" . -x '.*' )

# Also kept at dist/ root (not just dist/constituents/) so each extension
# can still be installed individually, same as the package's own copies.
cp "$DIST/com_lcomment.zip" "$DIST/constituents/com_lcomment.zip"
cp "$DIST/plg_content_lcomment.zip" "$DIST/constituents/plg_content_lcomment.zip"
cp "$DIST/plg_system_lcomment.zip" "$DIST/constituents/plg_system_lcomment.zip"
cp "$DIST/plg_task_lcomment.zip" "$DIST/constituents/plg_task_lcomment.zip"

cp "$ROOT/packages/pkg_lcomment.xml" "$DIST/pkg_lcomment.xml"
cp "$ROOT/packages/script.php" "$DIST/script.php"
cp -r "$ROOT/packages/language" "$DIST/language"
( cd "$DIST" && zip -r -q pkg_lcomment.zip pkg_lcomment.xml script.php language constituents )

echo "Built $DIST/pkg_lcomment.zip"
