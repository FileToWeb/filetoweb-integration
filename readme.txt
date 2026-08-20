=== FileToWeb Integration ===
Contributors: filetoweb
Tags: pdf, html, accessibility, documents, media
Requires at least: 5.7
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.44
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert PDF media files with FileToWeb, serve WordPress-local HTML to public visitors, and optionally create draft WordPress Pages from uploaded PDFs.

== Description ==

FileToWeb Integration connects a WordPress site to the FileToWeb API. When a PDF attachment or Proud Document source is uploaded or saved, the plugin can sync it to FileToWeb, poll for conversion status, cache a WordPress-local HTML copy, and replace public PDF links with local HTML after the document is ready.

Original WordPress media files remain intact. The plugin stores FileToWeb state in post meta and only rewrites public front-end output when conversion is complete, a WordPress-local copy exists, and public replacement is enabled. If local HTML is unavailable, public visitors keep seeing the original PDF.

Features:

* Plugin-owned settings page for API URL, API key, public replacement, and bounded batch size, stored in one option row.
* Automatic sync for new PDF attachments and Proud Document saves.
* Manual bounded backfill for existing PDF attachments and Proud Document posts.
* Bulk queue controls for all Proud Documents or all ProudCity Meeting PDFs.
* Fair WP-Cron polling for pending conversions and PDF-to-Page jobs, with oldest-due-first selection, bounded backoff, recovery, and intentionally scheduled upload retries.
* Admin processing-time help on pending conversion statuses so users know larger PDFs can take several minutes.
* Front-end link replacement for attachment URLs, rendered content, text widgets, and Proud Document metadata, using WordPress-local HTML instead of public FileToWeb URLs.
* Durable provider-neutral preview records with complete artifact manifests for supported ProudCity Document and Meeting templates, with sanitized fingerprinted bundles under WordPress uploads.
* Fail-closed preview publication: if WordPress cannot parse and sanitize generated HTML, the original PDF remains in use.
* ProudCity Document preview replacement while preserving the original PDF download link.
* Optional, opt-in ProudCity Document EPUB download link for ready PDF-backed documents.
* ProudCity Meeting material support for Agenda, Agenda Packet, and Minutes PDFs, with per-material sync, upload-field controls on supported ProudCity builds, and WordPress-local preview replacement.
* Standard WordPress widget for linking to or embedding a PDF attachment or Proud Document's local HTML copy.
* Pages > Convert PDF to Page workflow for intentionally creating editable draft WordPress Pages after uploaded PDFs finish converting.
* Render-time FileToWeb attribution on public accessibility statement pages when the integration is enabled.
* Original PDF links are preserved in admin screens.
* Per-PDF controls can temporarily show the original PDF publicly while FileToWeb syncing and editing continue in the background.

== Installation ==

1. Install the plugin into `wp-content/plugins/filetoweb-integration`.
2. Activate FileToWeb Integration from the WordPress Plugins screen.
3. Open Settings > FileToWeb.
4. Create a project-scoped API key in FileToWeb under Settings > API keys, then paste it into the plugin.
5. Confirm the API URL is `https://filetoweb.com`.
6. Leave public replacement enabled if ready PDF links should point to WordPress-local HTML.
7. Click Test connection to confirm the FileToWeb workspace and folder.

== Frequently Asked Questions ==

= Does this replace or delete the original PDF? =

No. Original WordPress media files remain in place. The plugin stores FileToWeb metadata separately and rewrites public links only when a WordPress-local HTML copy is ready. The separate Pages > Convert PDF to Page workflow uploads a PDF for conversion without adding that PDF to the Media Library.

= Can existing PDFs be migrated? =

Yes. The settings page includes a bounded Backfill batch action. Administrators control the batch size so migration can be paced against site performance and FileToWeb credit usage.

= Can I temporarily switch one published HTML preview back to its PDF? =

Yes. Use **Show original PDF publicly** in the FileToWeb panel for a Proud Document or Media PDF, or beside an individual Meeting material. FileToWeb can continue syncing while the PDF stays public. When the HTML is ready, use **Restore HTML preview**. Restoration is manual and only succeeds when the local HTML matches the current PDF. A reused Media attachment is paused everywhere it appears.

= What happens if the plugin is deactivated, disabled, or uninstalled? =

