"""Check rendered hrefs, actual response types and PDF bytes using only localhost."""

import hashlib
import sys
import urllib.parse
import urllib.request
from html.parser import HTMLParser


class Markup(HTMLParser):
    def __init__(self):
        super().__init__()
        self.links = []
        self.frames = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "a":
            self.links.append(attrs)
        elif tag == "iframe":
            self.frames.append(attrs)


def fetch(url):
    url = urllib.parse.urljoin(base, url)
    if urllib.parse.urlsplit(url).hostname != "127.0.0.1":
        raise AssertionError(f"Fixture attempted an external request: {url}")
    with urllib.request.urlopen(url, timeout=10) as response:
        return response.headers.get_content_type(), response.read()


base = sys.argv[1]
markup = Markup()
content_type, content = fetch(base + "/?ftw_download_test=1")
assert content_type == "text/html"
markup.feed(content.decode())
assert len(markup.links) == 9, markup.links
assert len(markup.frames) == 3, markup.frames
pdf_hashes = set()
for start in range(0, 9, 3):
    preview, named, bare = markup.links[start : start + 3]
    for download in (named, bare):
        assert "download" in download, download
        assert download["href"].endswith("/original.pdf"), download
        content_type, pdf = fetch(download["href"])
        assert content_type == "application/pdf", content_type
        assert pdf.startswith(b"%PDF-"), pdf[:80]
        pdf_hashes.add(hashlib.sha256(pdf).hexdigest())
    assert preview["href"] != named["href"]
    content_type, html = fetch(preview["href"])
    assert content_type == "text/html"
    assert b"Accessible preview fixture" in html
for frame in markup.frames:
    content_type, html = fetch(frame["src"])
    assert content_type == "text/html"
    assert b"Accessible preview fixture" in html
assert len(pdf_hashes) == 1
print("PASS: all three registered content/widget filters preserve named and bare PDF downloads, ordinary HTML links, and embedded HTML previews after repeated filtering.")
print("PDF SHA-256:", next(iter(pdf_hashes)))
