# Changelog

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
