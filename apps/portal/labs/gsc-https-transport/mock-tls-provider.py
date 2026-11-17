#!/usr/bin/env python3
"""Bounded synthetic OAuth/sites TLS endpoints; never a Google connection."""

import argparse
import json
import os
from pathlib import Path
import re
import signal
import socket
import socketserver
import ssl
import sys
import threading
import time
from urllib.parse import parse_qsl

SCOPE = "https://www.googleapis.com/auth/webmasters.readonly"
ACCESS = "synthetic-only-access-token-1234567890"
REFRESH = "synthetic-only-refresh-token-1234567890"
REFRESHED_ACCESS = "synthetic-refreshed-access-token-1234567890"
ROTATED_REFRESH = "synthetic-rotated-refresh-token-1234567890"
REFRESH_CASES = frozenset({
    "rotate", "invalid-grant", "redirect", "cross-origin-redirect", "bad-json",
    "wrong-scope", "oversize", "timeout", "temporary-consent",
    "sites-missing-property",
})
SITE_CASES = frozenset({
    "bad-json", "content-type", "oversize", "header-oversize", "http-500",
    "unauthenticated", "redirect", "cross-origin-redirect", "timeout",
    "missing-property", "restricted", "duplicate",
})
METRIC_NAMES = ("token", "sites", "redirect_target", "unexpected", "violations", "total")


class Metrics:
    def __init__(self, path: Path):
        self.path = path
        self.counts = dict.fromkeys(METRIC_NAMES, 0)
        self.lock = threading.Lock()

    def record(self, *names: str) -> None:
        with self.lock:
            for name in names:
                self.counts[name] += 1
            temporary = self.path.with_suffix(".tmp")
            temporary.write_text(json.dumps(self.counts, sort_keys=True), encoding="ascii")
            os.replace(temporary, self.path)


class FixtureServer(socketserver.ThreadingMixIn, socketserver.TCPServer):
    allow_reuse_address = True
    daemon_threads = True
    block_on_close = False

    def __init__(self, port: int, hostname: str, context: ssl.SSLContext, metrics: Metrics):
        self.hostname = hostname
        self.context = context
        self.metrics = metrics
        self.slots = threading.BoundedSemaphore(8)
        super().__init__(("127.0.0.1", port), FixtureRequest)

    def process_request(self, request: socket.socket, client_address: tuple) -> None:
        if not self.slots.acquire(blocking=False):
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except BaseException:
            self.slots.release()
            raise

    def process_request_thread(self, request: socket.socket, client_address: tuple) -> None:
        try:
            super().process_request_thread(request, client_address)
        finally:
            self.slots.release()

    def handle_error(self, request: socket.socket, client_address: tuple) -> None:
        # Never let socketserver print a request, stack, header or body.
        self.metrics.record("violations")


