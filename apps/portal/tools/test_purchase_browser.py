"""Real forms in Chromium, synthetic actor only, disposable loopback Laravel/PG."""
import shutil, os
from playwright.sync_api import sync_playwright

BASE = os.environ.get('PURCHASE_PREVIEW_BASE','http://127.0.0.1:18776/_rehearsal/purchase')

# Standalone purchase CI uses its isolated synthetic fixture by design.
# Only the private Docker Portal preview requires the synthetic CLIENT login.
PREVIEW_LOGIN_REQUIRED = os.environ.get('PORTAL_PREVIEW_LOGIN_REQUIRED') == '1'
PREVIEW_LOGIN = BASE.split('/_rehearsal/')[0] + '/_preview/portal'

def login_preview_client(page):
    page.goto(PREVIEW_LOGIN)
    page.locator('input[name=username]').fill('cliente.teste')
    page.locator('input[name=password]').fill('ci-client-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    assert page.url.endswith('/client/overview'), 'Synthetic preview client login required'

with sync_playwright() as p:
    chrome = shutil.which('google-chrome') or shutil.which('chromium')
    assert chrome, 'CI Chromium executable is required'
    browser = p.chromium.launch(executable_path=chrome, headless=True, args=['--no-sandbox'])
    page = browser.new_page(viewport={'width':1280,'height':900})
    failures = []
    page.on('pageerror', lambda e: failures.append(str(e)))
    if PREVIEW_LOGIN_REQUIRED:
        assert os.environ.get('GITHUB_ACTIONS') == 'true'
        assert BASE == 'http://127.0.0.1:18083/_rehearsal/purchase'
        guest = page.goto(BASE)
        assert guest.status == 403, 'Purchase must deny unauthenticated preview visitors'
        login_preview_client(page)
    for product, position in [('onepage',0),('landing-pages',1),('performance',2)]:
        page.goto(BASE)
        # A cross-site/absent-CSRF write must not reach the purchase store.
        denied = page.request.post(BASE+'/orders',form={'product':product,'request_key':'TEST-missing-csrf-123456'})
        assert denied.status == 419, f'Expected CSRF rejection, got {denied.status}'
        page.get_by_role('button',name='Criar pedido de teste').nth(position).click()
        page.get_by_role('heading',name='Seu pedido de teste',exact=True).wait_for()
        order_url = page.url
        assert '/orders/TEST-' in order_url
        page.get_by_role('checkbox').check()
        page.locator('select[name=outcome]').select_option('declined')
        page.get_by_role('button',name='Aplicar resultado de teste').click()
        assert page.get_by_role('button',name='Salvar briefing de teste').count() == 0
        page.get_by_role('checkbox').check()
        page.locator('select[name=outcome]').select_option('pending')
        page.get_by_role('button',name='Aplicar resultado de teste').click()
        assert page.get_by_role('button',name='Salvar briefing de teste').count() == 0
        page.get_by_role('checkbox').check()
        page.locator('select[name=outcome]').select_option('approved')
        page.get_by_role('button',name='Aplicar resultado de teste').click()
        for field,value in {'business':'Empresa fictícia','goal':'<script>alert(1)</script>','audience':'Público de exemplo','materials':'Amostras fictícias'}.items():
            page.locator(f'textarea[name={field}]').fill(value)
        page.get_by_role('button',name='Salvar briefing de teste').click()
        page.get_by_role('heading',name='Briefing salvo no Portal de teste').wait_for()
        page.reload()
        assert page.get_by_text('<script>alert(1)</script>',exact=True).count() == 1
        assert page.locator('script').count() == 0
        # Durable order survives a fresh browser; access still requires
        # a NEW client login in private preview. Admin/guest cannot read it.
        other = browser.new_page()
        if PREVIEW_LOGIN_REQUIRED:
            denied = other.goto(order_url)
            assert denied.status == 403
            login_preview_client(other)
        other.goto(order_url)
        other.get_by_role('heading',name='Briefing salvo no Portal de teste').wait_for()
        other.close()
        for width in [320,390,768,1280]:
            page.set_viewport_size({'width':width,'height':900})
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), f'Overflow at {width}'
        print('PASS real Chromium forms, CSRF, payment states and durable briefing: '+product)
    assert not failures, failures
    browser.close()