On ProudCity releases that support the provider-neutral preview contract, deactivation stops sync and polling but preserves completed Proud Document and Meeting HTML previews. Explicitly turning off public replacement disables the FileToWeb provider and restores the original PDF preview. Uninstalling removes FileToWeb preview records and artifacts; supported ProudCity core releases retry remote WP Stateless deletion until storage confirms removal. Generic links in arbitrary page and widget content require the active plugin.

= Where is the API key stored? =

The API key is stored in the single WordPress option row owned by this plugin. By default, users need `activate_plugins` to access API settings. Sync actions default to `edit_others_posts`. Both capabilities are filterable.

== External services ==

This plugin connects to the FileToWeb API at `https://filetoweb.com`.

When a PDF is synced, the plugin sends FileToWeb the PDF source URL, filename, source fingerprint, and basic WordPress metadata needed to keep the conversion idempotent. FileToWeb imports the PDF, converts it to HTML, and returns conversion status plus generated page/editor URLs. A FileToWeb account and scoped API key are required.

FileToWeb service information:

* Website: https://filetoweb.com
* Terms: https://filetoweb.com/terms-of-service
* Privacy: https://filetoweb.com/privacy-policy

== Changelog ==

= 0.1.44 =

* Verifies true WP Stateless preview bundles through authenticated WordPress storage, allowing ProudCity's private GCS previews to publish correctly.
* Prevents trashed, auto-draft, and deleted WordPress content from scheduling new FileToWeb work.
* Clears removed WordPress content from FileToWeb sync and polling queues.

= 0.1.43 =

* Adds Refresh embedded preview to completed Agenda, Agenda Packet, and Minutes attachments in both ProudCity Meeting editor layouts.
* Republishes a Meeting preview without uploading or reconverting its source PDF.
* Shows customer-safe WordPress publication errors directly beside completed Meeting materials.
* Preserves the exact publication failure when an older local preview cannot be migrated to ProudCity's durable preview record.

= 0.1.42 =

* Polls pending conversions every minute in oldest-due-first order so large syncs cannot indefinitely block completed files.
* Moves checked processing files to a bounded 1, 2, 5, then 10 minute backoff window with small deterministic jitter.
* Recovers pre-upgrade pending items that do not yet have queue metadata by processing them oldest-first.
* Shares one configured batch limit across upload retries, regular conversions, and PDF-to-Page jobs.
* Prevents overlapping WordPress cron workers from polling the same batch with a connection-scoped database lock.

= 0.1.41 =

* Adds per-PDF controls to show the original PDF publicly and manually restore a current HTML preview.
* Keeps paused previews private while sync and editing continue, including for individual Meeting materials and reused attachments.
* Cleans paused preview state and preserved FileToWeb artifacts during uninstall.
* Keeps generated page content inside its visible page boundaries across WordPress preview widths.
* Preserves Unicode symbols and other raw CSS text when publishing sanitized WordPress preview bundles.

= 0.1.40 =

* Refreshes ProudCity previews against the linked attachment that owns the public preview record, then copies the refreshed FileToWeb state back to the Document.
* Keeps attachment-less Proud Documents and direct attachment refreshes on their existing source records.

= 0.1.39 =

* Enumerates and cleans preview bundles through stream-wrapper-compatible directory reads so true WP Stateless `gs://` storage can produce a complete artifact manifest.
* Preserves customer-safe publication-stage errors so a later storage or verification failure is distinguishable from bundle creation.

= 0.1.38 =

* Adds a Refresh embedded preview action for ready Proud Documents in the editor and document list.
* Re-checks the existing FileToWeb document and republishes its WordPress preview without uploading or reconverting the PDF.

= 0.1.37 =

* Preserves complete nested preview paths when publishing HTML bundles through WP Stateless in true Stateless mode.
* Ensures the public-read update targets the same Google Cloud Storage object that WordPress created before verifying the preview URL.

= 0.1.36 =

* Makes document and Meeting actions state-specific: Sync PDF now before submission, Check conversion progress while processing, no redundant action when ready, and Retry processing after failure.
* Keeps manual recovery available when a submission fails before FileToWeb assigns a document ID.

= 0.1.35 =

* Replaces polling terminology in WordPress admin actions with clear labels such as Check now, Check FileToWeb progress, and Check pending conversions.
* Updates processing help and completion notices to use the same user-focused status-check language.

