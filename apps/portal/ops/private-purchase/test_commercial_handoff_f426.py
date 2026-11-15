"""F426: real Chromium HTTP selection handoff -> demo login/register -> server quote.
No PSP, billing, cookies outside the isolated CI session, or real identities.
"""
import json, pathlib, os, shutil, uuid
from urllib.parse import quote
from playwright.sync_api import sync_playwright

BASE=os.environ.get('PURCHASE_PREVIEW_BASE','http://127.0.0.1:18083/_rehearsal/purchase').split('/_rehearsal/')[0]+'/_preview/portal'
fixture=pathlib.Path('/tmp/portal-customer-ci.json')
saved=json.loads(fixture.read_text())

def href(site, channels, tier):
    return BASE+'/shop?site='+site+'&channels='+quote(channels,safe='')+'&tier='+tier

with sync_playwright() as playwright:
    browser=playwright.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':375,'height':850})
    # Selection starts before authentication. Only enum values cross domains.
    page.goto(href('onepage','google_ads,meta_ads','recommended'))
    assert page.url.rstrip('/')==BASE
    page.locator('[name=username]').fill(saved['user'])
    page.locator('[name=password]').fill('ci-owned-customer-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    assert page.url.endswith('/shop'),page.url
    assert page.locator('[data-commercial-handoff-review]').count()==1
    rendered=page.locator('[data-commercial-handoff-review]').inner_text()
    assert 'Onepage' in rendered and 'Google Ads' in rendered and 'Meta Ads' in rendered
    assert 'R$ 990,00' in rendered and 'R$ 980,00' in rendered
    assert 'R$ 500,00' in rendered and 'R$ 2.500,00' in rendered
    assert 'R$ 1.310,00' in rendered and 'não é uma cobrança única' in rendered.lower()
    cards=page.locator('form[action$="/shop/orders"]')
    assert cards.count()==2
    assert set(cards.locator('[name=product]').evaluate_all('(nodes)=>nodes.map(n=>n.value)'))=={'onepage','performance'}
    assert page.locator('[name=google_media]').input_value()=='1500.00'
    assert page.locator('[name=meta_media]').input_value()=='1000.00'
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
    assert page.locator('body').get_attribute('data-payment-real') is None
    assert page.request.get(href('onepage','google_ads,meta_ads','recommended')+'&amount_cents=1').status==422
    assert page.request.get(href('onepage','google_ads,google_ads','recommended')).status==422
    assert page.request.get(href('none','','minimum')).status==422
    assert page.request.get(href('onepage','chatgpt_ads','recommended')).status==422
    assert page.request.get(href('onepage','meta_ads','fake')).status==422
    assert page.request.get(href('onepage','google_ads,meta_ads','minimum')+'&product=performance').status==422
    page.goto(BASE+'/shop')
    assert page.locator('[name=google_media]').input_value()=='1500.00'
    # Customer explicitly decides each product: no order exists because of GET.
    assert page.locator('form[action$="/shop/orders"] [name=offer_ack]').count()==2
    assert page.locator('body').get_attribute('data-autosubmit') is None

    # The same validated intent survives the synthetic registration path.
    page.goto(BASE)
    page.get_by_role('button',name='Sair',exact=True).click()
    page.goto(href('landing-pages','meta_ads','minimum'))
    assert page.url.rstrip('/')==BASE
    page.goto(BASE+'/register')
    username='demo-handoff-'+uuid.uuid4().hex[:9]
    for field,value in [
      ('name','Cliente ficticio de homologacao'),
      ('company','Empresa exemplo de homologacao'),
      ('username',username),
      ('password','ci-owned-customer-only-2026'),
      ('password_confirmation','ci-owned-customer-only-2026')
    ]: page.locator('[name='+field+']').fill(value)
    page.get_by_role('button',name='Criar conta e continuar').click()
    assert page.url.endswith('/shop'),page.url
    assert page.locator('[data-commercial-handoff-review]').count()==1
    assert 'Landing Page' in page.locator('[data-commercial-handoff-review]').inner_text()
    assert page.locator('[name=meta_media]').input_value()=='700.00'
    assert page.locator('[name=google_media]').input_value()==''
    assert page.locator('form[action$="/shop/orders"]').count()==2
    for width in [320,390,1280]:
      page.set_viewport_size({'width':width,'height':850})
      assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),width
    browser.close()
print('PASS F426: opt-in choice survives synthetic login+registration; server-owned totals, separated orders, tampering denied and 320px layout')
