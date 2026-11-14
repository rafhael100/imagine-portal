"""Owned/admin report review with no PSP side effects, real HTTP and PostgreSQL."""
import json,os,pathlib,shutil
from playwright.sync_api import sync_playwright,expect
from asaas_sandbox_worker import Database,Unconfirmed
BASE=os.environ.get('PURCHASE_PREVIEW_BASE','http://127.0.0.1:18083/_rehearsal/purchase').split('/_rehearsal/')[0]+'/_preview/portal'
fixture=pathlib.Path('/tmp/performance-report-ci.json')
saved=json.loads(pathlib.Path('/tmp/portal-customer-ci.json').read_text())
with sync_playwright() as p:
 b=p.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),headless=True,args=['--no-sandbox']);page=None;pages={}
 def login(user):
  global page
  if user in pages:page=pages[user];return
  page=b.new_page();pages[user]=page
  page.goto(BASE);page.locator('[name=username]').fill(user);page.locator('[name=password]').fill('ci-admin-only-2026' if user=='administrador.teste' else 'ci-owned-customer-only-2026');page.get_by_role('button',name='Entrar',exact=True).click()
 def token():return page.locator('[name=_token]').first.input_value()
 def revision():return page.locator('[name=revision]').first.input_value()
 login(saved['user'])
 if os.getenv('PERFORMANCE_REPORT_REOPEN')=='1':
  work=json.loads(fixture.read_text())['work'];assert page.goto(work).status==200
  expect(page.get_by_text('Conferido pelo cliente; aguarda financeiro',exact=False)).to_be_visible()
  assert page.locator('input[name=version]').count()==0
  assert 'SIM-CORRECAO-002' in page.content() and 'SIM-RELATORIO-001' in page.content()
  login('administrador.teste');assert page.goto(work).status==200;assert page.locator('form[action$="/performance-report"]').count()==0
  print('PASS Performance report history and customer review survived database/application restart')
 else:
  psp_count=Database().sql('SELECT count(*) FROM purchase_rehearsal.sandbox_payment_intents;')
  page.goto(BASE+'/shop?product=performance');page.locator('[name=google_media]').fill('700,00');page.locator('[name=offer_ack]').check();page.get_by_role('button',name='Criar pedido de teste',exact=True).click()
  page.locator('[name=sample_ack]').check();page.get_by_role('button',name='Aplicar resultado de teste',exact=True).click()
  for name in ['business','goal','audience','materials']:page.locator('[name='+name+']').fill('Performance ficticia')
  page.get_by_role('button',name='Salvar briefing de teste',exact=True).click();oid=page.url.rsplit('/',1)[1];work=BASE+'/work/'+oid;page.goto(work)
  assert page.request.post(work+'/performance-report',form={'_token':token(),'revision':revision()}).status==403
  login('administrador.teste');page.goto(work)
  form=page.locator('form[action$="/performance-report"]');expect(form).to_be_visible()
  data={'_token':token(),'revision':revision(),'period':'2026-11','evidence_ref':'SIM-RELATORIO-001','actual_google_ads':'1000,00','synthetic_ack':'1'}
  assert page.request.post(work+'/performance-report',form=data|{'actual_management_fee_cents':'1'}).status==422
  assert page.request.post(work+'/performance-report',form=data|{'actual_meta_ads':'0'}).status==422
  assert page.request.post(work+'/performance-report',form=data).status==200
  page.reload();assert 'R$ 60,00' in page.content() and 'Aguardando conferência do cliente' in page.content()
  assert page.request.post(work+'/performance-report',form=data).status==409
  assert page.request.post(work+'/performance-response',form={'_token':token(),'revision':revision(),'version':'1','decision':'acknowledged','report_ack':'1'}).status==403
  # PSP role cannot edit the report, so gateway events cannot alter its financial terms.
  try:Database().sql("UPDATE purchase_rehearsal.performance_settlement_reports SET report='{}'::jsonb WHERE order_id='"+oid+"';")
  except Unconfirmed:pass
  else:raise AssertionError('PSP must not overwrite report amounts')
  login(saved['user'].replace('demo-a-','demo-b-'));assert page.request.get(work).status==404
  assert page.request.post(work+'/performance-response',form={'_token':token(),'revision':'0','version':'1','decision':'acknowledged','report_ack':'1'}).status==404
  login(saved['user']);page.goto(work)
  response={'_token':token(),'revision':revision(),'version':'1','decision':'disputed','note':'Conferir gasto ficticio','report_ack':'1'}
  assert page.request.post(work+'/performance-response',form=response|{'adjustment_cents':'0'}).status==422
  assert page.request.post(work+'/performance-response',form=response).status==200
  assert page.request.post(work+'/performance-response',form=response).status==409
  login('administrador.teste');page.goto(work)
  corrected={'_token':token(),'revision':revision(),'period':'2026-11','evidence_ref':'SIM-CORRECAO-002','actual_google_ads':'500,00','synthetic_ack':'1'}
  assert page.request.post(work+'/performance-report',form=corrected).status==200
  page.reload();assert 'Crédito em análise' in page.content() and 'R$ 40,00' in page.content()
  assert 'não há complemento automático' in page.content() and 'SIM-RELATORIO-001' in page.content()
  login(saved['user']);page.goto(work)
  form=page.locator('form[action$="/performance-response"]');expect(form).to_be_visible();assert form.locator('[name=version]').input_value()=='2'
  for width in [320,390,1280]:
   page.set_viewport_size({'width':width,'height':900});assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
  form.locator('[name=report_ack]').check();form.get_by_role('button',name='Conferir apuração',exact=True).click()
  expect(page.get_by_text('Conferido pelo cliente; aguarda financeiro',exact=False)).to_be_visible()
  login('administrador.teste');page.goto(work)
  assert page.locator('form[action$="/performance-report"]').count()==0
  assert page.request.post(work+'/performance-report',form=corrected|{'_token':token(),'revision':revision(),'period':'2026-12'}).status==409
  assert Database().sql('SELECT count(*) FROM purchase_rehearsal.sandbox_payment_intents;')==psp_count # Only prior Asaas fixture; report review creates no charge.
  fixture.write_text(json.dumps({'work':work}))
  print('PASS Performance advance -> report -> customer dispute -> immutable correction -> customer review; role/ownership/price tampering denied; no extra PSP charge')
 b.close()
