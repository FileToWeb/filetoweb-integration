# Changelog

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
