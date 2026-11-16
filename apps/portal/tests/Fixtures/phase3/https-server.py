"""Runner-only mTLS HTTP transport: no plaintext backend listener or production route."""
import http.server, ssl, os, subprocess, json
from pathlib import Path
assert os.environ.get('CI') == 'true' and os.environ.get('ADMIN_PHASE3_HTTPS_CI') == '1'
root = Path(os.environ['ADMIN_PHASE3_TLS'])
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_POST(self):
        cert = self.connection.getpeercert()
        if self.path != '/v1/admin/read' or cert.get('serialNumber') != (root/'client.serial').read_text().strip():
            self.send_error(403); return
        length = self.headers.get('Content-Length','')
        if not length.isdigit() or int(length)>16384:
            self.send_error(413); return
        env = dict(os.environ, ADMIN_CI_VERIFIED_PEER='portal_service')
        try:
            p = subprocess.run(['php',str(Path(__file__).with_name('receiver.php'))],input=self.rfile.read(int(length)),capture_output=True,timeout=8,env=env,check=True)
            r = json.loads(p.stdout)
            body = json.dumps(r['body']).encode()
            self.send_response(r['status']); self.send_header('Content-Type','application/json'); self.send_header('Cache-Control','no-store'); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)
        except Exception:
            self.send_error(503)
server = http.server.ThreadingHTTPServer(('172.30.250.1',19443),Handler)
context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.minimum_version=ssl.TLSVersion.TLSv1_2
context.load_cert_chain(root/'server.pem',root/'server.key');context.load_verify_locations(root/'ca.pem');context.verify_mode=ssl.CERT_REQUIRED
server.socket=context.wrap_socket(server.socket,server_side=True)
server.serve_forever()
