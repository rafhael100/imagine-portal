"""Confirm only an operator-owned synthetic smoke payment in Asaas sandbox.
Never accepts payment IDs, amounts or credentials from command line.
"""
import argparse,fcntl,json,os,re,stat
from asaas_sandbox_worker import Asaas,Database,Worker,ROOT,verify_payment

def confirm(product,scenario='standard',provider_only=False):
    if os.environ.get('APP_ENV')=='production':raise RuntimeError('Production is unsupported')
    private=ROOT/'.private'
    cfg_file=private/'asaas-sandbox.json'
    state_file=private/('sandbox-smoke.json' if product=='onepage' else 'sandbox-smoke-'+product+'.json')
    if scenario=='webhook':state_file=private/('sandbox-smoke-webhook-'+product+'.json')
    for path in [cfg_file,state_file]:
        if stat.S_IMODE(path.stat().st_mode)!=0o600:raise RuntimeError('Private mode must be 600')
    cfg=json.loads(cfg_file.read_text())
    if cfg.get('environment')!='sandbox':raise RuntimeError('Sandbox only')
    state=json.loads(state_file.read_text());oid=state['id']
    if not re.fullmatch(r'TEST-[a-f0-9]{32}',oid):raise RuntimeError('Synthetic order required')
    with open(private/'asaas-worker.lock','w') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        db=Database();rows=json.loads(db.sql("SELECT COALESCE(json_agg(i),'[]'::json) FROM purchase_rehearsal.sandbox_payment_intents i WHERE order_id='"+oid+"';"))
        if len(rows)!=1 or not rows[0].get('payment_ref'):raise RuntimeError('Create and reconcile the requested sandbox charge first')
        row=rows[0];api=Asaas(cfg['api_key']);payment=api.call('GET','/payments/'+row['payment_ref'])
        current=verify_payment(payment,row)
        if current=='pending':api.call('POST','/sandbox/payment/'+row['payment_ref']+'/confirm',{})
        elif current!='approved':raise RuntimeError('Reversed payment cannot be confirmed')
        if provider_only:
            print('PASS sandbox provider confirmation requested; local approval awaits webhook')
            return
        Worker(db,api).process(row)
        print('PASS synthetic '+product+' payment confirmed and reconciled in sandbox')
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--product',choices=['onepage','landing-pages','performance'],required=True)
    parser.add_argument('--scenario',choices=['standard','webhook'],default='standard');parser.add_argument('--provider-only',action='store_true')
    args=parser.parse_args();os.umask(0o077);confirm(args.product,args.scenario,args.provider_only)
