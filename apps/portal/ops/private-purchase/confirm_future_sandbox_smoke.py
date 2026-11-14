"""Provider-only confirmation of a private synthetic future invoice.
No local approval writes: the authenticated webhook must reconcile the result.
"""
import argparse,json,os,re,stat
from asaas_sandbox_worker import ROOT,Database,Asaas,verify_payment
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--product',choices=['onepage','landing-pages'],required=True);parser.add_argument('--charge',choices=['setup-2','setup-3','monthly'],required=True);args=parser.parse_args()
    if os.environ.get('APP_ENV')=='production':raise RuntimeError('Sandbox only')
    cfg_path=ROOT/'.private/asaas-sandbox.json';state_path=ROOT/('.private/sandbox-smoke.json' if args.product=='onepage' else '.private/sandbox-smoke-landing-pages.json')
    for path in [cfg_path,state_path]:
        if stat.S_IMODE(path.stat().st_mode)!=0o600:raise RuntimeError('Private mode required')
    cfg=json.loads(cfg_path.read_text());state=json.loads(state_path.read_text());oid=state['id']
    if cfg.get('environment')!='sandbox' or not re.fullmatch(r'TEST-[a-f0-9]{32}',oid):raise RuntimeError('Synthetic sandbox required')
    db=Database();api=Asaas(cfg['api_key'])
    rows=json.loads(db.sql("SELECT COALESCE(json_agg(t),'[]'::json) FROM (SELECT f.*,i.customer_ref FROM purchase_rehearsal.sandbox_future_billing f JOIN purchase_rehearsal.sandbox_payment_intents i ON i.order_id=f.order_id WHERE f.order_id='"+oid+"' AND f.charge_key='"+args.charge+"') t;"))
    if len(rows)!=1 or not rows[0].get('provider_ref'):raise RuntimeError('Future billing not reconciled')
    row=rows[0]
    if args.charge=='monthly':
        page=api.call('GET','/subscriptions/'+row['provider_ref']+'/payments?limit=100')
        if page.get('hasMore') or not isinstance(page.get('data'),list):raise RuntimeError('Invoice page needs review')
        invoices=[p for p in page['data'] if p.get('dueDate')==row['due_date'] and p.get('deleted') is not True]
        if len(invoices)!=1:raise RuntimeError('Original D90 invoice unavailable')
        payment=invoices[0]
        if payment.get('subscription')!=row['provider_ref']:raise RuntimeError('Subscription mismatch')
        status=verify_payment(payment|{'externalReference':'imagine:'+oid+':initial'},row|{'payment_ref':None})
    else:
        payment=api.call('GET','/payments/'+row['provider_ref'])
        if payment.get('dueDate')!=row['due_date']:raise RuntimeError('Due date mismatch')
        status=verify_payment(payment,row|{'payment_ref':row['provider_ref']},args.charge)
    if status=='pending':api.call('POST','/sandbox/payment/'+payment['id']+'/confirm',{})
    elif status!='approved':raise RuntimeError('Reversal must not be confirmed')
    print('PASS future sandbox provider confirmation requested; local approval awaits webhook')
