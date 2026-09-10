#!/usr/bin/env bash
set -euo pipefail
test_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
test_project="${FTW_BULK_TEST_PROJECT:-ftw-bulk-test-pr29}"
case "$test_project" in ftw-bulk-test-*) ;; *) echo 'Test project must start with ftw-bulk-test-' >&2; exit 1 ;; esac
dc() { docker compose -p "$test_project" -f "$test_dir/compose.yaml" "$@"; }
run() { local replica="$1"; shift; dc exec -T "$replica" php /ftw-tests/worker.php "$@"; }
cleanup() { dc down --volumes --remove-orphans; }
trap cleanup EXIT
wait_signal() {
  for attempt in {1..60}; do
    if run wp-b signal "$1"; then return; fi
    sleep 0.5
  done
  echo "Worker did not reach $1" >&2
  exit 1
}
dc up -d --wait
for replica in wp-a wp-b; do
  for attempt in {1..60}; do
    if dc exec -T "$replica" test -f /var/www/html/wp-config.php; then break; fi
    sleep 0.5
  done
done
run wp-a install
run wp-b files

for phase in accepted synced; do
  run wp-b reset 4
  pause_id="$(run wp-b id 1)"
  dc exec -T -e "FTW_TEST_PAUSE=$phase" -e "FTW_TEST_PAUSE_ID=$pause_id" wp-a php /var/www/html/wp-cron.php &
  worker_pid=$!
  wait_signal "$phase"
  dc kill -s SIGKILL wp-a
  wait "$worker_pid" || true
  run wp-b assert 1
  run wp-b run
  if [ "$phase" = accepted ]; then run wp-b assert-complete lookup; else run wp-b assert-complete; fi
  echo "PASS: hard-killed worker at $phase; another replica completed the original queue"
  dc up -d --wait wp-a
done

run wp-b reset 3
pause_id="$(run wp-b id 0)"
dc exec -T -e FTW_TEST_PAUSE=accepted -e "FTW_TEST_PAUSE_ID=$pause_id" wp-a php /ftw-tests/worker.php run &
worker_pid=$!
wait_signal accepted
run wp-b run
run wp-b assert 0
run wp-b replace-busy
dc kill -s SIGKILL wp-a
wait "$worker_pid" || true
run wp-b run
run wp-b assert-complete lookup
echo 'PASS: competing dispatch retained its successor; active queue could not be replaced'
dc up -d --wait wp-a

run wp-b reset 3
dc exec -T wp-a php /ftw-tests/worker.php hold-item &
worker_pid=$!
wait_signal item
run wp-b run
run wp-b assert 0
dc kill -s SIGKILL wp-a
wait "$worker_pid" || true
run wp-b run
run wp-b assert-complete
echo 'PASS: busy document stayed in queue and was processed after lock release'

run wp-b reset 3
run wp-b recover
run wp-b run
run wp-b assert-complete
echo 'PASS: existing abandoned queue recovered through the registered periodic hook'

run wp-b reset 3
dc exec -T -e FTW_TEST_BUDGET=0.02 -e FTW_TEST_DELAY_MS=40 wp-b php /ftw-tests/worker.php run
run wp-b assert 1
run wp-b run
run wp-b assert-complete
echo 'PASS: slow document exhausted budget without starting additional items'
