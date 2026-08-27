# FileToWeb rooted-preview migration

This is a standalone, operator-run WP-CLI repair tool for FileToWeb preview
records created before tenant-prefixed WP Stateless storage. It is not loaded
by the plugin, has no activation hook, registers no cron event, never loops
over multiple sites, and never deletes an object.

The migration requires FileToWeb Integration 0.1.50 or newer and an active,
authenticated WP Stateless client. Always supply one exact site through
`--url`.

## Safety model

- `audit`, `verify`, and `migrate` without `--apply` are read-only.
- A write invocation accepts at most 10 postmeta records and then exits.
- Pagination uses a caller-supplied `meta_id` cursor.
- Selection is one bounded query against `wp_postmeta`; there is no posts join,
  serialized-value predicate, background event, or automatic reschedule.
- Intact rooted bundles use the plugin's tested per-document migration method.
  That method downloads the recorded objects, rewrites embedded legacy URLs,
  syncs and verifies the tenant-prefixed objects, and updates the record last.
- Old rooted objects remain untouched for fleet-wide reconciliation.
- Missing objects are not partially migrated and do not trigger conversion.
- Write commands use the plugin's existing connection-scoped MySQL advisory
  lock, so they cannot overlap the normal FileToWeb polling worker or another
  migration command. A busy command exits without changing a record.

## Load the command

Replace both paths and the site URL with Curtis's environment-specific values:

```bash
wp --url=https://test-site.example \
  --require=/absolute/path/filetoweb-preview-migration.php \
  filetoweb preview-migration audit --limit=100
```

The command prints `next_cursor=<meta_id>`. Pass it as `--after` to inspect the
next page. A page containing no records repeats the supplied cursor and
completes that pass.

Use `--format=json` to capture a stable audit artifact containing both the
records and the next cursor.

## Recommended test-site sequence

1. Audit the site in pages, initially without GCS checks:

   ```bash
   wp --url=https://test-site.example \
     --require=/absolute/path/filetoweb-preview-migration.php \
     filetoweb preview-migration audit --after=0 --limit=100 --format=json
   ```

2. Dry-run the first five records with authenticated object verification:

   ```bash
   wp --url=https://test-site.example \
     --require=/absolute/path/filetoweb-preview-migration.php \
     filetoweb preview-migration migrate --after=0 --limit=5
   ```

3. Apply that exact bounded page:

   ```bash
   wp --url=https://test-site.example \
     --require=/absolute/path/filetoweb-preview-migration.php \
     filetoweb preview-migration migrate --after=0 --limit=5 --apply
   ```

4. Verify the page and every recorded GCS object:

   ```bash
   wp --url=https://test-site.example \
     --require=/absolute/path/filetoweb-preview-migration.php \
     filetoweb preview-migration verify --after=0 --limit=5
   ```

5. Confirm representative public previews from more than one pod, confirm
   database health, then continue with the returned cursor. Restart each site
   at cursor `0`; each already-current record is an idempotent skip.

Do not use the WordPress **Refresh embedded preview** button or edit the same
documents while a write batch is running. The shared lock excludes normal cron
polling, while this short operator quiet period excludes an administrator
starting an independent manual refresh of the same record.

## Statuses

- `rooted`: all recorded old objects exist and the record can be migrated.
- `rooted_missing`: at least one old object is absent; use explicit repair.
- `tenant_prefixed`: current and complete.
- `tenant_prefixed_missing`: current metadata but a missing GCS object; repair.
- `*_check_error`: GCS could not be checked reliably; retry the audit before
  deciding that the object is missing.
- `incomplete`: required preview record data is absent.
- `nonstandard`: a prefixed record does not have the expected schema/backend or
  its manifest mixes prefixes. Review it manually.
- `invalid`: not a valid FileToWeb preview record.

An action of `migrated_unverified` means the per-record write completed but the
immediate GCS verification was inconclusive or found a missing destination.
Stop that site and inspect the record before continuing.

## Repair a missing bundle

Repair is deliberately limited to one exact preview-owner post ID. It follows
the existing **Refresh embedded preview** path: it polls the stored FileToWeb
document and fetches its completed HTML with cache bypass. It does not submit a
conversion or call the reprocess endpoint.

Run the dry form first:

```bash
wp --url=https://test-site.example \
  --require=/absolute/path/filetoweb-preview-migration.php \
  filetoweb preview-migration repair --post-id=123
```

Then explicitly apply it:

```bash
wp --url=https://test-site.example \
  --require=/absolute/path/filetoweb-preview-migration.php \
  filetoweb preview-migration repair --post-id=123 --apply
```

If FileToWeb no longer has the completed output, the command reports the stored
error and leaves the existing record unchanged for review. It never queues a
new conversion or clears the record automatically.

## Lock behavior

If the normal polling worker is active, the write command reports that it is
busy and changes nothing. Retry the same cursor after that worker finishes. The
database releases the advisory lock automatically if a process terminates, so
there is no persistent lock option to clean up.

## Cleanup is intentionally excluded

Do not delete `filetoweb-integration/previews/**` at the bucket root during this
migration. A rooted object can still be referenced by another site. After every
site reports zero rooted references, aggregate the audit results and prepare a
separate deletion manifest for review and a retention period.
