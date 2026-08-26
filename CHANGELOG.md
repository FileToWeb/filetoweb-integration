# Changelog

## 0.1.46

- Publish preview manifests and generated HTML asset references with the exact tenant-prefixed GCS object URLs used by ProudCity's WP Stateless configuration.
- Preserve complete nested FileToWeb bundle paths while enabling WP Stateless root handling, preventing preview bundles from different tenants from sharing object names.
- Verify every uploaded preview artifact through the authenticated WP Stateless GCS client instead of sending a public request to a randomly selected WordPress pod.
- Recover schema-v1 preview bundles from their intact GCS objects and republish them under the corrected namespace without uploading or reconverting the source PDF, while retaining legacy shared-object identities for deferred fleet-wide cleanup.

## 0.1.45

- Make an explicit **Refresh embedded preview** request fetch the latest published FileToWeb HTML even when the original PDF and viewer URL are unchanged.
- Publish editor-only HTML changes to a content-versioned preview bundle so ProudCity activates the refreshed markup without reusing stale preview files.
- Request cache revalidation during explicit refreshes without uploading or reconverting the source PDF.

## 0.1.44

- Verify true WP Stateless preview bundles through WordPress's authenticated stream storage instead of requiring private GCS objects to answer anonymous public requests.
- Prevent trashed, auto-draft, or deleted attachments and Proud Documents from scheduling, retrying, or polling FileToWeb work.
- Remove deleted WordPress content from the plugin's local sync and poll queues.

## 0.1.43

- Add **Refresh embedded preview** to ready Agenda, Agenda Packet, and Minutes attachments in both ProudCity Meeting editor layouts.
- Reuse the existing attachment refresh flow so a Meeting preview can be republished without uploading or reconverting its source PDF.
- Show customer-safe WordPress publication errors directly beside ready Meeting materials when the generated HTML exists but its embedded preview is unavailable.
- Preserve the exact publication failure when an older local preview cannot be migrated to ProudCity's durable preview record.

## 0.1.42

- Poll pending conversions every minute in oldest-due-first order so large syncs cannot indefinitely block completed files.
- Move checked processing files to a bounded 1, 2, 5, then 10 minute backoff window with small deterministic jitter.
- Recover pre-upgrade pending items that do not yet have queue metadata by processing them oldest-first.
- Share one configured batch limit across upload retries, regular conversions, and PDF-to-Page jobs.
- Prevent overlapping WordPress cron workers with a connection-scoped database lock that cannot expire during a healthy batch.

## 0.1.41

- Add a per-PDF **Show original PDF publicly** control for Proud Documents, Media PDFs, and individual Meeting materials.
- Keep conversion, editing, and local preview updates running while a source is paused, without republishing it automatically.
- Require an explicit **Restore HTML preview** action and verify that the local HTML matches the current PDF before restoring it.
- Apply a paused attachment everywhere it is reused and remove the new pause records and preserved preview bundles during uninstall.
- Keep generated page roots inside their visible page boundaries when the source layout uses padding and full-width content.
- Preserve sanitized CSS raw text exactly so Unicode symbols, localized text, and generated-content icons render correctly in WordPress previews.

## 0.1.40

- Refresh ProudCity previews against the linked attachment that owns the public preview record, then copy the refreshed FileToWeb state back to the Document.
- Keep attachment-less Proud Documents and direct attachment refreshes on their existing source records.

## 0.1.39

- Enumerate and clean preview bundles through stream-wrapper-compatible directory reads so true WP Stateless `gs://` storage can produce a complete artifact manifest.
- Preserve customer-safe publication-stage errors so a later storage or verification failure is distinguishable from bundle creation.

## 0.1.38

- Add a Refresh embedded preview action for ready Proud Documents in the editor and document list.
- Re-check the existing FileToWeb document and republish its WordPress preview without uploading or reconverting the PDF.

## 0.1.37

- Preserve complete nested preview paths when publishing HTML bundles through WP Stateless in true Stateless mode.
- Ensure the public-read update targets the same Google Cloud Storage object that WordPress created before verifying the preview URL.

## 0.1.36

- Make document and Meeting actions state-specific: Sync PDF now before submission, Check conversion progress while processing, no redundant action when ready, and Retry processing after failure.
- Keep manual recovery available when a submission fails before FileToWeb assigns a document ID.

## 0.1.35

