=== FileToWeb Integration ===
Contributors: filetoweb
Tags: pdf, html, accessibility, documents, media
Requires at least: 5.7
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert PDF media files to FileToWeb HTML pages and replace public PDF links once conversion is ready.

== Description ==

FileToWeb Integration connects a WordPress site to the FileToWeb API. When a PDF attachment or Proud Document source is uploaded or saved, the plugin can sync it to FileToWeb, poll for conversion status, and replace public PDF links with generated HTML links after the document is ready.

Original WordPress media files remain intact. The plugin stores FileToWeb state in post meta and only rewrites public front-end output when conversion is complete and public replacement is enabled.

Features:

* Plugin-owned settings page for API URL, API key, public replacement, and bounded batch size.
* Automatic sync for new PDF attachments and Proud Document saves.
* Manual bounded backfill for existing PDF attachments and Proud Document posts.
* WP-Cron polling for pending conversions.
* Front-end link replacement for attachment URLs, rendered content, text widgets, and Proud Document metadata.
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

The API key is stored as a WordPress option owned by this plugin. Only users with `manage_options` can access the settings page.

== Changelog ==

= 0.1.0 =

* Initial release with FileToWeb settings, sync, polling, bounded backfill, public link replacement, Proud Document support, widget support, uninstall cleanup, and security hardening.
