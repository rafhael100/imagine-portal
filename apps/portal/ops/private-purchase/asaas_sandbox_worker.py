"""Host-side sandbox broker. No production endpoint, real identity or notifications.
Run --once for operator-requested reconciliation. No background polling by default.
"""
import argparse,calendar,datetime,decimal,fcntl,json,os,pathlib,re,stat,subprocess,urllib.request,urllib.parse,urllib.error
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
            with urllib.request.build_opener(NoRedirect).open(req,timeout=20) as r:
                raw=r.read(1048577)
                if len(raw)>1048576 or r.status not in (200,201): raise Unconfirmed('Response unavailable')
                return json.loads(raw)
        except urllib.error.HTTPError as e:
            codes=[]
            try:
                errors=json.loads(e.read(8192)).get('errors',[])
                codes=[x['code'] for x in errors if isinstance(x,dict) and isinstance(x.get('code'),str) and re.fullmatch('[A-Za-z0-9_]{1,64}',x['code'])][:3]
            except Exception:pass
            raise Unconfirmed('Provider HTTP '+str(e.code)+' '+','.join(codes)) from None
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

def verify_payment(p,row,reference_key='initial'):
    try:
        if reference_key not in ('initial','setup-2','setup-3'):raise ValueError()
        cents=decimal.Decimal(str(p['value']))*100
        if not cents.is_finite() or cents!=row['amount_cents']: raise ValueError()
        if p.get('customer')!=row['customer_ref'] or p.get('externalReference')!='imagine:'+row['order_id']+':'+reference_key or not re.fullmatch(r'pay_[A-Za-z0-9_]{1,100}',p.get('id','')): raise ValueError()
        if row.get('payment_ref') and p['id']!=row['payment_ref']:raise ValueError()
        if not isinstance(p.get('status'),str) or not re.fullmatch('[A-Z_]{1,64}',p['status']):raise ValueError()
    except Exception: raise Unconfirmed('Payment financial mismatch') from None
    paid=p['status']=='RECEIVED' or (p['status']=='CONFIRMED' and p.get('billingType')=='CREDIT_CARD')
    reversed_status=p['status'] in ('REFUNDED','CHARGEBACK_REQUESTED','CHARGEBACK_DISPUTE','AWAITING_CHARGEBACK_REVERSAL') or p.get('deleted') is True
    return 'reversed' if reversed_status else 'approved' if paid else 'pending'

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
                if row['state']!='queued':
                    patch(sync_requested=False);return # operator/customer must request another reconciliation
                patch(state='customer_creating')
                customer=self.api.call('POST','/customers',{'name':FIXTURE_NAME,'cpfCnpj':FIXTURE_DOCUMENT,'notificationDisabled':True,'externalReference':cref})
            if not re.fullmatch(r'cus_[A-Za-z0-9]+',customer.get('id','')) or customer.get('externalReference')!=cref or customer.get('notificationDisabled') is not True or customer.get('name')!=FIXTURE_NAME:raise Unconfirmed('Customer mismatch')
            patch(customer_ref=customer['id'],state='customer_ready')
        payment=self.api.call('GET','/payments/'+row['payment_ref']) if row.get('payment_ref') else self.api.find('payments',reference)
        if payment is None:
            if row['state']!='customer_ready':
                patch(sync_requested=False);return # never repeat an uncertain write
            patch(state='payment_creating')
            payment=self.api.call('POST','/payments',{'customer':row['customer_ref'],'billingType':'UNDEFINED','value':row['amount_cents']/100,'dueDate':str(row['due_date']),'description':'Imagine - homologacao sem dinheiro real','externalReference':reference})
        state=verify_payment(payment,row)
        patch(payment_ref=payment['id'],invoice_url=invoice_url(payment.get('invoiceUrl')),provider_status=payment['status'],state=state,sync_requested=False)
        self.db.settle(oid,state)

class Database:
    def sql(self,s):
        cmd=['docker','compose','exec','-T','database','psql','-X','-q','-tA','-v','ON_ERROR_STOP=1','-U','postgres','-d','portal_purchase_rehearsal']
        p=subprocess.run(cmd,input='SET ROLE purchase_rehearsal_psp;\n'+s,text=True,cwd=ROOT,capture_output=True,timeout=20)
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
        if state=='approved':
            self.sql("UPDATE purchase_rehearsal.customer_orders o SET payment='approved',revision=revision+1 WHERE id='"+oid+"' AND payment_source='asaas_sandbox' AND payment<>'approved' AND EXISTS(SELECT FROM purchase_rehearsal.sandbox_payment_intents i WHERE i.order_id=o.id AND i.state='approved');")

class FutureDatabase(Database):
    def rows(self):
        return json.loads(self.sql("SELECT COALESCE(json_agg(t),'[]'::json) FROM (SELECT f.*,i.customer_ref FROM purchase_rehearsal.sandbox_future_billing f JOIN purchase_rehearsal.sandbox_payment_intents i ON i.order_id=f.order_id WHERE f.sync_requested AND i.state='approved' ORDER BY f.updated_at LIMIT 10) t;"))
    def patch_future(self,oid,key,changes):
        allowed={'state','provider_ref','invoice_url','provider_status','sync_requested'}
        if set(changes)-allowed or not re.fullmatch(r'TEST-[a-f0-9]{32}',oid) or key not in ('setup-2','setup-3','monthly'):raise Unconfirmed('Invalid future ledger update')
        def lit(v):
            if v is None:return 'NULL'
            if isinstance(v,bool):return 'true' if v else 'false'
            return "'"+str(v).replace("'","''")+"'"
        fields=','.join(k+'='+lit(v) for k,v in changes.items())
        self.sql('UPDATE purchase_rehearsal.sandbox_future_billing SET '+fields+",updated_at=now() WHERE order_id='"+oid+"' AND charge_key='"+key+"';")

