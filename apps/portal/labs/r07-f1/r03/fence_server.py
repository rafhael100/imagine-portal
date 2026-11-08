#!/usr/bin/env python3
"""Disposable HTTP allow/deny gate whose state is OUTSIDE the PostgreSQL dump.
Not an identity service, HA fence, production architecture, or trusted T3 fix.
"""
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

STATE = Path("/fence/state.json")

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path != "/v1/allow":
            self.send_error(404)
            return
        try:
            raw = STATE.read_bytes()
            if len(raw) > 2048:
                raise ValueError("large state")
            state = json.loads(raw)
            allowed = (type(state) is dict
                       and state.get("state") == "open"
                       and type(state.get("generation")) is int
                       and state.get("generation") == 2
                       and state.get("reconciled") is True
                       and state.get("db") == "portal_r07_restored")
        except (OSError, ValueError, TypeError, UnicodeError):
            allowed = False
        payload = b'{"allow":true}' if allowed else b'{"allow":false}'
        self.send_response(200 if allowed else 503)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, format, *args):
        pass

ThreadingHTTPServer(("0.0.0.0", 8081), Handler).serve_forever()
