=== FileToWeb Integration ===
Contributors: filetoweb
Tags: pdf, html, accessibility, documents, media
Requires at least: 5.7
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert PDF media files to FileToWeb HTML pages and replace public PDF links once conversion is ready.

== Description ==

FileToWeb Integration connects a WordPress site to the FileToWeb API. When a PDF attachment or Proud Document source is uploaded or saved, the plugin can sync it to FileToWeb, poll for conversion status, and replace public PDF links with generated HTML links after the document is ready.

Original WordPress media files remain intact. The plugin stores FileToWeb state in post meta and only rewrites public front-end output when conversion is complete and public replacement is enabled.

Features:

* Plugin-owned settings page for API URL, API key, public replacement, and bounded batch size, stored in one option row.
* Automatic sync for new PDF attachments and Proud Document saves.
* Manual bounded backfill for existing PDF attachments and Proud Document posts.
* WP-Cron polling for pending conversions.
* Front-end link replacement for attachment URLs, rendered content, text widgets, and Proud Document metadata.
* ProudCity Document preview replacement while preserving the original PDF download link.
* ProudCity Meeting material support for Agenda, Agenda Packet, and Minutes PDFs, with per-material sync and preview replacement.
* Standard WordPress widget for linking to a PDF attachment or Proud Document.
* Original PDF links are preserved in admin screens.

== Installation ==

1. Install the plugin into `wp-content/plugins/filetoweb-integration`.
2. Activate FileToWeb Integration from the WordPress Plugins screen.
3. Open Settings > FileToWeb.
4. Enter a scoped FileToWeb API key.
5. Confirm the API URL is `https://filetoweb.com`.
6. Leave public replacement enabled if ready PDF links should point to generated HTML.

== Frequently Asked Questions ==

= Does this replace or delete the original PDF? =

No. Original WordPress media files remain in place. The plugin stores FileToWeb metadata separately and rewrites public links only when a generated HTML page is ready.

= Can existing PDFs be migrated? =

Yes. The settings page includes a bounded Backfill batch action. Administrators control the batch size so migration can be paced against site performance and FileToWeb credit usage.

= Can the integration be disabled? =

Yes. Disable the plugin or turn off the Enabled setting. When disabled, sync, polling, backfill, and public replacement stop, and original PDF links remain in use.

= Where is the API key stored? =

The API key is stored in the single WordPress option row owned by this plugin. Only users with `manage_options` can access the settings page.

== External services ==

This plugin connects to the FileToWeb API at `https://filetoweb.com`.

When a PDF is synced, the plugin sends FileToWeb the PDF source URL, filename, source fingerprint, and basic WordPress metadata needed to keep the conversion idempotent. FileToWeb imports the PDF, converts it to HTML, and returns conversion status plus generated page/editor URLs. A FileToWeb account and scoped API key are required.

FileToWeb service information:

* Website: https://filetoweb.com
* Terms: https://filetoweb.com/terms-of-service
* Privacy: https://filetoweb.com/privacy-policy

== Changelog ==

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
