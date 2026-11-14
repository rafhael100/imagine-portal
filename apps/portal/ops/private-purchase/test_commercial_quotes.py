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
    for channel,description in [('google_ads','Google Ads'),('meta_ads','Meta Ads')]:
        page.goto(BASE+'/shop?product=performance&channel='+channel)
        assert page.locator('[data-channel-origin="'+channel+'"]').count()==1
        assert description in page.locator('[data-channel-origin]').inner_text()
        assert page.locator('[name=google_media]').input_value()==''
        assert page.locator('[name=meta_media]').input_value()==''
    for bad in [BASE+'/shop?product=onepage&channel=google_ads',
                BASE+'/shop?product=performance&channel=unknown',
                BASE+'/shop?product=performance&channel[]=google_ads']:
        assert page.request.get(bad).status==422
    page.goto(BASE)
    page.get_by_role('button',name='Sair',exact=True).click()
    page.goto(BASE+'/shop?product=performance&channel=meta_ads')
    assert page.url.rstrip('/')==BASE
    page.locator('[name=username]').fill(saved['user'])
    page.locator('[name=password]').fill('ci-owned-customer-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    assert page.url.endswith('/shop?product=performance&channel=meta_ads'),page.url
    assert page.locator('[data-channel-origin="meta_ads"]').count()==1
    assert page.locator('[name=meta_media]').input_value()==''
    page.goto(BASE+'/shop?product=landing-pages')
    assert page.locator('[data-channel-origin]').count()==0
    assert 'R$ 1.290,00' in page.locator('body').inner_text()
    assert 'R$ 430,00' in page.locator('body').inner_text()
    page.locator('[name=offer_ack]').check()
    page.get_by_role('button',name='Criar pedido de teste').click()
    assert 'R$ 1.290,00' in page.locator('body').inner_text()
    assert page.locator('[data-billing-kind="setup"]').count()==3
    assert page.locator('[data-billing-kind="monthly"]').count()==3
    assert 'R$ 430,00' in page.locator('[data-billing-calendar]').inner_text()
    page.goto(BASE+'/shop?product=performance')
    assert '20%' in page.locator('body').inner_text()
    data={'_token':page.locator('[name=_token]').input_value(),'product':'performance','request_key':page.locator('[name=request_key]').input_value(),'offer_ack':'1','offer_version':page.locator('[name=offer_version]').input_value()}
    assert page.request.post(BASE+'/shop/orders',form=data|{'google_media':'1400','meta_media':'0'}).status==422
    assert page.request.post(BASE+'/shop/orders',form=data|{'google_media':'699,99'}).status==422
    assert page.request.post(BASE+'/shop/orders',form=data|{'google_media':'700','fee_cents':'1'}).status==422
    page.locator('[name=google_media]').fill('700')
    page.locator('[name=meta_media]').fill('700')
    page.locator('[name=offer_ack]').check()
    page.get_by_role('button',name='Criar pedido de teste').click()
    owned_url=page.url
    body=page.locator('body').inner_text()
    assert 'R$ 280,00' in body and body.count('R$ 700,00')==2
    assert 'R$ 1.680,00' not in body
    page.reload()
    assert 'R$ 280,00' in page.locator('body').inner_text()
    for width in [320,390,1280]:
        page.set_viewport_size({'width':width,'height':900})
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
    # A reused idempotency key cannot change the stored budget or quote.
    assert page.request.post(BASE+'/shop/orders',form=data|{'google_media':'800','meta_media':'700'}).status==409
    page.goto(BASE)
    page.get_by_role('button',name='Sair',exact=True).click()
    page.goto(BASE)
    page.locator('[name=username]').fill('administrador.teste')
    page.locator('[name=password]').fill('ci-admin-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    page.goto(BASE+'/work/'+owned_url.rsplit('/',1)[1])
    assert 'R$ 280,00' in page.locator('body').inner_text()
    print('PASS Landing Page quote, separate Performance media and fee, per-channel minimum, frozen quote, price forgery rejection and admin view')
    browser.close()