class FixtureRequest(socketserver.BaseRequestHandler):
    def handle(self) -> None:
        if self.client_address[0] != "127.0.0.1":
            return
        self.request.settimeout(2)
        try:
            with self.server.context.wrap_socket(self.request, server_side=True) as tls:
                self.handle_http(tls)
        except (OSError, ssl.SSLError, ValueError):
            # Invalid trust/hostname cases intentionally fail the handshake;
            # they must not be counted as HTTP requests or leak diagnostics.
            return

    def handle_http(self, tls: ssl.SSLSocket) -> None:
        with tls.makefile("rb") as stream:
            line = stream.readline(4097)
            if not line or len(line) > 4096 or not line.endswith(b"\r\n"):
                return
            self.server.metrics.record("total")
            try:
                method, path, protocol = line[:-2].decode("ascii").split(" ")
                if protocol not in ("HTTP/1.0", "HTTP/1.1"):
                    raise ValueError("protocol")
                headers = {}
                size = len(line)
                for _ in range(40):
                    raw = stream.readline(4097)
                    size += len(raw)
                    if size > 16384 or len(raw) > 4096 or not raw.endswith(b"\r\n"):
                        raise ValueError("headers")
                    if raw == b"\r\n":
                        break
                    name, value = raw[:-2].decode("ascii").split(":", 1)
                    name = name.lower()
                    if not re.fullmatch(r"[a-z0-9-]+", name) or name in headers:
                        raise ValueError("headers")
                    headers[name] = value.strip()
                else:
                    raise ValueError("headers")
                if headers.get("host") != self.server.hostname:
                    raise ValueError("host")
                if headers.get("accept") != "application/json" or "transfer-encoding" in headers:
                    raise ValueError("headers")
                length = headers.get("content-length", "0")
                if not re.fullmatch(r"[0-9]{1,5}", length) or int(length) > 8192:
                    raise ValueError("body")
                body = stream.read(int(length))
                if len(body) != int(length):
                    raise ValueError("body")
            except (UnicodeError, ValueError):
                self.server.metrics.record("unexpected", "violations")
                self.send(tls, 400, b'{}')
                return

            if path == "/never-follow":
                self.server.metrics.record("redirect_target")
                self.send(tls, 404, b'{}')
            elif self.server.hostname == "oauth2.googleapis.com" and path == "/token":
                self.server.metrics.record("token")
                self.token(tls, method, headers, body)
            elif self.server.hostname == "www.googleapis.com" and path == "/webmasters/v3/sites":
                self.server.metrics.record("sites")
                self.sites(tls, method, headers, body)
            else:
                self.server.metrics.record("unexpected", "violations")
                self.send(tls, 404, b'{}')

    def reject(self, tls: ssl.SSLSocket) -> None:
        self.server.metrics.record("violations")
        self.send(tls, 400, b'{}')

    def token(self, tls: ssl.SSLSocket, method: str, headers: dict, body: bytes) -> None:
        if method != "POST" or headers.get("content-type") != "application/x-www-form-urlencoded" or "authorization" in headers:
            self.reject(tls)
            return
        try:
            pairs = parse_qsl(body.decode("ascii"), keep_blank_values=True,
                             strict_parsing=True, max_num_fields=6, encoding="ascii", errors="strict")
            form = dict(pairs)
            if len(pairs) != len(form):
                raise ValueError("fields")
            if not (
                form.get("client_id") == "1234567890-f116syntheticonly.apps.googleusercontent.com"
                and form.get("client_secret") == "synthetic-secret-f116-private-only"
            ):
                raise ValueError("fields")
            if form.get("grant_type") == "refresh_token":
                if (os.environ.get("PORTAL_GSC_REFRESH_LAB") != "1" or set(form) != {
                    "client_id", "client_secret", "refresh_token", "grant_type",
                }):
                    raise ValueError("refresh fields")
                self.refresh(tls, form["refresh_token"])
                return
            if len(form) != 6 or set(form) != {
                "client_id", "client_secret", "code", "code_verifier", "redirect_uri", "grant_type",
            } or not (
                form["grant_type"] == "authorization_code"
                and form["redirect_uri"] == "https://portal.example.test/integrations/google/search-console/callback"
                and re.fullmatch(r"[A-Za-z0-9_-]{43,128}", form["code_verifier"])
            ):
                raise ValueError("authorization code fields")
        except (UnicodeError, ValueError):
            self.reject(tls)
            return

        scenario = form["code"]
        valid = {"access_token": ACCESS, "expires_in": 300, "scope": SCOPE,
                 "token_type": "Bearer", "refresh_token": REFRESH}
        initial_refresh = "synthetic-initial-refresh-"
        if (os.environ.get("PORTAL_GSC_REFRESH_LAB") == "1"
                and scenario.startswith(initial_refresh)
                and scenario[len(initial_refresh):] in REFRESH_CASES):
            valid["refresh_token"] = "synthetic-refresh-" + scenario[len(initial_refresh):] + "-token-1234567890"
        elif scenario.startswith("synthetic-sites-") and scenario[16:] in SITE_CASES:
            valid["access_token"] = scenario + "-access-token-1234567890"
        elif scenario == "synthetic-good-with-refresh":
            pass
        elif scenario == "synthetic-good-without-refresh":
            del valid["refresh_token"]
        elif scenario in ("synthetic-json-charset", "synthetic-json-charset-quoted",
                          "synthetic-json-charset-open", "synthetic-json-charset-close"):
            mime = {
                "synthetic-json-charset": "application/json; charset=utf-8",
                "synthetic-json-charset-quoted": 'application/json; charset="utf-8"',
                "synthetic-json-charset-open": 'application/json; charset="utf-8',
                "synthetic-json-charset-close": 'application/json; charset=utf-8"',
            }[scenario]
            self.send(tls, 200, self.json(valid), mime=mime)
            return
        elif scenario in ("synthetic-redirect", "synthetic-cross-origin-redirect", "synthetic-egress-redirect"):
            target = {
                "synthetic-redirect": "https://oauth2.googleapis.com/never-follow",
                "synthetic-cross-origin-redirect": "https://www.googleapis.com/webmasters/v3/sites",
                "synthetic-egress-redirect": "https://unexpected.example.invalid/never-follow",
            }[scenario]
            self.send(tls, 302, b'{}', extra={"Location": target})
            return
        elif scenario == "synthetic-denied-scope":
            valid["scope"] = "https://www.googleapis.com/auth/webmasters"
        elif scenario == "synthetic-bad-expiry":
            valid["expires_in"] = 86400
        elif scenario == "synthetic-zero-expiry":
            valid["expires_in"] = 0
        elif scenario == "synthetic-bad-type":
            valid["token_type"] = "Basic"
        elif scenario == "synthetic-extra-field":
            valid["id_token"] = "synthetic-not-allowed"
        elif scenario == "synthetic-invalid-refresh":
            valid["refresh_token"] = "short"
        elif scenario == "synthetic-bad-json":
            self.send(tls, 200, b'{"access_token":')
            return
        elif scenario == "synthetic-oversize":
            self.send(tls, 200, b"a" * 20000)
            return
        elif scenario == "synthetic-header-oversize":
            self.send(tls, 200, self.json(valid), extra={"X-Synthetic-Padding": "a" * 20000})
            return
        elif scenario in ("synthetic-http-500", "synthetic-unauthenticated"):
            self.send(tls, 500 if scenario == "synthetic-http-500" else 401, b'{}')
            return
        elif scenario == "synthetic-content-type":
            self.send(tls, 200, self.json(valid), mime="text/plain")
            return
        elif scenario == "synthetic-empty":
            self.send(tls, 200, b"")
            return
        elif scenario == "synthetic-malformed-token":
            valid["access_token"] = "\nINVALID"
        elif scenario == "synthetic-noninteger-expiry":
            valid["expires_in"] = "300"
        elif scenario == "synthetic-timeout":
            time.sleep(3)
        else:
            self.reject(tls)
            return
        self.send(tls, 200, self.json(valid))

    def refresh(self, tls: ssl.SSLSocket, refresh_token: str) -> None:
        # The caller has already checked the exact four-field refresh form.
        # No authorization code, verifier, redirect or bearer is accepted here.
        valid = {"access_token": REFRESHED_ACCESS, "expires_in": 600,
                 "scope": SCOPE, "token_type": "Bearer"}
        if refresh_token in (REFRESH, ROTATED_REFRESH):
            self.send(tls, 200, self.json(valid))
            return
        prefix = "synthetic-refresh-"
        suffix = "-token-1234567890"
        if not refresh_token.startswith(prefix) or not refresh_token.endswith(suffix):
            self.reject(tls)
            return
        scenario = refresh_token[len(prefix):-len(suffix)]
        if scenario == "rotate":
            valid["refresh_token"] = ROTATED_REFRESH
        elif scenario == "invalid-grant":
            self.send(tls, 400, b'{"error":"invalid_grant"}')
            return
        elif scenario in ("redirect", "cross-origin-redirect"):
            target = ("https://oauth2.googleapis.com/never-follow" if scenario == "redirect"
                      else "https://www.googleapis.com/webmasters/v3/sites")
            self.send(tls, 302, b'{}', extra={"Location": target})
            return
        elif scenario == "bad-json":
            self.send(tls, 200, b'{"access_token":')
            return
        elif scenario == "wrong-scope":
            valid["scope"] = "https://www.googleapis.com/auth/webmasters"
        elif scenario == "oversize":
            self.send(tls, 200, b"a" * 20000)
            return
        elif scenario == "timeout":
            time.sleep(3)
        elif scenario == "temporary-consent":
            valid["refresh_token_expires_in"] = 3600
        elif scenario == "sites-missing-property":
            valid["access_token"] = "synthetic-sites-missing-property-access-token-1234567890"
        else:
            self.reject(tls)
            return
        self.send(tls, 200, self.json(valid))

    def sites(self, tls: ssl.SSLSocket, method: str, headers: dict, body: bytes) -> None:
        if method != "GET" or body:
            self.reject(tls)
            return
        bearer = headers.get("authorization", "")
        if bearer in ("Bearer " + ACCESS, "Bearer " + REFRESHED_ACCESS):
            scenario = "good"
        elif bearer.startswith("Bearer synthetic-sites-") and bearer.endswith("-access-token-1234567890"):
            scenario = bearer[len("Bearer synthetic-sites-"):-len("-access-token-1234567890")]
            if scenario not in SITE_CASES:
                self.reject(tls)
                return
        else:
            self.reject(tls)
            return
        valid = {"siteEntry": [
            {"siteUrl": "sc-domain:example.com", "permissionLevel": "siteOwner"},
            {"siteUrl": "https://restricted.example.com/", "permissionLevel": "siteRestrictedUser"},
        ]}
        if scenario == "bad-json":
            self.send(tls, 200, b'{"siteEntry":')
        elif scenario == "content-type":
            self.send(tls, 200, self.json(valid), mime="text/plain")
        elif scenario == "oversize":
            self.send(tls, 200, b"a" * 40000)
        elif scenario == "header-oversize":
            self.send(tls, 200, self.json(valid), extra={"X-Synthetic-Padding": "a" * 20000})
        elif scenario in ("http-500", "unauthenticated"):
            self.send(tls, 500 if scenario == "http-500" else 401, b'{}')
        elif scenario in ("redirect", "cross-origin-redirect"):
            target = ("https://www.googleapis.com/never-follow" if scenario == "redirect"
                      else "https://oauth2.googleapis.com/never-follow")
            self.send(tls, 302, b'{}', extra={"Location": target})
        else:
            if scenario == "missing-property":
                valid["siteEntry"] = [{"siteUrl": "sc-domain:other.example", "permissionLevel": "siteOwner"}]
            elif scenario == "restricted":
                valid["siteEntry"][0]["permissionLevel"] = "siteRestrictedUser"
            elif scenario == "duplicate":
                valid["siteEntry"].append(dict(valid["siteEntry"][0]))
            elif scenario == "timeout":
                time.sleep(3)
            self.send(tls, 200, self.json(valid))

    @staticmethod
    def json(value: dict) -> bytes:
        return json.dumps(value, separators=(",", ":")).encode("ascii")

    @staticmethod
    def send(tls: ssl.SSLSocket, status: int, body: bytes, *,
             mime: str = "application/json", extra: dict | None = None) -> None:
        status_text = {200: "OK", 302: "Found", 400: "Bad Request", 401: "Unauthorized",
                       404: "Not Found", 500: "Internal Server Error"}[status]
        fields = {"Content-Type": mime, "Content-Length": str(len(body)),
                  "Cache-Control": "no-store", "Connection": "close", **(extra or {})}
        header = "HTTP/1.1 " + str(status) + " " + status_text + "\r\n"
        header += "".join(name + ": " + value + "\r\n" for name, value in fields.items()) + "\r\n"
        tls.sendall(header.encode("ascii") + body)


