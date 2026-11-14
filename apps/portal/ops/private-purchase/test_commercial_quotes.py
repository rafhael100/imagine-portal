"""Approved quotes in real Blade HTTP flow; no PSP requests or personal data."""
import json,pathlib,shutil,os
from playwright.sync_api import sync_playwright
BASE=os.environ.get('PURCHASE_PREVIEW_BASE','http://127.0.0.1:18083/_rehearsal/purchase').split('/_rehearsal/')[0]+'/_preview/portal'
saved=json.loads(pathlib.Path('/tmp/portal-customer-ci.json').read_text())
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page()
    page.goto(BASE)
    page.locator('[name=username]').fill(saved['user'])
    page.locator('[name=password]').fill('ci-owned-customer-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    page.goto(BASE+'/shop?product=landing-pages')
    assert 'R$ 1.290,00' in page.locator('body').inner_text()
    assert 'R$ 430,00' in page.locator('body').inner_text()
    page.locator('[name=offer_ack]').check()
    page.get_by_role('button',name='Criar pedido de teste').click()
    assert 'R$ 1.290,00' in page.locator('body').inner_text()
    page.goto(BASE+'/shop?product=performance')
    assert '20%' in page.locator('body').inner_text()
    data={'_token':page.locator('[name=_token]').input_value(),'product':'performance','request_key':page.locator('[name=request_key]').input_value(),'offer_ack':'1','offer_version':page.locator('[name=offer_version]').input_value()}
    assert page.request.post(BASE+'/shop/orders',form=data|{'google_media':'1400','meta_media':'0'}).status==422
    assert page.request.post(BASE+'/shop/orders',form=data|{'google_media':'699,99'}).status==422
    assert page.request.post(BASE+'/shop/orders',form=data|{'google_media':'700'}).status==422  # Google media minimum is R$1,000
    assert page.request.post(BASE+'/shop/orders',form=data|{'google_media':'1000','fee_cents':'1'}).status==422
    page.locator('[name=google_media]').fill('1000')
    page.locator('[name=meta_media]').fill('700')
    page.locator('[name=offer_ack]').check()
    page.get_by_role('button',name='Criar pedido de teste').click()
    owned_url=page.url
    body=page.locator('body').inner_text()
    assert 'R$ 980,00' in body  # activation: R$490 for each channel
    assert 'R$ 370,00' in body  # illustrative postpaid: Google R$200 + Meta R$170
    assert 'R$ 1.000,00' in body and 'R$ 700,00' in body
    assert 'R$ 2.680,00' not in body  # ad spend and activation are separate, not summed into a PSP invoice
    page.reload()
    assert 'R$ 980,00' in page.locator('body').inner_text()
    for width in [320,390,1280]:
        page.set_viewport_size({'width':width,'height':900})
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
    # A reused idempotency key cannot change the stored budget or quote.
    assert page.request.post(BASE+'/shop/orders',form=data|{'google_media':'1500','meta_media':'700'}).status==409
    page.goto(BASE)
    page.get_by_role('button',name='Sair',exact=True).click()
    page.goto(BASE)
    page.locator('[name=username]').fill('administrador.teste')
    page.locator('[name=password]').fill('ci-admin-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    page.goto(BASE+'/work/'+owned_url.rsplit('/',1)[1])
    assert 'R$ 980,00' in page.locator('body').inner_text()
    print('PASS Landing Page quote, Performance V5 R$980 activation, R$370 illustrative postpaid, media minima, price tampering and admin view')
    browser.close()
