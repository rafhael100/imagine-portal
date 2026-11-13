#!/usr/bin/env python3
"""Adversarial tests for the no-deploy, 127.0.0.1-only synthetic Portal viewer."""
from __future__ import annotations

from contextlib import contextmanager
from hashlib import sha256
from http.client import HTTPConnection
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory
from threading import Thread
import unittest
from zipfile import ZIP_DEFLATED, ZipFile

SCRIPT = Path(__file__).resolve().parents[1] / "tools" / "serve_private_preview.py"
SPEC = importlib.util.spec_from_file_location("imagine_private_preview", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Cannot import local-only private preview")
preview = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(preview)


def fixture_pages() -> dict[str, bytes]:
    return {
        name: ('<!DOCTYPE html><html lang="pt-BR"><head>'
               '<meta name="robots" content="noindex,nofollow">'
               '<title>Imagine fictícia</title></head><body>'
               '<main><h1>Somente teste</h1></main></body></html>').encode("utf-8")
        for name in preview.PAGES
    }


def fixture_manifest(pages: dict[str, bytes]) -> dict:
    return {
        "schema": "imagine-portal-visual-only-preview-v1",
        "synthetic_only": True,
        "logo_git_blob_sha1": preview.LOGO_BLOB,
        "authorizes_customer_access": False,
        "authorizes_payment": False,
        "authorizes_deployment": False,
        "pages": [
            {"name": name, "bytes": len(data), "sha256": sha256(data).hexdigest()}
            for name, data in sorted(pages.items())
        ],
    }


def write_fixture(path: Path, pages: dict[str, bytes], manifest: dict,
                  extra: dict[str, bytes] | None = None) -> None:
    with ZipFile(path, "w", ZIP_DEFLATED) as bundle:
        for name, data in pages.items():
            bundle.writestr(name, data)
        bundle.writestr("manifest.json", json.dumps(manifest).encode("utf-8"))
        for name, data in (extra or {}).items():
            bundle.writestr(name, data)


@contextmanager
def private_http_server(pages: dict[str, bytes]):
    with preview.create_local_server(pages, 0) as server:
        worker = Thread(target=server.serve_forever, daemon=True)
        worker.start()
        try:
            yield server
        finally:
            server.shutdown()
            worker.join(timeout=5)


def request(port: int, method: str, path: str, host: str | None = None):
    connection = HTTPConnection("127.0.0.1", port, timeout=5)
    try:
        connection.request(method, path, headers={
            "Host": host if host is not None else "127.0.0.1:" + str(port),
        })
        response = connection.getresponse()
        return response.status, dict(response.getheaders()), response.read()
    finally:
        connection.close()


class TestPrivatePreview(unittest.TestCase):
    def test_valid_artifact_is_verified_before_serve_and_reports_no_go(self):
        with TemporaryDirectory() as root:
            source = Path(root) / "synthetic.zip"
            pages = fixture_pages()
            write_fixture(source, pages, fixture_manifest(pages))
            accepted = preview.load_verified_bundle(source)
            self.assertEqual(accepted, pages)
            run = subprocess.run([sys.executable, str(SCRIPT), "--zip", str(source),
                                  "--check"], capture_output=True, text=True, timeout=10)
            self.assertEqual(run.returncode, 0, run.stderr)
            result = json.loads(run.stdout)
            self.assertEqual(result["pages"], 11)
            self.assertTrue(result["synthetic_only"])
            self.assertFalse(result["public_access_authorized"])
            self.assertFalse(result["homologation_complete"])

    def test_tamper_downgrade_and_extra_file_are_rejected(self):
        cases = ("tampered", "approved_by_manifest", "script", "extra", "missing",
                 "unexpected_logo", "duplicate_record")
        for case in cases:
            with self.subTest(case=case), TemporaryDirectory() as root:
                source = Path(root) / "synthetic.zip"
                pages = fixture_pages()
                manifest = fixture_manifest(pages)
                extra = None
                if case == "tampered":
                    pages["overview.html"] = b"<html>injected</html>"
                if case == "approved_by_manifest":
                    manifest["authorizes_deployment"] = True
                if case == "script":
                    pages["services.html"] = (
                        b'<html><meta name="robots" content="noindex,nofollow">'
                        b'<script>window.alert(1)</script></html>'
                    )
                    manifest = fixture_manifest(pages)
                if case == "extra":
                    extra = {"../../.env": b"SENSITIVE"}
                if case == "missing":
                    pages.pop("journey.html")
                if case == "unexpected_logo":
                    manifest["logo_git_blob_sha1"] = "0" * 40
                if case == "duplicate_record":
                    manifest["pages"][1] = manifest["pages"][0]
                write_fixture(source, pages, manifest, extra)
                with self.assertRaises(preview.BundleRefused):
                    preview.load_verified_bundle(source)

    def test_rehashed_manifest_cannot_authorize_active_html_or_fake_logo(self):
        # Deliberately regenerate SHA256 after modifying the HTML to prove
        # checksums alone do not confer semantic safety or visual provenance.
        modifications = {
            "javascript_anchor": '<a href="javascript:alert(1)">Ir</a>',
            "entity_encoded_anchor": '<a href="&#106;avascript:alert(1)">Ir</a>',
            "inline_event": '<a href="overview.html" onclick="alert(1)">Ir</a>',
            "meta_refresh": '<meta http-equiv="refresh" content="0;url=/admin">',
            "remote_css": '<style>@import url(https://example.invalid/mal.css);</style>',
            "embedded_fake_png": '<img src="data:image/png;base64,QUJDRA==">',
            "iframe": '<iframe src="about:blank"></iframe>',
        }
        for label, injected in modifications.items():
            with self.subTest(label=label), TemporaryDirectory() as root:
                source = Path(root) / "synthetic.zip"
                pages = fixture_pages()
                original = pages["overview.html"].decode("utf-8")
                changed = original.replace("</body>", injected + "</body>")
                if label == "remote_css":
                    # A direct https:// match is also forbidden, as expected.
                    changed = changed.replace("https://example.invalid/", "data:")
                pages["overview.html"] = changed.encode("utf-8")
                write_fixture(source, pages, fixture_manifest(pages))
                with self.assertRaises(preview.BundleRefused):
                    preview.load_verified_bundle(source)

    def test_symlink_does_not_open(self):
        with TemporaryDirectory() as root:
            source = Path(root) / "fixture.zip"
            link = Path(root) / "symlink.zip"
            pages = fixture_pages()
            write_fixture(source, pages, fixture_manifest(pages))
            try:
                link.symlink_to(source)
            except OSError:
                # GitHub-hosted Windows without Developer Mode may deny symlink
                # creation; Linux CI continues to exercise the refusal.
                if sys.platform == "win32":
                    self.skipTest("Windows runner lacks symlink creation privilege")
                raise
            with self.assertRaises(preview.BundleRefused):
                preview.load_verified_bundle(link)

    def test_http_only_loopback_get_head_no_cache_and_no_public_actions(self):
        pages = fixture_pages()
        with private_http_server(pages) as server:
            port = server.server_port
            self.assertEqual(server.server_address[0], "127.0.0.1")
            status, headers, body = request(port, "GET", "/")
            self.assertEqual(status, 200)
            self.assertEqual(body, pages["index.html"])
            self.assertEqual(headers["Cache-Control"], "no-store, max-age=0")
            self.assertIn("frame-ancestors 'none'", headers["Content-Security-Policy"])
            self.assertIn("script-src 'none'", headers["Content-Security-Policy"])
            self.assertEqual(headers["X-Robots-Tag"], "noindex, nofollow")
            status, headers, body = request(port, "HEAD", "/overview.html")
            self.assertEqual(status, 200)
            self.assertEqual(body, b"")
            self.assertEqual(int(headers["Content-Length"]), len(pages["overview.html"]))
            status, _, body = request(port, "GET", "/overview.html")
            self.assertEqual(status, 200)
            self.assertEqual(body, pages["overview.html"])
            for method in ("POST", "PUT", "DELETE", "PATCH"):
                with self.subTest(method=method):
                    status, _, body = request(port, method, "/overview.html")
                    self.assertEqual(status, 405)
                    self.assertEqual(body, b"")
            for path in ("/manifest.json", "/.env", "/admin", "/../../.env",
                         "/overview.html?x=1", "/%2e%2e/etc/passwd", "//overview.html"):
                with self.subTest(path=path):
                    status, _, body = request(port, "GET", path)
                    self.assertEqual(status, 404)
                    self.assertEqual(body, b"")
            status, _, body = request(port, "GET", "/index.html",
                                      host="malicious.example")
            self.assertEqual(status, 403)
            self.assertEqual(body, b"")

    def test_illegal_port_rejected_before_socket(self):
        pages = fixture_pages()
        for port in (-1, 65536):
            with self.assertRaises(ValueError):
                preview.create_local_server(pages, port)


if __name__ == "__main__":
    unittest.main()
