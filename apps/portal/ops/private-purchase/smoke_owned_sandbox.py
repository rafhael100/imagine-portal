"""Operator-only HTTP smoke on the dedicated loopback preview; no real identities.
Keeps the generated demo password private and never emits it to stdout.
"""
import argparse,http.cookiejar,json,os,pathlib,re,secrets,urllib.parse,urllib.request
ROOT=pathlib.Path(__file__).resolve().parent
BASE='http://127.0.0.1:18083/_preview/portal'
STATE=ROOT/'.private/sandbox-smoke.json'
class Browser:
    def __init__(self):self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def get(self,path):
        with self.http.open(BASE+path,timeout=20) as r:return r.geturl(),r.read().decode()
    def post(self,path,data):
        with self.http.open(BASE+path,urllib.parse.urlencode(data).encode(),timeout=20) as r:return r.geturl(),r.read().decode()
    @staticmethod
    def field(html,name):
        m=re.search(r'name="'+re.escape(name)+r'"[^>]*value="([^"]*)"',html)
        if not m:raise RuntimeError('Required form field unavailable')
        return m[1]
    def login(self,state):
        _,page=self.get('');self.post('/login',{'_token':self.field(page,'_token'),'username':state['username'],'password':state['password']})
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('action',choices=['prepare','verify','verify-paid','briefing']);args=parser.parse_args()
    os.umask(0o077);b=Browser()
    if args.action=='prepare' and not STATE.exists():
        state={'username':'demo-sbx-'+secrets.token_hex(6),'password':secrets.token_urlsafe(24)}
        _,page=b.get('/register');b.post('/register',{'_token':b.field(page,'_token'),'username':state['username'],'password':state['password'],'password_confirmation':state['password'],'name':'Cliente ficticio sandbox','company':'Empresa demonstracao'})
        _,page=b.get('/shop?product=onepage')
        url,page=b.post('/shop/orders',{'_token':b.field(page,'_token'),'request_key':b.field(page,'request_key'),'product':'onepage','offer_version':b.field(page,'offer_version'),'offer_ack':'1'})
        oid=url.rsplit('/',1)[1]
        if not re.fullmatch(r'TEST-[a-f0-9]{32}',oid):raise RuntimeError('Owned order not created')
        state['id']=oid
        fd=os.open(STATE,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        with os.fdopen(fd,'w') as f:json.dump(state,f)
    else:state=json.loads(STATE.read_text());b.login(state)
    path='/shop/orders/'+state['id'];_,page=b.get(path)
    if args.action=='prepare':
        if 'name="outcome"' in page:
            _,page=b.post(path+'/sandbox',{'_token':b.field(page,'_token'),'revision':b.field(page,'revision'),'sandbox_ack':'1'})
        if 'name="outcome"' in page:raise RuntimeError('Manual approval not blocked')
        print('PASS owned synthetic order requested Asaas sandbox; private demo credentials saved')
    elif args.action=='verify':
        if 'https://sandbox.asaas.com/i/' not in page:raise RuntimeError('Sandbox invoice not yet available')
        if 'name="outcome"' in page:raise RuntimeError('Manual approval still enabled')
        print('PASS owned Portal order displays Asaas sandbox invoice; manual outcome blocked')
    else:
        if 'name="business"' not in page:raise RuntimeError('Provider-confirmed payment not yet available')
        print('PASS provider-confirmed payment unlocks owned briefing')
        if args.action=='briefing':
            _,page=b.post(path+'/briefing',{'_token':b.field(page,'_token'),'revision':b.field(page,'revision'),**{x:'Exemplo sandbox sem dados pessoais' for x in ['business','goal','audience','materials']}})
            if 'Briefing salvo no Portal de teste' not in page:raise RuntimeError('Briefing not saved')
            print('PASS owned briefing saved after provider confirmation')