- Replace polling terminology in WordPress admin actions with clear labels such as Check now, Check FileToWeb progress, and Check pending conversions.
- Update processing help and completion notices to use the same user-focused status-check language.

## 0.1.34

- Add explicit retry controls for failed Meeting materials and PDF-to-Page conversions.
- Preserve customer-safe failure codes, support references, and retryability across every upload step.

## 0.1.33

- Store customer-safe processing errors with a searchable support reference while hiding provider and pipeline details.
- Add a Retry processing action for failed documents using FileToWeb's explicit reprocess endpoint.
- Preserve structured failure status during polling and clear prior failure details after a successful retry.

## 0.1.32

- Publishes sanitized, fingerprinted HTML preview bundles and provider-neutral `_proud_html_preview` records with complete artifact manifests for ProudCity Documents and Meeting materials.
- Mirrors supported images, fonts, stylesheets, CSS URLs, and srcset assets into WordPress uploads before atomically publishing each record.
- Synchronizes bundle files through WP Stateless's non-media hook when available, without requiring WP Stateless.
- Migrates existing local HTML caches to the durable preview contract without reconverting unchanged PDFs.
- Preserves completed previews on deactivation, disables them through the explicit public-replacement setting, and removes FileToWeb records/artifacts on uninstall.
- Queues remote non-media artifacts for provider-neutral verified deletion when supported ProudCity core is present during uninstall.

## 0.1.31

- Adds a nonce-protected Test connection action to the FileToWeb settings page.
- Confirms the connected FileToWeb workspace and folder without displaying the stored API key.
- Verifies that the configured key has the document read and write permissions required for conversion.
- Keeps Settings API registration compatible with ProudCity's legacy local WordPress image.
- Keeps non-JSON API failures concise instead of rendering upstream HTML in WordPress notices.

## 0.1.30

- Adds admin processing-time help next to Processing statuses for attachments, Proud Documents, Meetings, and PDF-to-Page conversions.
- Clarifies that larger or more complex PDFs can take up to 10 minutes while public links keep using the original PDF.

## 0.1.29

- Adds a FileToWeb attribution paragraph to public accessibility statement pages when the integration is enabled.
- Keeps attribution render-time only, filterable, and out of saved WordPress page content.

## 0.1.28

- Aligns inline ProudCity Meeting FileToWeb controls with ProudCity's Change File / Remove File button row.

## 0.1.27

- Auto-refreshes pending Pages > Convert PDF to Page jobs while the admin screen is open.
- Keeps the manual Poll status action available as an immediate fallback.

## 0.1.26

- Aligns inline ProudCity Meeting FileToWeb controls with the existing Change File / Remove File button row.
- Keeps the Meeting material status badge and links visible without pushing the sync actions out of alignment.

## 0.1.25

- Keeps admins on Pages > Convert PDF to Page while uploaded PDFs are processing.
- Tracks pending PDF-to-Page conversions without creating placeholder draft Pages.
- Creates the editable draft Page only after FileToWeb returns ready HTML.

## 0.1.24

- Recognizes real ProudForm-generated Meeting attachment field names and IDs for inline sync controls.
- Keeps Agenda Packet mapping distinct from Agenda so the inline action targets the correct Meeting material.

## 0.1.23

- Makes the optional ProudCity Document EPUB download configurable and off by default.
- Adds a one-time migration that hides EPUB downloads on existing installs unless an admin re-enables them later.

## 0.1.22

- Adds inline FileToWeb sync controls below ProudCity Meeting PDF upload fields when `wp-proud-core` exposes `proud_form_after_file_upload`.
- Keeps the existing Meeting material metabox and sync-all action for full status, links, polling, and batch sync.
- Scopes inline controls to Agenda, Agenda Packet, and Minutes PDF fields only.

## 0.1.21

- Stops automatic WordPress Page creation from normal PDF attachment and Proud Document sync.
- Keeps WordPress-local HTML caching and public replacement for ready attachment, Proud Document, and Meeting material sources.
- Adds Pages > Convert PDF to Page for intentional PDF-to-draft-Page conversion using the FileToWeb signed-upload API.
- Polls marked PDF-to-Page drafts and updates the same draft Page with editable WordPress HTML when conversion is ready.
- Sends the uploading admin one email with the draft edit link after the converted Page is ready.

## 0.1.20

- Polishes ProudCity Document EPUB button markup so it can be inserted next to the existing Download button without invalid nested block elements.