= 0.1.34 =

* Adds explicit retry controls for failed Meeting materials and PDF-to-Page conversions.
* Preserves customer-safe failure codes, support references, and retryability across every upload step.

= 0.1.33 =

* Stores customer-safe processing errors with a searchable support reference while hiding provider and pipeline details.
* Adds a Retry processing action for failed documents using FileToWeb's explicit reprocess endpoint.
* Preserves structured failure status during polling and clears prior failure details after a successful retry.

= 0.1.32 =

* Publishes sanitized, fingerprinted HTML preview bundles and provider-neutral `_proud_html_preview` records for ProudCity Documents and Meeting materials.
* Mirrors supported images, fonts, stylesheets, CSS URLs, and srcset assets into WordPress uploads before atomically publishing each record.
* Synchronizes bundle files through WP Stateless's non-media hook when available, without requiring WP Stateless.
* Migrates existing local HTML caches to the durable preview contract without reconverting unchanged PDFs.
* Preserves completed previews on deactivation, disables them through the explicit public-replacement setting, and removes FileToWeb records/artifacts on uninstall.

= 0.1.31 =

* Adds a nonce-protected Test connection action to the FileToWeb settings page.
* Confirms the connected FileToWeb workspace and folder without displaying the stored API key.
* Verifies that the configured key has the document read and write permissions required for conversion.
* Keeps Settings API registration compatible with ProudCity's legacy local WordPress image.
* Keeps non-JSON API failures concise instead of rendering upstream HTML in WordPress notices.

= 0.1.30 =

* Adds admin processing-time help next to Processing statuses for attachments, Proud Documents, Meetings, and PDF-to-Page conversions.
* Clarifies that larger or more complex PDFs can take up to 10 minutes while public links keep using the original PDF.

= 0.1.29 =

* Adds a FileToWeb attribution paragraph to public accessibility statement pages when the integration is enabled.
* Keeps attribution render-time only, filterable, and out of saved WordPress page content.

= 0.1.28 =

* Aligns inline ProudCity Meeting FileToWeb controls with ProudCity's Change File / Remove File button row.

= 0.1.27 =

* Auto-refreshes pending Pages > Convert PDF to Page jobs while the admin screen is open.
* Keeps the manual Poll status action available as an immediate fallback.

= 0.1.26 =

* Aligns inline ProudCity Meeting FileToWeb controls with the existing Change File / Remove File button row.
* Keeps the Meeting material status badge and links visible without pushing the sync actions out of alignment.

= 0.1.25 =

* Keeps admins on Pages > Convert PDF to Page while uploaded PDFs are processing.
* Tracks pending PDF-to-Page conversions without creating placeholder draft Pages.
* Creates the editable draft Page only after FileToWeb returns ready HTML.

= 0.1.24 =

* Recognizes real ProudForm-generated Meeting attachment field names and IDs for inline sync controls.
* Keeps Agenda Packet mapping distinct from Agenda so the inline action targets the correct Meeting material.

= 0.1.23 =

* Makes the optional ProudCity Document EPUB download configurable and off by default.
* Adds a one-time migration that hides EPUB downloads on existing installs unless an admin re-enables them later.

= 0.1.22 =

* Adds inline FileToWeb sync controls below ProudCity Meeting PDF upload fields when `wp-proud-core` exposes `proud_form_after_file_upload`.
* Keeps the existing Meeting material metabox and sync-all action for full status, links, polling, and batch sync.
* Scopes inline controls to Agenda, Agenda Packet, and Minutes PDF fields only.

= 0.1.21 =

* Stops automatic WordPress Page creation from normal PDF attachment and Proud Document sync.
* Keeps WordPress-local HTML caching and public replacement for ready attachment, Proud Document, and Meeting material sources.
* Adds Pages > Convert PDF to Page for intentional PDF-to-draft-Page conversion using the FileToWeb signed-upload API.
* Polls marked PDF-to-Page drafts and updates the same draft Page with editable WordPress HTML when conversion is ready.
* Sends the uploading admin one email with the draft edit link after the converted Page is ready.

= 0.1.20 =

* Polishes ProudCity Document EPUB button markup so it can be inserted next to the existing Download button without invalid nested block elements.

= 0.1.19 =

