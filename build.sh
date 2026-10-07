#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST="$ROOT/dist"

rm -rf "$DIST"
mkdir -p "$DIST"

( cd "$ROOT/com_lcomment" && zip -r -q "$DIST/com_lcomment.zip" . -x '.*' )
( cd "$ROOT/plg_content_lcomment" && zip -r -q "$DIST/plg_content_lcomment.zip" . -x '.*' )
( cd "$ROOT/plg_system_lcomment" && zip -r -q "$DIST/plg_system_lcomment.zip" . -x '.*' )

cp "$ROOT/packages/pkg_lcomment.xml" "$DIST/pkg_lcomment.xml"
cp "$ROOT/packages/script.php" "$DIST/script.php"
cp -r "$ROOT/packages/language" "$DIST/language"
( cd "$DIST" && zip -r -q pkg_lcomment.zip pkg_lcomment.xml script.php language com_lcomment.zip plg_content_lcomment.zip plg_system_lcomment.zip )

echo "Built $DIST/pkg_lcomment.zip"
