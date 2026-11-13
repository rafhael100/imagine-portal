#!/usr/bin/env python3
"""Read-only local HTTP viewer for a verified Imagine Portal SYNTHETIC preview ZIP.

This deliberately does NOT mount a Laravel route, enable customers, deploy to
the VPS, or make a service remotely accessible. Binds only 127.0.0.1.
"""
from __future__ import annotations

import argparse
from hashlib import sha1, sha256
from html.parser import HTMLParser
import base64
import binascii
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import re
import stat
import sys
from typing import Any
from zipfile import BadZipFile, ZipFile

PAGES = frozenset({
    "index.html", "overview.html", "services.html", "preparation.html",
    "available.html", "contracts.html", "modules.html", "journey.html",
    "requests.html", "help.html", "notices.html",
})
MANIFEST = "manifest.json"
MAX_ZIP_BYTES = 16 * 1024 * 1024
MAX_PAGE_BYTES = 1_000_000
LOGO_BLOB = "678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77"
CSP = ("default-src 'none'; script-src 'none'; connect-src 'none'; "
       "style-src 'unsafe-inline'; img-src data:; form-action 'none'; "
       "base-uri 'none'; frame-ancestors 'none'")
BAD_TAG = re.compile(rb"<\s*(?:script|form|input|button|iframe|object|embed)\b", re.I)


class BundleRefused(ValueError):
    """A preview bundle is invalid, unapproved or unsafe to serve."""


class SafePreviewHTML(HTMLParser):
    """Allowlist local synthetic hyperlinks and the EXACT original Imagine logo."""

    BANNED = frozenset({
        "script", "form", "input", "button", "iframe", "object", "embed",
        "svg", "math", "video", "audio", "source", "link", "base",
        "textarea", "select", "template",
    })

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.landmarks: dict[str, int] = {"main": 0, "h1": 0, "title": 0}
        self._style_depth = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag in self.BANNED:
            raise BundleRefused("Forbidden active HTML tag.")
        if tag in self.landmarks:
            self.landmarks[tag] += 1
        if tag == "style":
            self._style_depth += 1
        for key, value in attrs:
            name = key.lower()
            if (name.startswith("on") or name in {
                "srcdoc", "srcset", "formaction", "action", "xlink:href",
            }):
                raise BundleRefused("Forbidden executable HTML attribute.")
            if name == "href" and (tag != "a" or value not in PAGES | {"#pi-content"}):
                raise BundleRefused("Non-local or unsafe HTML hyperlink.")
            if name == "src":
                if tag != "img" or not isinstance(value, str) or not value.startswith(
                    "data:image/png;base64,"
                ):
                    raise BundleRefused("Unexpected image reference.")
                try:
                    image = base64.b64decode(value.split(",", 1)[1], validate=True)
                except (ValueError, binascii.Error) as exc:
                    raise BundleRefused("Malformed embedded logo.") from exc
                if (len(image) != 5594 or sha1(
                    b"blob " + str(len(image)).encode("ascii") + b"\x00" + image
                ).hexdigest() != LOGO_BLOB):
                    raise BundleRefused("Image does not match original Imagine logo.")
            if name == "http-equiv" and (
                tag != "meta" or not isinstance(value, str)
                or value.lower() != "content-security-policy"
            ):
                raise BundleRefused("HTML refresh/redirect or unknown HTTP-equivalent.")
            if name == "style" and isinstance(value, str) and re.search(
                r"url\s*\(|@import|expression\s*\(|-moz-binding", value, re.I
            ):
                raise BundleRefused("CSS import or external resource prohibited.")

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.handle_starttag(tag, attrs)
        self.handle_endtag(tag)

    def handle_endtag(self, tag: str) -> None:
        if tag == "style":
            self._style_depth = max(0, self._style_depth - 1)

    def handle_data(self, data: str) -> None:
        if self._style_depth and re.search(
            r"@import|url\s*\(|expression\s*\(", data, re.I
        ):
            raise BundleRefused("CSS in style tag contains an external resource.")

    def assert_landmarks(self) -> None:
        if any(count != 1 for count in self.landmarks.values()):
            raise BundleRefused("HTML must contain exactly one main, title and h1.")