## 0.1.19

- Adds an optional Download EPUB action to ready ProudCity Document pages while preserving the original PDF download.
- Links EPUB downloads to a public FileToWeb EPUB landing page and only prepares the EPUB file when clicked.

## 0.1.18

- Prevents cron polling from discovering never-synced historical PDF attachments.
- Marks newly uploaded PDF attachments as intentionally scheduled so missed upload syncs can still retry safely.
- Adds FileToWeb trigger metadata to document upserts for upload/save, manual sync, backfill, bulk queue, cron retry, and meeting-material sync paths.

## 0.1.17

- Requests FileToWeb continuous HTML in no-chrome mode for WordPress-local caching.
- Mirrors referenced FileToWeb image assets into WordPress uploads and rewrites local HTML to those WordPress-hosted assets.

## 0.1.16

- Moves links that already point to the plugin's WordPress-local HTML cache to the approved WordPress-native page when one is published and approved.

## 0.1.15

- Caches ready FileToWeb HTML into WordPress-local files during sync/poll/admin actions.
- Changes citizen-facing replacement to prefer approved WordPress pages, then WordPress-local HTML, then the original PDF fallback.
- Keeps FileToWeb generated/editor URLs in admin while avoiding FileToWeb-hosted runtime URLs on public pages.
- Creates editable draft WordPress pages from local HTML and requires explicit approval before a page becomes the public replacement.
- Adds Proud Document list status column and row sync/poll actions.
- Adds bounded bulk queue controls for all Proud Documents and all ProudCity Meeting PDFs.
- Adds filterable capability gates: API settings default to `activate_plugins`, PDF sync defaults to `edit_others_posts`.
- Updates the widget to link to or embed WordPress-local HTML.
- Hides FileToWeb viewer shell/header in locally cached public output.

## 0.1.14

- Prefers FileToWeb page URLs for meeting previews.

## 0.1.13

- Makes duplicate upload URL handling deterministic by preferring the newest matching WordPress attachment and preserving the newest ready URL map entry.

## 0.1.12

- Prefers direct WordPress attachment resolution before the cached URL map so duplicate upload URLs do not rewrite to stale FileToWeb documents.

## 0.1.11

- Bounds FileToWeb API request timeouts for admin/save sync flows so transient imports stay retryable.

## 0.1.10

- Prefers the current ProudCity Meeting material when rewriting preview iframes for reused or duplicate PDF URLs.

## 0.1.9

- Preserves literal ProudCity Meeting material download links when they are rendered inside post content.

## 0.1.8

- Adds optional ProudCity Meeting material support for Agenda, Agenda Packet, and Minutes attachments.
- Adds a Meeting edit-screen FileToWeb panel with per-material sync/poll actions and sync-all for the current meeting.
- Replaces ready Meeting Google Docs preview iframes with FileToWeb HTML while preserving original PDF downloads.

## 0.1.7

- Hardens source URL safety checks for IPv4/IPv6 private addresses and WordPress HTTP unsafe URL rejection.
- Removes the current-site host SSRF bypass and adds defensive scalar/string guards for stricter PHP runtimes.
- Adds a filter to disable the bundled FileToWeb widget and makes admin processing status more visible.

## 0.1.6

- Treats transient FileToWeb API timeouts as pending retries instead of terminal conversion failures.
- Nudges WP-Cron when new PDF uploads are scheduled for sync and retries recently missed uploads during polling.
- Adds sync/poll lifecycle hooks for companion plugins that create reviewed native WordPress drafts.

## 0.1.5

- Adds a public replacement URL filter so reviewed native WordPress pages can override ready FileToWeb HTML links.

## 0.1.4

- Adds ProudCity Document viewer replacement while preserving original PDF downloads.

## 0.1.3

- Corrects external service disclosure links.

## 0.1.2

- Adds WordPress.org-style external service disclosure.

## 0.1.1

- Consolidates plugin settings into a single Settings API option row.
- Adds tests for capability gates, link rewriting, widget output, uninstall cleanup, settings registration, and legacy settings migration.
- Preserves migration from the previous multi-option settings model.

## 0.1.0

- Initial standalone FileToWeb WordPress plugin.
- Adds settings, PDF sync, polling, bounded backfill, link replacement, Proud Document support, standard widget support, uninstall cleanup, and security hardening.
