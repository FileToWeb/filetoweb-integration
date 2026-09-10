# Bulk queue interruption tests

Run from the repository root:

```sh
bash tests/integration/bulk-queue/run.sh
```

Requires Docker Compose, `wordpress:6.9.4-php8.2-apache` and `mariadb:10.11`. The runner creates only the `ftw-bulk-test-pr29` project (override with `FTW_BULK_TEST_PROJECT=ftw-bulk-test-<unique-name>`), exposes no host ports and removes that project's containers, volumes and network on exit. Do not point this project at an existing database or customer WordPress installation.

The two WordPress replicas share MariaDB but not their uploads directories. The test uses real WordPress hooks, cron options, attachment metadata, source fingerprinting, plugin sync code and connection-scoped database locks. It starts actual `wp-cron.php` workers and kills the entire test container with SIGKILL, so PHP finally/shutdown handlers cannot rescue the interrupted worker. Additional overlapping/admin dispatches exercise `Bulk_Queue::process_next_batch()` and WordPress's single-event removal behavior.

Checks:

1. Kill a worker after its second API request has been accepted but before it receives the response. The first item remains checkpointed, the second remains in the queue and another replica recovers its document through the external-ID lookup.
2. Kill a worker after sync completes but before its second queue checkpoint. Resume with the same source identity and document ID.
3. Dispatch a second worker while the first owns the bulk lock. It does no duplicate work, preserves a scheduled continuation and cannot replace the running queue.
4. Hold an individual document lock in another replica. That item stays pending until the lock is released.
5. Recover a previously abandoned queue through the registered one-minute hook, without re-queueing or adding items.
6. Delay an item beyond the small test budget. The run checkpoints that item and does not start the next until a later dispatch.

## Boundaries

All WordPress HTTP requests are intercepted by a test-only MU plugin. The simulated FileToWeb service retains accepted documents in the shared test database and verifies stable fingerprints and document IDs on replay. Unknown API requests (including reprocess) fail the test. No real conversions, cloud storage calls, API credentials or billing operations occur. This verifies the plugin's recovery contract; it does **not** independently verify production API billing, GCS publication or ProudCity infrastructure performance.

These are small, failure-focused integration scenarios, not a fleet-scale load test. WordPress attachment records with completed fixture conversions exercise the same bulk dispatcher used by meeting PDFs. The unit suite additionally covers Proud Document queue creation, per-item checkpoints, disabled integrations, scheduling/checkpoint failures and modern/legacy lock behavior.

For a negative control, set `FTW_BULK_TEST_PLUGIN_DIR` to an isolated extracted copy of release 0.1.55 and run the same harness. It must fail rather than report successful interruption recovery. The command runner explicitly fails if WordPress exits before reaching its assertions.
