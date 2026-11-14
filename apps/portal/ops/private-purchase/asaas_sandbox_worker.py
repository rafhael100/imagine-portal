"""Host-side sandbox broker. No production endpoint, real identity or notifications.
Run --once for operator-requested reconciliation. No background polling by default.
"""
import argparse,datetime,decimal,fcntl,json,os,pathlib,re,stat,subprocess,urllib.request,urllib.parse
ROOT=pathlib.Path(__file__).resolve().parent
BASE='https://api-sandbox.asaas.com/v3'
FIXTURE_DOCUMENT='24971563792' # Official API example: docs.asaas.com/reference/criar-novo-cliente
FIXTURE_NAME='Imagine Sandbox - Pagador ficticio'
class Unconfirmed(Exception): pass
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): return None
class Asaas:
    def __init__(self,key):
        if not re.fullmatch(r'\$aact_hmlg_[^\s]{30,4000}',key): raise Unconfirmed('Sandbox key required')
        self.key=key
    def call(self,method,path,body=None):
        if not path.startswith('/') or '://' in path: raise Unconfirmed('Invalid resource')
        req=urllib.request.Request(BASE+path,data=None if body is None else json.dumps(body).encode(),method=method,headers={'access_token':self.key,'Content-Type':'application/json','User-Agent':'ImaginePortal-Sandbox/1.0'})
        try:
            with urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect).open(req,timeout=20) as r:
                raw=r.read(1048577)
                if len(raw)>1048576 or r.status not in (200,201): raise Unconfirmed('Response unavailable')
                return json.loads(raw)
        except Exception: raise Unconfirmed('Provider operation not confirmed') from None
    def find(self,kind,reference):
        data=self.call('GET','/'+kind+'?'+urllib.parse.urlencode({'externalReference':reference,'limit':100}))
        if data.get('hasMore') or not isinstance(data.get('data'),list): raise Unconfirmed('Ambiguous provider list')
        rows=data['data']
        if len(rows)>1 or any(x.get('externalReference')!=reference for x in rows): raise Unconfirmed('Ambiguous provider reference')
        return rows[0] if rows else None

def invoice_url(value):
    if not isinstance(value,str): return None
    try:
        u=urllib.parse.urlsplit(value)
        if u.scheme=='https' and u.netloc=='sandbox.asaas.com' and re.fullmatch(r'/i/[A-Za-z0-9_-]+',u.path) and not u.query and not u.fragment:return value
    except ValueError: pass
    return None

def verify_payment(p,row):
    try:
        cents=decimal.Decimal(str(p['value']))*100
        if not cents.is_finite() or cents!=row['amount_cents']: raise ValueError()
        if p.get('customer')!=row['customer_ref'] or p.get('externalReference')!='imagine:'+row['order_id']+':initial' or not re.fullmatch(r'pay_[A-Za-z0-9_]{6,64}',p.get('id','')): raise ValueError()
        if row.get('payment_ref') and p['id']!=row['payment_ref']:raise ValueError()
        if not isinstance(p.get('status'),str) or not re.fullmatch('[A-Z_]{1,64}',p['status']):raise ValueError()
    except Exception: raise Unconfirmed('Payment financial mismatch') from None
    # Match the independent PSP settlement boundary (F390): CONFIRMED is
    # authorization/confirmation, not a RECEIVED, non-reversed settlement.
    reversed_status=(
        p['status'] in ('REFUNDED','REFUND_REQUESTED','REFUND_IN_PROGRESS',
                        'CHARGEBACK_REQUESTED','CHARGEBACK_DISPUTE',
                        'AWAITING_CHARGEBACK_REVERSAL','DELETED')
        or p.get('deleted') is True
        or p.get('refundedDate') is not None
        or bool(p.get('refunds'))
        or p.get('chargeback') not in (None,False)
    )
    if p.get('subscription') is not None or p.get('installment') is not None or p.get('currency','BRL')!='BRL':
        raise Unconfirmed('Payment cannot fund a one-off installation')
    return 'reversed' if reversed_status else 'approved' if p['status']=='RECEIVED' else 'pending'

class Worker:
    def __init__(self,db,api):self.db=db;self.api=api
    def process(self,row):
        row=dict(row);oid=row['order_id']
        if not re.fullmatch(r'TEST-[a-f0-9]{32}',oid) or type(row['amount_cents']) is not int or not 1<=row['amount_cents']<=100000000:raise Unconfirmed('Invalid intent')
        reference='imagine:'+oid+':initial';cref='imagine:'+oid+':customer'
        def patch(**changes):
            self.db.patch(oid,changes);row.update(changes)
        if not row.get('customer_ref'):
            customer=self.api.find('customers',cref)
            if customer is None:
                if row['state']!='queued':return # uncertain POST: never blindly repeat
                patch(state='customer_creating')
                customer=self.api.call('POST','/customers',{'name':FIXTURE_NAME,'cpfCnpj':FIXTURE_DOCUMENT,'notificationDisabled':True,'externalReference':cref})
            if not re.fullmatch(r'cus_[A-Za-z0-9]+',customer.get('id','')) or customer.get('externalReference')!=cref or customer.get('notificationDisabled') is not True or customer.get('name')!=FIXTURE_NAME:raise Unconfirmed('Customer mismatch')
            patch(customer_ref=customer['id'],state='customer_ready')
        payment=self.api.call('GET','/payments/'+row['payment_ref']) if row.get('payment_ref') else self.api.find('payments',reference)
        if payment is None:
            if row['state']!='customer_ready':return # payment_creating remains for reconciliation
            patch(state='payment_creating')
            payment=self.api.call('POST','/payments',{'customer':row['customer_ref'],'billingType':'UNDEFINED','value':row['amount_cents']/100,'dueDate':str(row['due_date']),'description':'Imagine - homologacao sem dinheiro real','externalReference':reference})
        state=verify_payment(payment,row)
        patch(payment_ref=payment['id'],invoice_url=invoice_url(payment.get('invoiceUrl')),provider_status=payment['status'],state=state,sync_requested=False)
        self.db.settle(oid,state)

