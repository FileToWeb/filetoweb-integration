# FileToWeb Integration

Regular WordPress plugin that connects PDF attachments and Proud Document records to the generic FileToWeb document API.

## Behavior

- Newly created or edited PDF attachments are submitted to FileToWeb.
- Proud Document saves reuse the linked attachment when one is present; otherwise the plugin resolves the document URL back to a WordPress attachment with `attachment_url_to_postid()`.
- Repeated saves are idempotent because FileToWeb receives a stable `external_id` and source fingerprint.
- Public front-end PDF links are replaced with FileToWeb HTML links only after the document is ready.
- Admin screens keep the original PDF link and show FileToWeb status, generated HTML, editor link, manual sync, and poll actions.
- Manual backfill is available from **Settings > FileToWeb** and is bounded by the configured batch size.

## Settings

The plugin owns its settings through the WordPress Settings API:

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

The original WordPress file remains intact.

## Security Notes

- API base URL must be HTTPS and on the allowed FileToWeb API host list.
- The API key is never sent to non-allowlisted hosts.
- Source URLs are checked for public HTTP/HTTPS hosts before HEAD requests or API submission.
- FileToWeb response fields are explicitly allowlisted and sanitized before storage.
- Result/editor URLs must use trusted FileToWeb hosts.
- Admin actions use nonces and capability checks.
- No global output buffering is used for public rewriting.

## Development

```bash
composer install
composer test
```

## Packaging

The package type is `wordpress-plugin`, so Composer installers can place it under `wp-content/plugins/filetoweb-integration`.
