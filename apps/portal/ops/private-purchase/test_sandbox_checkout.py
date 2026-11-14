"""Real HTTP/PG ledger, injected provider only in CI. No keys or external API."""
import json,pathlib,shutil,os
from playwright.sync_api import sync_playwright
from asaas_sandbox_worker import Database,Worker,Unconfirmed
from test_asaas_sandbox_worker import API
BASE=os.environ.get('PURCHASE_PREVIEW_BASE','http://127.0.0.1:18083/_rehearsal/purchase').split('/_rehearsal/')[0]+'/_preview/portal'
saved=json.loads(pathlib.Path('/tmp/portal-customer-ci.json').read_text())
with sync_playwright() as p:
    b=p.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=b.new_page();page.goto(BASE)
    page.locator('[name=username]').fill(saved['user']);page.locator('[name=password]').fill('ci-owned-customer-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    page.goto(BASE+'/shop?product=onepage');page.locator('[name=offer_ack]').check()
    page.get_by_role('button',name='Criar pedido de teste').click();url=page.url;oid=url.rsplit('/',1)[1]
    def token():return page.locator('[name=_token]').first.input_value()
    def revision():return page.locator('[name=revision]').first.input_value()
    invalid=page.request.post(url+'/sandbox',form={'_token':token(),'revision':revision(),'sandbox_ack':'1','amount_cents':'1'})
    assert invalid.status==422
    page.locator('[name=sandbox_ack]').check();page.get_by_role('button',name='Gerar cobrança no Asaas sandbox',exact=True).click()
    assert page.locator('[name=outcome]').count()==0
    assert page.request.post(url+'/payment',form={'_token':token(),'revision':revision(),'sample_ack':'1','outcome':'approved'}).status==409
    db=Database();rows=db.rows();row=next(x for x in rows if x['order_id']==oid)
    assert row['amount_cents']==33000
    try:db.sql("UPDATE purchase_rehearsal.sandbox_payment_intents SET amount_cents=1 WHERE order_id='"+oid+"';")
    except Unconfirmed:pass
    else:raise AssertionError('Worker must not alter financial amount')
    api=API();w=Worker(db,api);w.process(row)
    # Fake provider fixture references are test-order-specific, never real PSP data.
    page.reload();assert page.get_by_role('link',name='Abrir cobrança de teste no Asaas').count()==1
    assert page.locator('[name=business]').count()==0
    assert len(api.posts)==2
    page.locator('[name=sandbox_ack]').check();page.get_by_role('button',name='Solicitar atualização do Asaas',exact=True).click()
    row=next(x for x in db.rows() if x['order_id']==oid)
    api.payment['status']='RECEIVED';w.process(row)
    page.reload();assert page.locator('[name=business]').count()==1;assert len(api.posts)==2
    for name in ['business','goal','audience','materials']:page.locator('[name='+name+']').fill('Exemplo sandbox ficticio')
    page.get_by_role('button',name='Salvar briefing de teste').click()
    assert page.get_by_role('heading',name='Briefing salvo no Portal de teste').count()==1
    # A later refund cannot delete the historic accepted briefing, but must
    # block further fulfilment even when payment='approved' remains auditable.
    fresh=json.loads(db.sql("SELECT row_to_json(i) FROM purchase_rehearsal.sandbox_payment_intents i WHERE order_id='"+oid+"';"))
    api.payment['status']='REFUNDED'
    w.process(fresh)
    assert db.sql("SELECT payment_hold::text FROM purchase_rehearsal.customer_orders WHERE id='"+oid+"';")=='true'
    page.reload()
    assert 'Suspenso para revisão financeira' in page.locator('body').inner_text()
    assert page.get_by_role('alert').filter(has_text='estornada ou contestada').count()==1
    b.close()
print('PASS sandbox request -> immutable ledger -> provider reconciliation -> approved owned order -> briefing; browser cannot forge approval')
