# PDF download regression (issue #30)

Build an installable ZIP with a single `filetoweb-integration/` directory containing the plugin entry point and `includes/`. Then run:

```sh
FTW_DOWNLOAD_TEST_ZIP=/absolute/path/filetoweb-integration-0.1.58-rc.1.zip \
  bash tests/integration/download-links/run.sh
```

Requires Docker Compose, Python 3, `wordpress:6.9.4-php8.2-apache`, and `mariadb:10.11`. The test creates an isolated `ftw-download-test-issue30` project with a disposable database, installs and activates the actual ZIP, and removes its containers, volumes, and network on exit. Override the project with `FTW_DOWNLOAD_TEST_PROJECT=ftw-download-test-<unique-name>` and the localhost port with `FTW_DOWNLOAD_TEST_PORT=18031` if needed. Never target a customer WordPress instance or an existing database.

The fixture generates a deterministic PDF and a ready local HTML preview. Representative widget markup passes through the real registered `the_content`, `widget_text`, and `widget_text_content` hooks, including a repeated page-content pass. Checks fetch each named/bare Download target, require `application/pdf` and `%PDF-` bytes, compare download hashes, and verify that ordinary links and iframe previews still serve HTML. A test-only MU plugin blocks external WordPress HTTP requests; there are no API keys, conversions, cloud-storage writes, or credit usage.

For the negative control, run the same harness against the unchanged 0.1.57 release ZIP: it must fail because Download hrefs point at HTML. Unit regressions additionally cover attribute order, quotes, empty values, casing, whitespace, signed GCS URLs, misleading attribute text, and quoted `>` characters.

The local fixture represents ProudCity widget markup; it does not load ProudCity core, SiteOrigin, WP Stateless, or its production cache stack. Final acceptance on ProudCity is therefore required:

1. Confirm `wp-proud-core` includes `2026.10.28.1351` (the document list must request `proud_document_original_url()`). Install/pin this plugin candidate through the normal testing process and clear rendered-page caches.
2. On [Duchesne library policies](https://duchesne-county-ut.proudcity.com/library-policies-and-forms/), download a ready document. Its URL must be the original PDF, response type `application/pdf`, and saved file must open as a PDF. Check more than one button.
3. Repeat the Download check at the bottom of [Digital collection help](https://duchesne-county-ut.proudcity.com/digital-collection-help/), and confirm the embedded preview still shows accessible HTML.
4. Confirm an ordinary link to the same ready PDF still opens HTML, while [the document's own page](https://duchesne-county-ut.proudcity.com/documents/3d-printing-policy/) still downloads the PDF. Non-ready and publicly paused items must keep their existing PDF behavior.

No reconversion, preview refresh, migration, or new FileToWeb credits are needed. Keep issue #30 open until the ProudCity acceptance checks pass.
