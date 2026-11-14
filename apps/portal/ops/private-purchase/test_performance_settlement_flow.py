"""Owned/admin report review with no PSP side effects, real HTTP and PostgreSQL."""
import json,os,pathlib,shutil,uuid,time
from playwright.sync_api import sync_playwright,expect
from asaas_sandbox_worker import Database,Unconfirmed,Worker,FutureDatabase,FutureWorker
from test_asaas_sandbox_worker import API as InitialAPI
from test_future_billing import API as MonthlyAPI
BASE=os.environ.get('PURCHASE_PREVIEW_BASE','http://127.0.0.1:18083/_rehearsal/purchase').split('/_rehearsal/')[0]+'/_preview/portal'
fixture=pathlib.Path('/tmp/performance-report-ci.json')
saved=json.loads(pathlib.Path('/tmp/portal-customer-ci.json').read_text())
with sync_playwright() as p:
 b=p.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),headless=True,args=['--no-sandbox']);page=None;pages={}
 def delay(response):
  seconds=int(response.headers.get('retry-after','60'));assert 0<=seconds<=60
  print('Respecting the normal rate-limit Retry-After in the isolated report suite')
  time.sleep(max(1,seconds))
 def login(user):
  global page
  if user in pages:page=pages[user];return
  page=b.new_page()
  for attempt in range(2):
   page.goto(BASE);page.locator('[name=username]').fill(user);page.locator('[name=password]').fill('ci-admin-only-2026' if user=='administrador.teste' else 'ci-owned-customer-only-2026')
   with page.expect_response(lambda response:response.url==BASE+'/login' and response.request.method=='POST') as pending:page.get_by_role('button',name='Entrar',exact=True).click()
   response=pending.value
   if response.status==429 and attempt==0:delay(response);continue
   assert '/client/' in page.url or '/admin/' in page.url,page.locator('body').inner_text()[:300]
   pages[user]=page;return
 def post(url,form):
  for attempt in range(3):
   response=page.request.post(url,form=form)
   if response.status!=429 or attempt==2:return response
   delay(response)
 def token():return page.locator('[name=_token]').first.input_value()
 def revision():return page.locator('[name=revision]').first.input_value()
 def signup(user):
  global page
  page=b.new_page();pages[user]=page;page.goto(BASE+'/register')
  for name,value in [('name','Cliente ficticio Performance'),('company','Empresa demonstracao'),('username',user),('password','ci-owned-customer-only-2026'),('password_confirmation','ci-owned-customer-only-2026')]:page.locator('[name='+name+']').fill(value)
  page.get_by_role('button',name='Criar conta e continuar',exact=True).click();assert '/client/' in page.url,page.locator('body').inner_text()[:300]
 if os.getenv('PERFORMANCE_REPORT_REOPEN')=='1':
  saved['user']=json.loads(fixture.read_text())['user'];login(saved['user'])
  work=json.loads(fixture.read_text())['work'];assert page.goto(work).status==200
  expect(page.get_by_text('Conferido pelo cliente; aguarda financeiro',exact=False)).to_be_visible()
  assert page.locator('input[name=version]').count()==0
  assert 'SIM-CORRECAO-002' in page.content() and 'SIM-RELATORIO-001' in page.content()
  login('administrador.teste');assert page.goto(work).status==200;assert page.locator('form[action$="/performance-report"]').count()==1
  print('PASS Performance report history and customer review survived database/application restart')
 else:
  suffix=uuid.uuid4().hex[:10];saved['user']='demo-perf-'+suffix;outsider='demo-out-'+suffix;signup(outsider);signup(saved['user'])
  psp_count=Database().sql('SELECT count(*) FROM purchase_rehearsal.sandbox_payment_intents;')
  page.goto(BASE+'/shop?product=performance&channel=google_ads');
  expect(page.locator('[data-performance-summary]')).to_contain_text('490,00')
  page.locator('[data-channel=meta_media]').check();expect(page.locator('[data-performance-summary]')).to_contain_text('980,00');page.locator('[data-channel=meta_media]').uncheck()
  page.locator('[name=google_media]').fill('700,00');page.locator('[name=offer_ack]').check();page.get_by_role('button',name='Criar pedido de teste',exact=True).click()
  page.locator('[name=sandbox_ack]').check();page.get_by_role('button',name='Gerar cobrança no Asaas sandbox',exact=True).click()
  oid=page.url.rsplit('/',1)[1];db=Database();row=next(x for x in db.rows() if x['order_id']==oid);assert row['amount_cents']==49000
  initial=InitialAPI();original=initial.call
  def initial_call(method,path,body=None):
   obj=original(method,path,body)
   if method=='POST' and path=='/payments':obj['id']='pay_performance_activation';initial.payment=obj.copy()
   return obj
  initial.call=initial_call;Worker(db,initial).process(row);initial.payment['status']='RECEIVED'
  row=json.loads(db.sql("SELECT row_to_json(t) FROM (SELECT * FROM purchase_rehearsal.sandbox_payment_intents WHERE order_id='"+oid+"') t;"));Worker(db,initial).process(row);page.reload()
  for name in ['business','goal','audience','materials']:page.locator('[name='+name+']').fill('Performance ficticia')
  page.get_by_role('button',name='Salvar briefing de teste',exact=True).click();oid=page.url.rsplit('/',1)[1];work=BASE+'/work/'+oid;page.goto(work)
  assert post(work+'/performance-report',form={'_token':token(),'revision':revision()}).status==403
  login('administrador.teste');page.goto(work)
  form=page.locator('form[action$="/performance-report"]');expect(form).to_be_visible()
  data={'_token':token(),'revision':revision(),'period':'2026-11','evidence_ref':'SIM-RELATORIO-001','actual_google_ads':'1000,00','synthetic_ack':'1'}
  assert post(work+'/performance-report',form=data|{'actual_management_fee_cents':'1'}).status==422
  assert post(work+'/performance-report',form=data|{'actual_meta_ads':'0'}).status==422
  assert post(work+'/performance-report',form=data).status==200
  page.reload();assert 'R$ 200,00' in page.content() and 'Aguardando conferência do cliente' in page.content()
  assert post(work+'/performance-report',form=data).status==409
  assert post(work+'/performance-response',form={'_token':token(),'revision':revision(),'version':'1','decision':'acknowledged','report_ack':'1'}).status==403
  # PSP role cannot edit the report, so gateway events cannot alter its financial terms.
  try:Database().sql("UPDATE purchase_rehearsal.performance_settlement_reports SET report='{}'::jsonb WHERE order_id='"+oid+"';")
  except Unconfirmed:pass
  else:raise AssertionError('PSP must not overwrite report amounts')
  login(outsider);assert page.request.get(work).status==404
  assert post(work+'/performance-response',form={'_token':token(),'revision':'0','version':'1','decision':'acknowledged','report_ack':'1'}).status==404
  login(saved['user']);page.goto(work)
  response={'_token':token(),'revision':revision(),'version':'1','decision':'disputed','note':'Conferir gasto ficticio','report_ack':'1'}
  assert post(work+'/performance-response',form=response|{'adjustment_cents':'0'}).status==422
  assert post(work+'/performance-response',form=response).status==200
  assert post(work+'/performance-response',form=response).status==409
  login('administrador.teste');page.goto(work)
  corrected={'_token':token(),'revision':revision(),'period':'2026-11','evidence_ref':'SIM-CORRECAO-002','actual_google_ads':'500,00','synthetic_ack':'1'}
  assert post(work+'/performance-report',form=corrected).status==200
  page.reload();assert 'Crédito em análise' not in page.content() and 'R$ 170,00' in page.content()
  assert 'mínimo mensal aplicado' in page.content() and 'SIM-RELATORIO-001' in page.content()
  login(saved['user']);page.goto(work)
  form=page.locator('form[action$="/performance-response"]');expect(form).to_be_visible();assert form.locator('[name=version]').input_value()=='2'
  for width in [320,390,1280]:
   page.set_viewport_size({'width':width,'height':900});assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
  form.locator('[name=report_ack]').check();form.get_by_role('button',name='Conferir apuração',exact=True).click()
  expect(page.get_by_text('Conferido pelo cliente; aguarda financeiro',exact=False)).to_be_visible()
  login('administrador.teste');page.goto(work)
  assert page.locator('form[action$="/performance-report"]').count()==1
  assert post(work+'/performance-report',form=corrected|{'_token':token(),'revision':revision()}).status==409
  invoice={'_token':token(),'revision':revision(),'version':'2','due_date':'2026-12-10','sandbox_ack':'1'}
  assert post(work+'/performance-invoice',form=invoice|{'amount_cents':'1'}).status==422
  assert post(work+'/performance-invoice',form=invoice|{'due_date':'2026-11-30'}).status==422
  assert post(work+'/performance-invoice',form=invoice).status==200
  assert post(work+'/performance-invoice',form=invoice).status==200
  assert post(work+'/performance-invoice',form=invoice|{'due_date':'2026-12-11'}).status==409
  fdb=FutureDatabase();item=next(x for x in fdb.rows() if x['order_id']==oid)
  assert item['amount_cents']==17000 and item['charge_key']=='performance-2026-11' and item['kind']=='performance'
  provider=MonthlyAPI();original_monthly=provider.call
  def monthly_call(method,path,body=None):
   obj=original_monthly(method,path,body)
   if method=='POST':obj['id']='pay_performance_november';provider.obj=obj.copy()
   return obj
  provider.call=monthly_call;worker=FutureWorker(fdb,provider);worker.process(item);assert provider.obj['billingType']=='BOLETO'
  def persisted():return json.loads(fdb.sql("SELECT row_to_json(t) FROM (SELECT f.*,i.customer_ref FROM purchase_rehearsal.sandbox_future_billing f JOIN purchase_rehearsal.sandbox_payment_intents i ON i.order_id=f.order_id WHERE f.order_id='"+oid+"' AND charge_key='performance-2026-11') t;"))
  from asaas_sandbox_webhook import Inbox,parse_event
  event={'id':'evt_performance_monthly','event':'PAYMENT_RECEIVED','payment':{'id':provider.obj['id'],'externalReference':provider.obj['externalReference'],'value':1,'status':'RECEIVED'}}
  Inbox().save(parse_event(json.dumps(event).encode()));tasks=db.request_event_tasks();worker.process(persisted());db.ack_event_tasks(tasks,oid,'performance-2026-11')
  assert persisted()['state']=='pending' # Claimed webhook approval is not payment evidence.
  event['id']='evt_performance_monthly_paid';Inbox().save(parse_event(json.dumps(event).encode()));provider.obj['status']='RECEIVED';tasks=db.request_event_tasks();worker.process(persisted());db.ack_event_tasks(tasks,oid,'performance-2026-11')
  assert persisted()['state']=='approved' and len(provider.posts)==1
  page.reload();assert 'Gestão Performance' in page.content() and 'RECEIVED' in page.content()
  login(saved['user']);page.goto(work)
  assert post(work+'/performance-invoice',form=invoice|{'_token':token()}).status==403
  assert 'RECEIVED' in page.content()
  login('administrador.teste');page.goto(work)
  second={'_token':token(),'revision':revision(),'period':'2026-12','evidence_ref':'SIM-SEGUNDO-MES-003','actual_google_ads':'4000,00','synthetic_ack':'1'}
  assert post(work+'/performance-report',form=second).status==200
  login(saved['user']);page.goto(work)
  assert 'R$ 800,00' in page.content()
  assert post(work+'/performance-response',form={'_token':token(),'revision':revision(),'version':'3','decision':'acknowledged','report_ack':'1'}).status==200
  login('administrador.teste');page.goto(work)
  second_invoice={'_token':token(),'revision':revision(),'version':'3','due_date':'2027-01-10','sandbox_ack':'1'}
  assert post(work+'/performance-invoice',form=second_invoice).status==200
  second_row=next(x for x in fdb.rows() if x['order_id']==oid and x['charge_key']=='performance-2026-12')
  assert second_row['amount_cents']==80000
  provider2=MonthlyAPI();call2=provider2.call
  def second_call(method,path,body=None):
   obj=call2(method,path,body)
   if method=='POST':obj['id']='pay_performance_december';provider2.obj=obj.copy()
   return obj
  provider2.call=second_call;FutureWorker(fdb,provider2).process(second_row)
  assert len(provider2.posts)==1 and provider2.obj['value']==800 and provider2.obj['billingType']=='BOLETO'
  assert fdb.sql("SELECT count(*) FROM purchase_rehearsal.sandbox_future_billing WHERE order_id='"+oid+"';")=='2'
  assert Database().sql('SELECT count(*) FROM purchase_rehearsal.sandbox_payment_intents;')==str(int(psp_count)+1) # One activation, one separately scoped monthly invoice; no duplicate initial charge.
  fixture.write_text(json.dumps({'work':work,'user':saved['user']}))
  print('PASS Performance advance -> report -> customer dispute -> immutable correction -> customer review; role/ownership/price tampering denied; activation490, per-channel floor170 and one idempotent postpaid boleto confirmed by authoritative provider reconciliation')
 b.close()
