"""F423: owned Performance V5 activation in disposable Asaas sandbox.

Chromium + PostgreSQL FORCE RLS + injected provider API only.
No production account, fiscal invoice, recurring management fee or real money.
"""
import json
import os
import pathlib
import shutil
from playwright.sync_api import sync_playwright
from asaas_sandbox_worker import Database, Worker, Unconfirmed
from test_asaas_sandbox_worker import API

BASE = os.environ.get(
    'PURCHASE_PREVIEW_BASE',
    'http://127.0.0.1:18083/_rehearsal/purchase'
).split('/_rehearsal/')[0] + '/_preview/portal'
saved = json.loads(pathlib.Path('/tmp/portal-customer-ci.json').read_text())

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(
        executable_path=shutil.which('google-chrome') or shutil.which('chromium'),
        headless=True, args=['--no-sandbox']
    )
    page = browser.new_page()
    page.goto(BASE)
    page.locator('[name=username]').fill(saved['user'])
    page.locator('[name=password]').fill('ci-owned-customer-only-2026')
    page.get_by_role('button', name='Entrar', exact=True).click()
    assert '/client/' in page.url, page.locator('body').inner_text()[:200]

    assert page.goto(BASE+'/shop?product=performance').status == 200
    page.locator('[name=google_media]').fill('1000')
    page.locator('[name=meta_media]').fill('700')
    page.locator('[name=offer_ack]').check()
    page.get_by_role('button', name='Criar pedido de teste', exact=True).click()
    url = page.url
    assert '/shop/orders/' in url
    oid = url.rsplit('/',1)[1]
    text = page.locator('body').inner_text()
    assert 'R$ 980,00' in text and 'R$ 370,00' in text
    assert 'Ativação única' in text
    assert page.locator('[name=sandbox_ack]').count() == 1

    def csrf():
        return page.locator('[name=_token]').first.input_value()
    def revision():
        return page.locator('[name=revision]').first.input_value()

    bad = page.request.post(url+'/sandbox',form={
        '_token':csrf(),'revision':revision(),'sandbox_ack':'1',
        'amount_cents':'1'})
    assert bad.status == 422, 'Browser must not set PSP amount'

    page.locator('[name=sandbox_ack]').check()
    page.get_by_role('button',name='Gerar cobrança no Asaas sandbox',exact=True).click()
    assert page.locator('[name=outcome]').count() == 0
    db = Database()
    rows = [row for row in db.rows() if row['order_id'] == oid]
    assert len(rows) == 1, 'Exactly one owned Performance sandbox intent'
    row = rows[0]
    assert row['amount_cents'] == 98000, 'Only the activation is charged'
    assert row['state']=='queued'

    api = API()
    worker = Worker(db,api)
    worker.process(row)
    assert len(api.posts)==2
    payment_requests=[entry[1] for entry in api.posts if entry[0]=='/payments']
    assert len(payment_requests)==1
    payment = payment_requests[0]
    assert payment['value']==980.0
    assert '1700' not in str(payment) and '370' not in str(payment), 'No media or management invoicing'
    assert payment['externalReference']=='imagine:'+oid+':initial'
    assert api.posts[0][1]['notificationDisabled'] is True
    assert not ({'email','mobilePhone','phone'} & set(api.posts[0][1]))

    page.reload()
    assert page.locator('[name=business]').count()==0, 'Pending means no briefing'
    page.locator('[name=sandbox_ack]').check()
    page.get_by_role('button',name='Solicitar atualização do Asaas',exact=True).click()
    row = next(x for x in db.rows() if x['order_id']==oid)
    api.payment['status']='RECEIVED'
    worker.process(row)
    page.reload()
    assert page.locator('[name=business]').count()==1, 'Trusted provider confirmation unlocks briefing'
    assert len(api.posts)==2, 'No duplicated provider writes'
    assert page.locator('form[action$="/future-billing"]').count()==0
    assert page.locator('form[action$="/performance-report"]').count()==0
    assert 'R$ 980,00' in page.locator('body').inner_text()
    browser.close()
print('PASS F423: owned R$980 Performance activation sandbox only; HMAC/RLS, fake provider verification, no ad media/management invoice.')
