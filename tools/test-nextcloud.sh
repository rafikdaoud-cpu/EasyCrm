#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-php}"
"$PHP_BIN" -r 'foreach (["mbstring", "pdo_sqlite", "fileinfo"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing PHP extension: $ext\n"); exit(1); } }'
node -e 'require("jsdom")'
for edition in callcrm nextcloud; do
  while IFS= read -r -d '' file; do "$PHP_BIN" -l "$file" > /dev/null; done < <(find "$edition" -name '*.php' -print0)
  node --check "$edition/js/app.js"
  "$PHP_BIN" "$edition/tests/presence.php"
  node "$edition/tests/announcements-ui.cjs"
done
node tests/transfers-ui.cjs
node tests/admin-panels-ui.cjs
node tests/simulator-history-ui.cjs
"$PHP_BIN" callcrm/tests/simulator.php
"$PHP_BIN" tests/documents.php callcrm
"$PHP_BIN" tests/documents.php nextcloud
"$PHP_BIN" tests/migrations.php
git diff --check
