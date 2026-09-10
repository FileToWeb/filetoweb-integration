# 0.1.56-rc.1 validation — 2026-09-10

- Original PR baseline: **263 tests, 1,241 assertions passed**.
- Candidate unit suite: **274 tests, 1,274 assertions passed**, PHP 8.5.5 / PHPUnit 11.5.55.
- PHP 7.4 syntax checks: plugin entry point, all `includes/*.php`, and integration PHP files passed.
- Shell syntax and `git diff --check`: passed.
- Isolated integration environment: **two WordPress 6.9.4 / PHP 8.2 replicas**, shared **MariaDB 10.11**, separate uploads filesystems, no exposed host ports.
- **All six integration scenarios passed**: kill after API acceptance; kill after sync before checkpoint; competing dispatch plus rejected queue replacement; busy document retention; abandoned-queue recovery through the periodic hook; elapsed-time budget.
- Negative control: the same harness using the unmodified **0.1.55** plugin failed after a real worker SIGKILL, with the first completed item absent from the persisted progress count. The candidate passes this assertion and continues on the other replica.

These tests use a local API fixture, not a live conversion service. They verify stable external ID, fingerprint, recovered document ID and absence of unexpected/reprocess requests. They do not independently test production billing, GCS, ProudCity themes/cache or fleet-scale performance. No customer documents, credits or production infrastructure were changed.

## Suggested runtime acceptance

Review and install the candidate using ProudCity's normal version-pinning/testing process. Do not click **Queue all** to recover an existing batch: preserve its state. An unscheduled nonempty queue with an old checkpoint should be re-armed by the existing periodic worker after the five-minute stale threshold plus cron delay. Verify successive saved counts and the presence of the next event. In a controlled test, interrupt a worker and verify continuation on another replica, then confirm the hook disappears when the queue is empty.

Preview errors remain a separate verification task; processing every queue entry does not certify every preview. Keep 0.1.55 as the stable release until candidate review/acceptance and final 0.1.56 publication.
