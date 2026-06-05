# Changelog

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
