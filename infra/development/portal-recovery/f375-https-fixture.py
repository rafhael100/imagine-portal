#!/usr/bin/env python3
"""CI-only HTTPS fixture. Never deploy: it holds a synthetic leaf TLS key."""
import argparse
import http.server
import pathlib
import ssl
import time

parser = argparse.ArgumentParser()
parser.add_argument("--directory", required=True)
parser.add_argument("--cert", required=True)
parser.add_argument("--key", required=True)
parser.add_argument("--port", type=int, default=18443)
args = parser.parse_args()
root = pathlib.Path(args.directory)
if args.port != 18443:
    raise SystemExit(64)

class Witness(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def do_GET(self):
        if self.path != "/v1/portal/recovery-witness":
            self.send_error(404)
            return
        mode = (root / "scenario").read_text().strip()
        with (root / "requests.log").open("a") as log:
            log.write(mode + "\n")
        body = (root / "receipt.json").read_bytes()
        status = 200
        mime = "application/json; charset=utf-8"
        headers = {}
        if mode == "malformed":
            body = b'{"schema":'
        elif mode == "oversized":
            body = b'Z' * 5000
        elif mode == "wrong-mime":
            mime = "text/plain"
        elif mode == "redirect":
            status = 302
            headers["Location"] = "https://different.invalid/v1/portal/recovery-witness"
        elif mode == "unavailable":
            status = 503
        elif mode == "timeout":
            time.sleep(2.5)
        elif mode == "forged":
            body = body.replace(b'"sequence":13', b'"sequence":14')
        elif mode != "ok":
            status = 500

        try:
            self.send_response(status)
            self.send_header("Content-Type", mime)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            for key, value in headers.items():
                self.send_header(key, value)
            self.end_headers()
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
            pass

    def log_message(self, format, *args):
        pass

server = http.server.ThreadingHTTPServer(("127.0.0.1",args.port),Witness)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.minimum_version = ssl.TLSVersion.TLSv1_2
context.load_cert_chain(args.cert,args.key)
server.socket=context.wrap_socket(server.socket,server_side=True)
server.serve_forever()
