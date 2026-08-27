# FileToWeb Integration

Regular WordPress plugin that connects PDF attachments and Proud Document records to the generic FileToWeb document API.

## Behavior

- Newly created or edited PDF attachments are submitted to FileToWeb.
- Upload-triggered syncs nudge WP-Cron, and polling retries intentionally scheduled or transiently timed-out uploads.
- Pending conversions and PDF-to-Page jobs share an oldest-due-first queue budget with bounded backoff and a one-minute worker so large migrations cannot indefinitely block completed files.
- A connection-scoped database lock prevents overlapping workers without expiring during a healthy long-running batch.
- Proud Document saves reuse the linked attachment when one is present; otherwise the plugin resolves the document URL back to a WordPress attachment with `attachment_url_to_postid()`.
- ProudCity Meeting Agenda, Agenda Packet, and Minutes attachments sync independently when the Meeting is saved, and supported ProudCity builds show inline status, preview errors, and ready-preview refresh controls below those upload fields.
- ProudCity Document EPUB downloads are available as an opt-in setting and are off by default.
- Repeated saves are idempotent because FileToWeb receives a stable `external_id` and source fingerprint.
- Ready FileToWeb output is published as a sanitized, fingerprinted bundle under WordPress uploads during sync/poll/admin actions, not during citizen page loads.
- True WP Stateless installations preserve the bundle's complete nested Google Cloud Storage object paths when making preview artifacts public.
- Each completed bundle publishes a provider-neutral `_proud_html_preview` record with a complete artifact manifest that updated ProudCity core/theme releases can serve after this plugin is deactivated and clean up after uninstall. On WP Stateless sites, bundle paths and generated asset URLs use the exact tenant-prefixed GCS namespace and are verified directly through authenticated storage.
- Public front-end PDF links are replaced with the WordPress-local HTML copy. If no local HTML exists, the original PDF remains in use.
- ProudCity Meeting preview iframes can show the WordPress-local HTML copy while keeping Download buttons and literal material links pointed at the original PDF.
- Add-on plugins can override the ready public replacement URL through `filetoweb_integration_ready_replacement_url`.
- Sites can disable the bundled FileToWeb widget with `filetoweb_integration_enable_widget`.
- ProudCity sites can disable meeting material support with `filetoweb_integration_enable_meeting_materials` or disable only meeting preview rewrites with `filetoweb_integration_rewrite_meeting_viewer`.
- Admin screens keep the original PDF link and show FileToWeb status, generated HTML, editor link, manual sync, and poll actions.
- Proud Documents, Media PDFs, and individual Meeting materials can temporarily show the original PDF publicly while conversion and editing continue; restoring HTML is an explicit action and requires a preview that matches the current PDF.
- Pages > Convert PDF to Page creates an editable draft WordPress Page from an uploaded PDF without adding that PDF to the Media Library.
- PDF-to-Page drafts update in place when FileToWeb conversion is ready, and the uploading admin receives a one-time email with the edit link.
- Manual backfill is available from **Settings > FileToWeb** and is bounded by the configured batch size.
- A bulk sync queue can process all Proud Documents or all ProudCity Meeting PDFs in bounded batches.
- Existing media-library PDFs are not discovered by cron automatically; migration/backfill requires an explicit admin action.

## Settings

The plugin owns its settings through the WordPress Settings API and stores them in one WordPress option row, `filetoweb_integration_settings`:

- Enabled
- FileToWeb API URL
- Scoped FileToWeb API key
- Public link replacement to WordPress-local HTML
- Backfill/poll batch size

The default API URL is `https://filetoweb.com`. Authorization headers are only sent to allowed HTTPS FileToWeb API hosts.

## Link Replacement

Replacement is limited to front-end rendering. Admin, REST, AJAX, feeds, and XML-RPC keep original WordPress URLs and FileToWeb admin/editor links.

The plugin rewrites:

- `wp_get_attachment_url()` output for ready PDF attachments
- Proud Document `document` post meta on the front end
- literal PDF and explicit attachment-ID links inside `the_content`
- text widget content
- Google Docs preview iframe URLs inside filtered content when they wrap a ready WordPress-local HTML copy
- ProudCity single Meeting Google Docs preview iframes for ready Agenda, Agenda Packet, and Minutes PDFs

The original WordPress file remains intact. Public rendering does not make a live request to FileToWeb; if the WordPress-local copy is unavailable, the original PDF URL is left in place.

For one-item recovery, use **Show original PDF publicly** in that source's FileToWeb panel. The active public preview record is retained privately and refreshed by later syncs, but WordPress keeps using the PDF until an editor chooses **Restore HTML preview**. Restoration fails closed if the local HTML does not match the current source fingerprint. Because the pause belongs to the source attachment, reusing the same Media PDF in Documents or Meetings pauses it everywhere.

On ProudCity releases that support `_proud_html_preview`, completed Proud Document and Meeting preview bundles remain available after plugin deactivation. Turning off **Replace public PDF links with generated HTML** explicitly disables the FileToWeb preview provider and restores PDF previews. Uninstalling queues exact tenant-prefixed artifacts for verified deletion. Legacy rootless object identities remain in ProudCity's `proud_html_preview_legacy_artifacts` registry so they can be removed safely after every tenant has migrated. Generic links in arbitrary page/widget content still require the active plugin because those replacements are performed at render time.

## Security Notes

- API base URL must be HTTPS and on the allowed FileToWeb API host list.
- The API key is never sent to non-allowlisted hosts.
- Source URLs are checked for public HTTP/HTTPS hosts before HEAD requests or API submission.
- FileToWeb response fields are explicitly allowlisted and sanitized before storage.
- Result/editor URLs must use trusted FileToWeb hosts and are shown to admins, not used as the default public runtime URL.
- Published preview bundles remove scripts, event handlers, unsafe elements/schemes, and mirror supported image, font, and stylesheet assets into WordPress uploads.
- Preview publication fails closed to the original PDF if WordPress cannot parse and sanitize the generated HTML.
- ProudCity's provider-neutral endpoint validates the provider, token, source identity, artifact path, and trusted uploads/GCS URL before serving a bundle with a restrictive Content Security Policy.
- Admin actions use nonces and capability checks.
- API settings default to the `activate_plugins` capability; PDF sync actions default to `edit_others_posts`; both can be filtered.
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
