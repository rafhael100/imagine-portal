"""Private synthetic first-advance report fixture. No real Ads evidence or PSP calls."""
import argparse,json,pathlib,re,subprocess
from smoke_owned_sandbox import Browser,ROOT
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('action',choices=['submit','dispute','correct','acknowledge','verify']);args=p.parse_args()
    state=json.loads((ROOT/'.private/sandbox-smoke-performance.json').read_text());oid=state['id']
    if not re.fullmatch(r'TEST-[a-f0-9]{32}',oid):raise RuntimeError('Synthetic fixture required')
    b=Browser();b.login(state);path='/work/'+oid;_,page=b.get(path)
    if args.action in ('submit','correct'):
        data={'period':'2026-11','actual':{'google_ads':100000 if args.action=='submit' else 50000},'evidence_ref':'SIM-RELATORIO-001' if args.action=='submit' else 'SIM-CORRECAO-002'}
        result=subprocess.run(['docker','compose','exec','-T','application','php','tools/operator_performance_report.php',oid],input=json.dumps(data),text=True,capture_output=True,cwd=ROOT,timeout=20)
        if result.returncode:raise RuntimeError('Operator fixture failed; no private output displayed')
        print('PASS synthetic operator report '+args.action+' persisted; no PSP operation')
    elif args.action in ('dispute','acknowledge'):
        _,page=b.post(path+'/performance-response',{'_token':b.field(page,'_token'),'revision':b.field(page,'revision'),'version':b.field(page,'version'),'decision':'disputed' if args.action=='dispute' else 'acknowledged','note':'Conferir gasto ficticio' if args.action=='dispute' else '', 'report_ack':'1'})
        expected='Correção solicitada' if args.action=='dispute' else 'Conferido pelo cliente; aguarda financeiro'
        if expected not in page:raise RuntimeError('Owned report response unavailable')
        print('PASS authenticated owner report '+args.action+' saved')
    else:
        if not all(text in page for text in ['SIM-RELATORIO-001','SIM-CORRECAO-002','Crédito em análise','Conferido pelo cliente; aguarda financeiro','R$ 40,00']):raise RuntimeError('Report history or review unavailable')
        print('PASS owned report shows immutable correction history, R40 credit under review and customer confirmation; no automatic credit')
