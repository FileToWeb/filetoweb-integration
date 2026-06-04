# FileToWeb Integration

Regular WordPress plugin that connects PDF attachments and Proud Document records to the generic FileToWeb document API.

## Behavior

- Newly created or edited PDF attachments are submitted to FileToWeb.
- Upload-triggered syncs nudge WP-Cron, and polling retries recently missed or transiently timed-out uploads.
- Proud Document saves reuse the linked attachment when one is present; otherwise the plugin resolves the document URL back to a WordPress attachment with `attachment_url_to_postid()`.
- ProudCity Meeting Agenda, Agenda Packet, and Minutes attachments sync independently when the Meeting is saved.
- Repeated saves are idempotent because FileToWeb receives a stable `external_id` and source fingerprint.
- Public front-end PDF links are replaced with FileToWeb HTML links only after the document is ready.
- ProudCity Meeting preview iframes can show the ready FileToWeb HTML page while keeping Download buttons and literal material links pointed at the original PDF.
- Add-on plugins can override the ready public replacement URL through `filetoweb_integration_ready_replacement_url`, for example to use a reviewed native WordPress page.
- Sites can disable the bundled FileToWeb widget with `filetoweb_integration_enable_widget`.
- ProudCity sites can disable meeting material support with `filetoweb_integration_enable_meeting_materials` or disable only meeting preview rewrites with `filetoweb_integration_rewrite_meeting_viewer`.
- Admin screens keep the original PDF link and show FileToWeb status, generated HTML, editor link, manual sync, and poll actions.
- Manual backfill is available from **Settings > FileToWeb** and is bounded by the configured batch size.

## Settings

The plugin owns its settings through the WordPress Settings API and stores them in one WordPress option row, `filetoweb_integration_settings`:

- Enabled
- FileToWeb API URL
- Scoped FileToWeb API key
- Public link replacement
- Backfill/poll batch size

The default API URL is `https://filetoweb.com`. Authorization headers are only sent to allowed HTTPS FileToWeb API hosts.

## Link Replacement

Replacement is limited to front-end rendering. Admin, REST, AJAX, feeds, and XML-RPC keep original WordPress URLs.

The plugin rewrites:

- `wp_get_attachment_url()` output for ready PDF attachments
- Proud Document `document` post meta on the front end
- literal PDF and attachment-page links inside `the_content`
- text widget content
- Google Docs preview iframe URLs inside filtered content when they wrap a ready FileToWeb page
- ProudCity single Meeting Google Docs preview iframes for ready Agenda, Agenda Packet, and Minutes PDFs

The original WordPress file remains intact.

## Security Notes

- API base URL must be HTTPS and on the allowed FileToWeb API host list.
- The API key is never sent to non-allowlisted hosts.
- Source URLs are checked for public HTTP/HTTPS hosts before HEAD requests or API submission.
- FileToWeb response fields are explicitly allowlisted and sanitized before storage.
- Result/editor URLs must use trusted FileToWeb hosts.
- Admin actions use nonces and capability checks.
- No global output buffering is used for public rewriting.

## External Service

This plugin connects to the FileToWeb API at `https://filetoweb.com`. When a PDF is synced, WordPress sends FileToWeb the PDF source URL, filename, source fingerprint, and basic WordPress metadata needed to keep the conversion idempotent. FileToWeb then imports the PDF, converts it to HTML, and returns conversion status plus generated page/editor URLs. A FileToWeb account and scoped API key are required.

FileToWeb service information:

- Website: https://filetoweb.com
- Terms: https://filetoweb.com/terms-of-service
- Privacy: https://filetoweb.com/privacy-policy

## Development

```bash
composer install
composer test
```

## Packaging

The package type is `wordpress-plugin`, so Composer installers can place it under `wp-content/plugins/filetoweb-integration`.