class Database:
    def sql(self,s):
        # A real LOGIN role in an isolated one-shot psql container replaces the
        # earlier superuser + SET ROLE shortcut. Broker cannot read customer PII,
        # the app's HMAC verifier secret or anyone's credential.
        cmd=['docker','compose','run','--rm','-T','--no-deps','broker',
             '-X','-q','-tA','-v','ON_ERROR_STOP=1',
             '-h','database','-p','5432','-U','purchase_rehearsal_psp',
             '-d','portal_purchase_rehearsal','-c',s]
        try:
            p=subprocess.run(cmd,text=True,cwd=ROOT,capture_output=True,timeout=30,
                             check=False)
        except (OSError,subprocess.TimeoutExpired):
            raise Unconfirmed('Ledger operation unavailable') from None
        if p.returncode: raise Unconfirmed('Ledger operation unavailable')
        return p.stdout.strip()
    def rows(self):
        return json.loads(self.sql("SELECT COALESCE(json_agg(t),'[]'::json) FROM (SELECT * FROM purchase_rehearsal.sandbox_payment_intents WHERE sync_requested ORDER BY updated_at LIMIT 10) t;"))
    def patch(self,oid,changes):
        allowed={'state','customer_ref','payment_ref','invoice_url','provider_status','sync_requested'}
        if set(changes)-allowed or not re.fullmatch(r'TEST-[a-f0-9]{32}',oid):raise Unconfirmed('Invalid ledger update')
        def lit(v):
            if v is None:return 'NULL'
            if isinstance(v,bool):return 'true' if v else 'false'
            return "'"+str(v).replace("'","''")+"'"
        fields=','.join(k+'='+lit(v) for k,v in changes.items())
        self.sql('UPDATE purchase_rehearsal.sandbox_payment_intents SET '+fields+",updated_at=now() WHERE order_id='"+oid+"';")
    def settle(self,oid,state):
        if not re.fullmatch(r'TEST-[a-f0-9]{32}',oid):raise Unconfirmed('Invalid order')
        if state=='approved':
            # A prior refund/chargeback hold must never be cleared by a later
            # stale RECEIVED response. A human/ledger gate owns resolution.
            self.sql("UPDATE purchase_rehearsal.customer_orders o SET payment='approved',revision=revision+1 WHERE id='"+oid+"' AND payment_source='asaas_sandbox' AND NOT payment_hold AND payment<>'approved' AND EXISTS(SELECT FROM purchase_rehearsal.sandbox_payment_intents i WHERE i.order_id=o.id AND i.state='approved');")
        elif state=='reversed':
            # Preserve the existing briefing and foreign-key history; moving
            # payment to declined would violate the historical briefing CHECK.
            # A persistent financial hold blocks further fulfilment instead.
            self.sql("UPDATE purchase_rehearsal.customer_orders o SET payment_hold=true,revision=revision+1 WHERE id='"+oid+"' AND payment_source='asaas_sandbox' AND NOT payment_hold AND EXISTS(SELECT FROM purchase_rehearsal.sandbox_payment_intents i WHERE i.order_id=o.id AND i.state='reversed');")

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--once',action='store_true',required=True)
    parser.parse_args()
    # A normal process startup never writes to an external payment provider.
    # Only a private operator-controlled staging invocation may make a POST.
    if (os.environ.get('APP_ENV')!='staging' or
        os.environ.get('PORTAL_SANDBOX_BROKER_ONCE')!='1' or
        os.environ.get('GITHUB_ACTIONS')=='true'):
        raise SystemExit('NO-GO: explicit staging operator opt-in required; CI/production denied')
    config=ROOT/'.private/asaas-sandbox.json'
    try:
        info=config.lstat()
        if (stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode) or
            stat.S_IMODE(info.st_mode)!=0o600 or info.st_uid!=os.geteuid() or
            info.st_size>8192):
            raise ValueError()
        cfg=json.loads(config.read_text())
    except (OSError,ValueError,TypeError):
        raise SystemExit('NO-GO: private sandbox configuration inaccessible or unsafe') from None
    if cfg.get('environment')!='sandbox':raise SystemExit('NO-GO: only Sandbox is supported')
    lock_path=ROOT/'.private/asaas-worker.lock'
    # No symlink following for lock file; do not assume the private directory
    # is safe until it has been reviewed by the independent VPS preflight.
    flags=os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW
    fd=os.open(lock_path,flags,0o600)
    try:
        with os.fdopen(fd,'w') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            db=Database();worker=Worker(db,Asaas(cfg['api_key']))
            for row in db.rows():
                try:worker.process(row);print('Sandbox intent reconciled')
                except Unconfirmed:print('Sandbox intent requires reconciliation; no blind retry')
    except OSError:
        raise SystemExit('NO-GO: worker lock unavailable') from None
