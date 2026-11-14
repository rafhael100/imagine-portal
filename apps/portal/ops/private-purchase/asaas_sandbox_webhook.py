"""Loopback-only authenticated sandbox inbox. No API key and no payment approval here."""
import hashlib,hmac,http.server,json,os,pathlib,re,stat,subprocess
ROOT=pathlib.Path(__file__).resolve().parent
PATH='/integrations/asaas/sandbox/events'
EVENTS={'PAYMENT_CREATED','PAYMENT_UPDATED','PAYMENT_CONFIRMED','PAYMENT_RECEIVED','PAYMENT_OVERDUE','PAYMENT_DELETED','PAYMENT_RESTORED','PAYMENT_REFUNDED','PAYMENT_PARTIALLY_REFUNDED','PAYMENT_CHARGEBACK_REQUESTED','PAYMENT_CHARGEBACK_DISPUTE','PAYMENT_AWAITING_CHARGEBACK_REVERSAL','SUBSCRIPTION_CREATED','SUBSCRIPTION_UPDATED','SUBSCRIPTION_DELETED','SUBSCRIPTION_INACTIVATED'}
class InvalidEvent(Exception):pass

def parse_event(raw):
    try:
        if not isinstance(raw,bytes) or len(raw)>65536:raise ValueError()
        body=json.loads(raw)
        eid=body['id'];kind=body['event']
        if not isinstance(eid,str) or not 1<=len(eid)<=200 or not eid.isascii() or any(ord(c)<33 for c in eid):raise ValueError()
        if kind not in EVENTS:return None # Ignore new event types; never interrupt provider delivery.
        obj=body['subscription' if kind.startswith('SUBSCRIPTION_') else 'payment']
        resource=obj['id'];prefix='sub' if kind.startswith('SUBSCRIPTION_') else 'pay'
        if not isinstance(resource,str) or not re.fullmatch(prefix+r'_[A-Za-z0-9_]{1,100}',resource):raise ValueError()
        sub=obj.get('subscription')
        if sub is not None and (not isinstance(sub,str) or not re.fullmatch(r'sub_[A-Za-z0-9_]{1,100}',sub)):raise ValueError()
        external=obj.get('externalReference')
        if not isinstance(external,str) or not re.fullmatch(r'imagine:TEST-[a-f0-9]{32}:(initial|setup-2|setup-3|monthly)',external):external=None
        return {'event_hash':hashlib.sha256(eid.encode()).hexdigest(),'event_type':kind,'resource_ref':resource,'subscription_ref':sub,'external_ref':external}
    except Exception:raise InvalidEvent('Invalid event') from None

class Inbox:
    def save(self,event):
        def lit(v):return 'NULL' if v is None else "'"+v.replace("'","''")+"'"
        values=','.join(lit(event[k]) for k in ['event_hash','event_type','resource_ref','subscription_ref','external_ref'])
        # Payload is deliberately not retained: only routing IDs, no personal/financial data.
        sql="BEGIN; SET LOCAL ROLE purchase_rehearsal_hook; SELECT pg_advisory_xact_lock(724116); INSERT INTO purchase_rehearsal.sandbox_webhook_inbox(event_hash,event_type,resource_ref,subscription_ref,external_ref) SELECT "+values+" WHERE (SELECT count(*) FROM purchase_rehearsal.sandbox_webhook_inbox)<10000 ON CONFLICT DO NOTHING; SELECT EXISTS(SELECT FROM purchase_rehearsal.sandbox_webhook_inbox WHERE event_hash="+lit(event['event_hash'])+"); COMMIT;"
        p=subprocess.run(['docker','compose','exec','-T','database','psql','-X','-q','-tA','-v','ON_ERROR_STOP=1','-U','postgres','-d','portal_purchase_rehearsal'],input=sql,cwd=ROOT,capture_output=True,text=True,timeout=10)
        if p.returncode or p.stdout.strip().splitlines()[-1:]!=['t']:raise RuntimeError('Inbox unavailable')

class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args):pass # Never log headers, token or provider payload.
    def setup(self):super().setup();self.connection.settimeout(8)
    def reply(self,code):
        self.send_response(code);self.send_header('Content-Type','application/json');self.send_header('Cache-Control','no-store');self.end_headers();self.wfile.write(b'{"received":'+(b'true' if code==200 else b'false')+b'}')
    def do_GET(self):self.reply(405)
    def do_POST(self):
        if self.path!=PATH:return self.reply(404)
        if getattr(self.server,'expected_host',None) and self.headers.get('Host')!=self.server.expected_host:return self.reply(404)
        supplied=self.headers.get_all('asaas-access-token') or []
        if len(supplied)!=1 or not hmac.compare_digest(supplied[0].encode(),self.server.token.encode()):return self.reply(401)
        lengths=self.headers.get_all('Content-Length') or []
        if len(lengths)!=1 or not lengths[0].isdigit() or self.headers.get('Transfer-Encoding'):return self.reply(400)
        length=int(lengths[0])
        if not 1<=length<=65536:return self.reply(413)
        if self.headers.get_content_type()!='application/json':return self.reply(415)
        try:
            raw=self.rfile.read(length)
            if len(raw)!=length:raise InvalidEvent()
            event=parse_event(raw)
            if event:self.server.inbox.save(event)
        except InvalidEvent:return self.reply(400)
        except Exception:return self.reply(503)
        self.reply(200)

if __name__=='__main__':
    if os.environ.get('APP_ENV')=='production':raise SystemExit('Sandbox only')
    config=ROOT/'.private/asaas-webhook.json'
    if stat.S_IMODE(config.stat().st_mode)!=0o600:raise SystemExit('Private mode must be 600')
    cfg=json.loads(config.read_text());token=cfg.get('auth_token','')
    if cfg.get('environment')!='sandbox' or not re.fullmatch(r'[A-Za-z0-9_-]{43,255}',token):raise SystemExit('Private sandbox token required')
    server=http.server.HTTPServer(('127.0.0.1',18084),Handler);server.token=token;server.expected_host='portal-dev.imaginecomunica.com.br';server.inbox=Inbox();server.serve_forever()
