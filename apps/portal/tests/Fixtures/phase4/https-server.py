"""CI TLS harness invoking the actual Performance service composition in another process/DB."""
import http.server,ssl,os,subprocess,json,hashlib
from pathlib import Path
assert os.environ.get('CI')=='true' and os.environ.get('ADMIN_PHASE4_PG_CI')=='1'
root=Path(os.environ['ADMIN_PHASE4_TLS'])
class Handler(http.server.BaseHTTPRequestHandler):
 def log_message(self,*args): pass
 def do_POST(self):
  length=self.headers.get('Content-Length','')
  if self.path!='/v1/admin/read' or not length.isdigit() or not 0<int(length)<=16384 or 'Cookie' in self.headers or 'Authorization' in self.headers:
   self.send_error(403);return
  wire=self.rfile.read(int(length))
  env=dict(os.environ,PHASE4_TLS_PEER_SHA256=hashlib.sha256(self.connection.getpeercert(binary_form=True)).hexdigest())
  try:
   p=subprocess.run(['php',str(Path(__file__).with_name('receiver.php'))],input=wire,capture_output=True,timeout=8,env=env,check=True)
   r=json.loads(p.stdout);body=json.dumps(r['body']).encode()
   self.send_response(r['status']);self.send_header('Content-Type','application/json');self.send_header('Cache-Control','no-store');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
  except Exception:self.send_error(503)
server=http.server.ThreadingHTTPServer(('172.30.250.2',19444),Handler)
context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.minimum_version=ssl.TLSVersion.TLSv1_2
context.load_cert_chain(root/'server.pem',root/'server.key');context.load_verify_locations(root/'ca.pem');context.verify_mode=ssl.CERT_REQUIRED
server.socket=context.wrap_socket(server.socket,server_side=True)
server.serve_forever()