* Adds an optional Download EPUB action to ready ProudCity Document pages while preserving the original PDF download.
* Links EPUB downloads to a public FileToWeb EPUB landing page and only prepares the EPUB file when clicked.

= 0.1.18 =

* Prevents cron polling from discovering never-synced historical PDF attachments.
* Keeps existing-site migration behind explicit Backfill or Bulk Queue actions.
* Adds FileToWeb trigger metadata for upload/save, manual sync, backfill, bulk queue, cron retry, and meeting-material sync paths.

= 0.1.17 =

* Requests FileToWeb continuous HTML in no-chrome mode for WordPress-local caching.
* Mirrors referenced FileToWeb image assets into WordPress uploads and rewrites local HTML to those WordPress-hosted assets.

= 0.1.16 =

* Moves links that already point to the plugin's WordPress-local HTML cache to the approved WordPress-native page when one is published and approved.

= 0.1.15 =

* Caches ready FileToWeb HTML into WordPress-local files during sync/poll/admin actions and uses those local files for citizen-facing preview/link replacement.
* Creates editable draft WordPress pages from local HTML and requires explicit publish/approval before a page replaces public PDF links.
* Keeps FileToWeb generated/editor links in admin while avoiding FileToWeb-hosted runtime URLs on public pages.
* Adds Proud Document status columns and row-level sync/poll actions.
* Adds bounded bulk queue controls for all Proud Documents and all ProudCity Meeting PDFs.
* Adds filterable capability gates: API settings default to `activate_plugins`, PDF sync defaults to `edit_others_posts`.
* Updates the widget to embed a local HTML copy in page context.
* Hides the FileToWeb viewer shell/header in WordPress-local public output.

= 0.1.14 =

* Prefers FileToWeb page URLs for meeting previews.

= 0.1.13 =

* Makes duplicate upload URL handling deterministic by preferring the newest matching WordPress attachment and preserving the newest ready URL map entry.

= 0.1.12 =

* Prefers direct WordPress attachment resolution before the cached URL map so duplicate upload URLs do not rewrite to stale FileToWeb documents.

= 0.1.11 =

* Bounds FileToWeb API request timeouts for admin/save sync flows so transient imports stay retryable.

= 0.1.10 =

* Prefers the current ProudCity Meeting material when rewriting preview iframes for reused or duplicate PDF URLs.

= 0.1.9 =

* Preserves literal ProudCity Meeting material download links when they are rendered inside post content.

= 0.1.8 =

* Adds guarded ProudCity Meeting support for agenda, agenda packet, and minutes attachments.
* Adds a Meeting edit-screen FileToWeb panel with per-material sync/poll actions and sync-all for the current meeting.
* Replaces ready Meeting Google Docs preview iframes with FileToWeb HTML while preserving original PDF downloads.

= 0.1.7 =

* Hardens source URL safety checks for IPv4/IPv6 private addresses and WordPress HTTP unsafe URL rejection.
* Removes the current-site host SSRF bypass and adds defensive scalar/string guards for stricter PHP runtimes.
* Adds a filter to disable the bundled FileToWeb widget and makes admin processing status more visible.

= 0.1.6 =

* Treats transient FileToWeb API timeouts as pending retries instead of terminal conversion failures.
* Nudges WP-Cron when new PDF uploads are scheduled for sync and retries recently missed uploads during polling.
* Adds sync/poll lifecycle hooks for companion plugins that create reviewed native WordPress drafts.

= 0.1.5 =

* Adds a public replacement URL filter so reviewed native WordPress pages can override ready FileToWeb HTML links.

= 0.1.4 =

* Replaces the ProudCity single Document preview iframe with the ready FileToWeb HTML viewer while preserving the original PDF download link.

= 0.1.3 =

* Corrects external service disclosure links.

= 0.1.2 =

* Adds WordPress.org-style external service disclosure.

= 0.1.1 =

* Consolidates plugin settings into one Settings API option row.
* Adds tests for capability gates, link rewriting, widget output, uninstall cleanup, settings registration, and legacy settings migration.
* Preserves migration from the previous multi-option settings model.

= 0.1.0 =

* Initial release with FileToWeb settings, sync, polling, bounded backfill, public link replacement, Proud Document support, widget support, uninstall cleanup, and security hardening.
