#!/usr/bin/env bash
set -euo pipefail
test_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
test_project="${FTW_DOWNLOAD_TEST_PROJECT:-ftw-download-test-issue30}"
case "$test_project" in ftw-download-test-*) ;; *) echo 'Test project must start with ftw-download-test-' >&2; exit 1 ;; esac
dc() { docker compose -p "$test_project" -f "$test_dir/compose.yaml" "$@"; }
cleanup() { dc down --volumes --remove-orphans; }
trap cleanup EXIT
dc up -d --wait
for attempt in {1..60}; do
  if dc exec -T wp test -f /var/www/html/wp-config.php; then break; fi
  sleep 0.5
done
dc exec -T wp php /ftw-tests/setup.php
python3 "$test_dir/check.py" "http://127.0.0.1:${FTW_DOWNLOAD_TEST_PORT:-18030}"
