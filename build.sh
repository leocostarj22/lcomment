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
( cd "$DIST" && zip -q pkg_lcomment.zip pkg_lcomment.xml com_lcomment.zip plg_content_lcomment.zip plg_system_lcomment.zip )

echo "Built $DIST/pkg_lcomment.zip"