def context_for(directory: Path, name: str, hostname: str) -> ssl.SSLContext:
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.set_alpn_protocols(["http/1.1"])
    context.load_cert_chain(str(directory / (name + ".pem")), str(directory / (name + ".key")))

    def sni(connection: ssl.SSLSocket, server_name: str | None, initial: ssl.SSLContext):
        if server_name != hostname:
            return ssl.ALERT_DESCRIPTION_UNRECOGNIZED_NAME
        return None

    context.set_servername_callback(sni)
    return context


def main() -> None:
    if not (os.environ.get("APP_ENV") == "testing"
            and os.environ.get("PORTAL_GSC_HTTPS_TRANSPORT_LAB") == "1"
            and os.environ.get("GITHUB_ACTIONS") == "true"
            and os.environ.get("RUNNER_ENVIRONMENT") == "github-hosted"):
        raise RuntimeError("Disposable TLS laboratory required")
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("certificate_directory", type=Path)
    parser.add_argument("metrics_file", type=Path)
    parser.add_argument("--certificate-mode", choices=("valid", "wrong-host"), default="valid")
    args = parser.parse_args()
    root = Path(os.environ["RUNNER_TEMP"]).resolve(strict=True)
    directory = args.certificate_directory.resolve(strict=True)
    metrics_path = args.metrics_file.resolve()
    if (directory.parent != root or not directory.name.startswith("gsc-https-")
            or metrics_path.parent != directory or metrics_path.exists()
            or args.metrics_file.is_symlink()):
        raise RuntimeError("Private disposable TLS directory required")
    os.umask(0o077)
    metrics = Metrics(metrics_path)
    servers = []
    started = []
    try:
        for port, hostname, name in [(18443, "oauth2.googleapis.com", "token"),
                                     (18444, "www.googleapis.com", "sites")]:
            leaf = "wrong-host" if args.certificate_mode == "wrong-host" else name
            server = FixtureServer(port, hostname, context_for(directory, leaf, hostname), metrics)
            servers.append(server)
        stop = threading.Event()
        signal.signal(signal.SIGTERM, lambda _signum, _frame: stop.set())
        signal.signal(signal.SIGINT, lambda _signum, _frame: stop.set())
        for server in servers:
            threading.Thread(target=server.serve_forever, daemon=True).start()
            started.append(server)
        # The all-zero metrics file is also the readiness signal: both literal
        # loopback sockets and TLS contexts exist before it becomes visible.
        metrics.record()
        stop.wait(180)
    finally:
        for server in servers:
            if server in started:
                server.shutdown()
            server.server_close()


if __name__ == "__main__":
    try:
        main()
    except (Exception, KeyboardInterrupt):
        print("GSC synthetic TLS fixture failed", file=sys.stderr)
        sys.exit(1)