class FutureWorker:
    def __init__(self,db,api):self.db=db;self.api=api
    def process(self,row):
        oid=row['order_id'];key=row['charge_key'];monthly=key=='monthly'
        if not re.fullmatch(r'TEST-[a-f0-9]{32}',oid) or key not in ('setup-2','setup-3','monthly') or type(row['amount_cents']) is not int or not 1<=row['amount_cents']<=100000000 or not re.fullmatch(r'cus_[A-Za-z0-9_]{1,100}',row.get('customer_ref','')):raise Unconfirmed('Invalid future intent')
        due=str(row['due_date']);datetime.date.fromisoformat(due)
        reference='imagine:'+oid+':'+key;kind='subscriptions' if monthly else 'payments'
        obj=self.api.call('GET','/'+kind+'/'+row['provider_ref']) if row.get('provider_ref') else self.api.find(kind,reference)
        if obj is None:
            if row['state']!='queued':
                self.db.patch_future(oid,key,{'sync_requested':False});return
            self.db.patch_future(oid,key,{'state':'payment_creating'})
            payload={'customer':row['customer_ref'],'billingType':'UNDEFINED','value':row['amount_cents']/100,'description':'Imagine - homologacao sem dinheiro real','externalReference':reference}
            payload.update({'cycle':'MONTHLY','nextDueDate':due} if monthly else {'dueDate':due})
            obj=self.api.call('POST','/'+kind,payload)
        if monthly:
            try:
                if obj.get('customer')!=row['customer_ref'] or obj.get('externalReference')!=reference or not re.fullmatch(r'sub_[A-Za-z0-9_]{1,100}',obj.get('id','')) or (row.get('provider_ref') and obj['id']!=row['provider_ref']):raise ValueError()
                if decimal.Decimal(str(obj['value']))*100!=row['amount_cents'] or obj.get('cycle')!='MONTHLY' or obj.get('status') not in ('ACTIVE','INACTIVE','EXPIRED'):raise ValueError()
                # Before the first due date the provider must preserve the accepted D90 anchor.
                if datetime.date.today()<datetime.date.fromisoformat(due) and obj.get('nextDueDate')!=due:
                    first=datetime.date.fromisoformat(due);year=first.year+(first.month==12);month=first.month%12+1
                    next_due=first.replace(year=year,month=month,day=min(first.day,calendar.monthrange(year,month)[1])).isoformat()
                    if obj.get('nextDueDate')!=next_due:raise ValueError()
                    # Asaas advances nextDueDate when it generates the first invoice.
                    # Confirm D90 on that actual invoice rather than changing the contract.
                    invoices=self.api.call('GET','/subscriptions/'+obj['id']+'/payments?limit=100')
                    if invoices.get('hasMore') or not isinstance(invoices.get('data'),list):raise ValueError()
                    initial=[p for p in invoices['data'] if p.get('dueDate')==due and p.get('deleted') is not True]
                    if len(initial)!=1:raise ValueError()
                    invoice=initial[0]
                    if invoice.get('subscription')!=obj['id'] or invoice.get('customer')!=row['customer_ref'] or decimal.Decimal(str(invoice['value']))*100!=row['amount_cents']:raise ValueError()
            except Exception:raise Unconfirmed('Subscription terms mismatch') from None
            state='active' if obj['status']=='ACTIVE' else 'inactive'
        else:
            if obj.get('dueDate')!=due:raise Unconfirmed('Future due date mismatch')
            state=verify_payment(obj,row|{'payment_ref':row.get('provider_ref')},key)
        self.db.patch_future(oid,key,{'provider_ref':obj['id'],'provider_status':obj['status'],'invoice_url':None if monthly else invoice_url(obj.get('invoiceUrl')),'state':state,'sync_requested':False})

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--once',action='store_true',required=True);args=parser.parse_args()
    if os.environ.get('APP_ENV')=='production':raise SystemExit('Production is not supported')
    config=ROOT/'.private/asaas-sandbox.json'
    if stat.S_IMODE(config.stat().st_mode)!=0o600:raise SystemExit('Private configuration mode must be 600')
    cfg=json.loads(config.read_text())
    if cfg.get('environment')!='sandbox':raise SystemExit('Only sandbox is supported')
    with open(ROOT/'.private/asaas-worker.lock','w') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        db=Database();worker=Worker(db,Asaas(cfg['api_key']))
        for row in db.rows():
            try:worker.process(row);print('Sandbox intent reconciled')
            except Unconfirmed as e:
                db.patch(row['order_id'],{'sync_requested':False})
                print('Sandbox intent requires reconciliation; no blind retry: '+str(e))

        future_db=FutureDatabase();future_worker=FutureWorker(future_db,Asaas(cfg['api_key']))
        for row in future_db.rows():
            try:future_worker.process(row);print('Sandbox future billing reconciled')
            except (Unconfirmed,ValueError) as e:
                future_db.patch_future(row['order_id'],row['charge_key'],{'sync_requested':False})
                print('Sandbox future billing requires reconciliation; no blind retry')