def load_verified_bundle(path: Path) -> dict[str, bytes]:
    """Verify EVERY file before any socket can be opened."""
    try:
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_ZIP_BYTES:
            raise BundleRefused("Bundle must be a small regular file, not a symlink.")
        with ZipFile(path, "r") as bundle:
            entries = bundle.infolist()
            names = [item.filename for item in entries]
            if len(names) != len(PAGES) + 1 or set(names) != PAGES | {MANIFEST}:
                raise BundleRefused("ZIP inventory must contain 11 approved HTML files and manifest.")
            blobs: dict[str, bytes] = {}
            for item in entries:
                mode = (item.external_attr >> 16) & stat.S_IFMT(0o170000)
                if (item.is_dir() or mode not in (0, stat.S_IFREG)
                    or item.file_size > MAX_PAGE_BYTES
                    or item.file_size > 1024 * max(1, item.compress_size)):
                    raise BundleRefused("ZIP contains an invalid, oversized or unsafe entry.")
                payload = bundle.read(item)
                if len(payload) != item.file_size:
                    raise BundleRefused("ZIP length mismatch.")
                blobs[item.filename] = payload
    except (BadZipFile, OSError, RuntimeError, EOFError) as exc:
        raise BundleRefused("Could not safely read approved ZIP.") from exc

    try:
        manifest: Any = json.loads(blobs[MANIFEST].decode("utf-8"))
    except (UnicodeError, ValueError) as exc:
        raise BundleRefused("Invalid preview manifest JSON.") from exc
    if (not isinstance(manifest, dict)
        or manifest.get("schema") != "imagine-portal-visual-only-preview-v1"
        or manifest.get("synthetic_only") is not True
        or manifest.get("logo_git_blob_sha1") != LOGO_BLOB
        or any(manifest.get(flag) is not False for flag in (
            "authorizes_customer_access", "authorizes_payment",
            "authorizes_deployment"))):
        raise BundleRefused("Preview manifest is not explicitly synthetic and nondeployable.")
    records = manifest.get("pages")
    if not isinstance(records, list) or len(records) != len(PAGES):
        raise BundleRefused("Expected one integrity record per HTML page.")
    observed = set()
    for record in records:
        if not isinstance(record, dict) or set(record) != {"name", "bytes", "sha256"}:
            raise BundleRefused("Unexpected page metadata.")
        name = record["name"]
        if not isinstance(name, str) or name not in PAGES or name in observed:
            raise BundleRefused("Missing, duplicated or unexpected preview page.")
        observed.add(name)
        payload = blobs[name]
        if (type(record["bytes"]) is not int or record["bytes"] != len(payload)
            or record["sha256"] != sha256(payload).hexdigest()):
            raise BundleRefused("Preview content checksum or byte length mismatch.")
        if (b"noindex,nofollow" not in payload
            or b"http://" in payload or b"https://" in payload
            or BAD_TAG.search(payload)):
            raise BundleRefused("Preview contains an external link or active HTML element.")
        try:
            html = payload.decode("utf-8", "strict")
        except UnicodeDecodeError as exc:
            raise BundleRefused("Invalid UTF-8 HTML.") from exc
        parser = SafePreviewHTML()
        parser.feed(html)
        parser.close()
        parser.assert_landmarks()
    if observed != PAGES:
        raise BundleRefused("Preview manifest omitted a page.")
    del blobs[MANIFEST]  # Never expose the inventory or manifest over HTTP.
    return blobs


def create_local_server(pages: dict[str, bytes], port: int) -> ThreadingHTTPServer:
    """Offer only named HTML pages on IPv4 loopback; no filesystem access."""
    if not 0 <= port <= 65535:
        raise ValueError("Invalid loopback port.")

    class Handler(BaseHTTPRequestHandler):
        server_version = "ImaginePreview"
        sys_version = ""

        def log_message(self, format: str, *args: object) -> None:
            return

        def parse_request(self) -> bool:
            # BaseHTTPRequestHandler canonically rewrites //path to /path.
            # Preserve the ORIGINAL request-target to refuse ambiguous paths.
            raw_parts = self.raw_requestline.split(b" ", 2)
            self._ambiguous_raw_target = (
                len(raw_parts) >= 2 and raw_parts[1].startswith(b"//")
            )
            return super().parse_request()

        def _headers(self, status: int, length: int = 0) -> None:
            self.send_response(status)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(length))
            self.send_header("Cache-Control", "no-store, max-age=0")
            self.send_header("Content-Security-Policy", CSP)
            self.send_header("X-Frame-Options", "DENY")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("X-Robots-Tag", "noindex, nofollow")
            self.send_header("Referrer-Policy", "no-referrer")
            self.end_headers()

        def _render(self, include_body: bool) -> None:
            correct_host = "127.0.0.1:" + str(self.server.server_port)
            if self.headers.get("Host") != correct_host:
                self._headers(403)
                return
            if getattr(self, "_ambiguous_raw_target", False):
                self._headers(404)
                return
            # Reject queries, encodings, duplicate slashes, traversal and names
            # not present in the integrity-verified, immutable in-memory bundle.
            path = "/index.html" if self.path == "/" else self.path
            name = path[1:] if path.startswith("/") else ""
            if path != "/" + name or name not in pages:
                self._headers(404)
                return
            payload = pages[name]
            self._headers(200, len(payload))
            if include_body:
                self.wfile.write(payload)

        def do_GET(self) -> None:
            self._render(True)

        def do_HEAD(self) -> None:
            self._render(False)

        def do_POST(self) -> None:
            self._headers(405)

        do_PUT = do_POST
        do_DELETE = do_POST
        do_PATCH = do_POST

    return ThreadingHTTPServer(("127.0.0.1", port), Handler)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--zip", required=True, type=Path, help="Private offline HTML artifact ZIP.")
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check", action="store_true", help="Verify content without opening a port.")
    mode.add_argument("--serve", action="store_true", help="Serve only on 127.0.0.1 for private SSH tunnelling.")
    parser.add_argument("--port", type=int, default=18417, help="127.0.0.1-only TCP port (default 18417).")
    args = parser.parse_args()
    try:
        pages = load_verified_bundle(args.zip)
        if args.check:
            print(json.dumps({"verified": True, "synthetic_only": True,
                              "pages": len(pages), "public_access_authorized": False,
                              "homologation_complete": False}, sort_keys=True))
            return 0
        with create_local_server(pages, args.port) as server:
            print("SYNTHETIC-ONLY, NO-GO | http://127.0.0.1:"
                  + str(server.server_port) + "/index.html", flush=True)
            server.serve_forever(poll_interval=0.2)
    except (BundleRefused, OSError, ValueError) as exc:
        print("Private preview refused: " + type(exc).__name__, file=sys.stderr)
        return 2
    except KeyboardInterrupt:
        return 0
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
