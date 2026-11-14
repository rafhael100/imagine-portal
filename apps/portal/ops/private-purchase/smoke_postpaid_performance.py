"""Operator-owned synthetic postpaid fixture. Never reads or prints private credentials."""
import argparse,datetime,json,re,subprocess,stat
from smoke_owned_sandbox import Browser,ROOT
from asaas_sandbox_worker import Database,Asaas,verify_payment
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('action',choices=['report','acknowledge','invoice','confirm','verify']);args=p.parse_args()
 state_path=ROOT/'.private/sandbox-smoke-postpaid-performance.json'
 if stat.S_IMODE(state_path.stat().st_mode)!=0o600:raise RuntimeError('Private mode required')
 state=json.loads(state_path.read_text());oid=state['id']
 if not re.fullmatch(r'TEST-[a-f0-9]{32}',oid):raise RuntimeError('Synthetic fixture required')
 b=Browser();b.login(state);path='/work/'+oid;_,page=b.get(path)
 # Whole-month synthetic scenario only; no production/partial-month policy inferred.
 period=datetime.date.today().replace(day=1).isoformat()[:7]
 first=datetime.date.fromisoformat(period+'-01');due=first.replace(year=first.year+(first.month==12),month=first.month%12+1,day=10).isoformat()
 if args.action in ('report','invoice'):
  data={'period':period,'actual':{'google_ads':35000},'evidence_ref':'SIM-POSTPAID-FLOOR-170'} if args.action=='report' else {'action':'invoice','due_date':due}
  r=subprocess.run(['docker','compose','exec','-T','application','php','tools/operator_performance_report.php',oid],input=json.dumps(data),text=True,capture_output=True,cwd=ROOT,timeout=20)
  if r.returncode:raise RuntimeError('Synthetic operator action failed; no private output displayed')
  print('PASS postpaid synthetic '+args.action+' persisted')
 elif args.action=='acknowledge':
  b.post(path+'/performance-response',{'_token':b.field(page,'_token'),'revision':b.field(page,'revision'),'version':b.field(page,'version'),'decision':'acknowledged','report_ack':'1'})
  print('PASS authenticated owner acknowledged monthly report')
 else:
  db=Database();key='performance-'+period
  rows=json.loads(db.sql("SELECT COALESCE(json_agg(t),'[]'::json) FROM (SELECT f.*,i.customer_ref FROM purchase_rehearsal.sandbox_future_billing f JOIN purchase_rehearsal.sandbox_payment_intents i ON i.order_id=f.order_id WHERE f.order_id='"+oid+"' AND f.charge_key='"+key+"') t;"))
  if len(rows)!=1 or rows[0]['amount_cents']!=17000 or not rows[0].get('provider_ref'):raise RuntimeError('Monthly invoice unavailable')
  row=rows[0]
  if args.action=='confirm':
   cfg_path=ROOT/'.private/asaas-sandbox.json'
   if stat.S_IMODE(cfg_path.stat().st_mode)!=0o600:raise RuntimeError('Private mode required')
   cfg=json.loads(cfg_path.read_text())
   if cfg.get('environment')!='sandbox':raise RuntimeError('Sandbox only')
   api=Asaas(cfg['api_key']);payment=api.call('GET','/payments/'+row['provider_ref'])
   if payment.get('billingType')!='BOLETO' or payment.get('dueDate')!=row['due_date']:raise RuntimeError('Invoice terms mismatch')
   status=verify_payment(payment,row|{'payment_ref':row['provider_ref']},key)
   if status=='pending':api.call('POST','/sandbox/payment/'+payment['id']+'/confirm',{})
   elif status!='approved':raise RuntimeError('Reversal cannot be confirmed')
   print('PASS monthly boleto confirmed only at sandbox provider; local state awaits authenticated webhook')
  else:
   if row['state']!='approved' or row['provider_status']!='RECEIVED' or not all(t in page for t in ['SIM-POSTPAID-FLOOR-170','R$ 170,00','Ativação de canais','RECEIVED','Gestão Performance']):raise RuntimeError('Owned monthly flow not yet confirmed')
   print('PASS VPS owned activation490 -> briefing -> actual media350 -> fee170 -> client review -> monthly boleto -> webhook-confirmed payment')
