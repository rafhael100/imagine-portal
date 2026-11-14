"""Operator-only sandbox webhook setup; never logs API key or webhook token."""
import argparse,json,os,re,secrets,stat,urllib.request,urllib.error
from asaas_sandbox_worker import ROOT,Asaas,NoRedirect
from asaas_sandbox_webhook import EVENTS,PATH
URL='https://portal-dev.imaginecomunica.com.br'+PATH
NAME='Imagine Portal - Sandbox event inbox'
CONFIG=ROOT/'.private/asaas-webhook.json'
def read_private(path):
    if stat.S_IMODE(path.stat().st_mode)!=0o600:raise RuntimeError('Private mode required')
    return json.loads(path.read_text())
def write(cfg):
    tmp=CONFIG.with_suffix('.tmp');fd=os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    with os.fdopen(fd,'w') as f:json.dump(cfg,f)
    os.replace(tmp,CONFIG)
def health(cfg):
    req=urllib.request.Request(URL,data=json.dumps({'id':'evt_imagine_probe','event':'IMAGINE_HEALTHCHECK'}).encode(),headers={'asaas-access-token':cfg['auth_token'],'Content-Type':'application/json'})
    with urllib.request.build_opener(NoRedirect).open(req,timeout=20) as response:
        if response.status!=200:raise RuntimeError('Authenticated endpoint unavailable')
    req=urllib.request.Request(URL,data=b'{}',headers={'Content-Type':'application/json','asaas-access-token':'invalid-probe'})
    try:urllib.request.build_opener(NoRedirect).open(req,timeout=20)
    except urllib.error.HTTPError as e:
        if e.code!=401:raise RuntimeError('Invalid token was not rejected')
    else:raise RuntimeError('Endpoint must require the private token')
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('action',choices=['prepare','health','register']);args=p.parse_args();os.umask(0o077)
    if os.environ.get('APP_ENV')=='production':raise SystemExit('Sandbox only')
    if args.action=='prepare' and not CONFIG.exists():write({'environment':'sandbox','auth_token':secrets.token_urlsafe(48),'registration_state':'not_started'})
    cfg=read_private(CONFIG)
    if cfg.get('environment')!='sandbox' or not re.fullmatch(r'[A-Za-z0-9_-]{43,255}',cfg.get('auth_token','')):raise RuntimeError('Sandbox config required')
    if args.action in ('health','register'):health(cfg)
    if args.action=='register':
        key=read_private(ROOT/'.private/asaas-sandbox.json')
        if key.get('environment')!='sandbox':raise RuntimeError('Sandbox API key required')
        api=Asaas(key['api_key']);page=api.call('GET','/webhooks?limit=100')
        if page.get('hasMore') or not isinstance(page.get('data'),list):raise RuntimeError('Webhook list requires review')
        matches=[w for w in page['data'] if w.get('url')==URL or w.get('name')==NAME]
        if len(matches)>1:raise RuntimeError('Ambiguous webhook configuration')
        if matches:w=matches[0]
        else:
            if cfg['registration_state']!='not_started':raise RuntimeError('Uncertain registration: reconcile before another POST')
            cfg['registration_state']='creating';write(cfg)
            w=api.call('POST','/webhooks',{'name':NAME,'url':URL,'email':'rafhael@imaginecomunicacao.com.br','enabled':True,'interrupted':False,'apiVersion':3,'authToken':cfg['auth_token'],'sendType':'SEQUENTIALLY','events':sorted(EVENTS)})
        if w.get('url')!=URL or w.get('enabled') is not True or w.get('interrupted') is not False or w.get('sendType')!='SEQUENTIALLY' or not EVENTS.issubset(set(w.get('events',[]))):raise RuntimeError('Webhook settings mismatch')
        if cfg.get('webhook_id') and cfg['webhook_id']!=w['id']:raise RuntimeError('Webhook identity mismatch')
        cfg.update(webhook_id=w['id'],registration_state='registered');write(cfg)
    print('PASS sandbox webhook '+args.action+'; private token not displayed')
