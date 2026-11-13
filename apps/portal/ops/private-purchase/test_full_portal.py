"""Role-based navigation in the isolated private Portal; no real accounts."""
import os, shutil, hashlib, json
from pathlib import Path
from playwright.sync_api import sync_playwright
BASE=os.environ.get('PURCHASE_PREVIEW_BASE','http://127.0.0.1:18083/_rehearsal/purchase').split('/_rehearsal/')[0]+'/_preview/portal'
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1280,'height':900})
    errors=[]
    # Review evidence is ONLY permitted in CI on the synthetic localhost run.
    shots_dir=os.getenv('PORTAL_PREVIEW_SCREENSHOTS_DIR') if os.getenv('GITHUB_ACTIONS')=='true' else None
    shots=Path(shots_dir) if shots_dir else None
    captured=[]
    if shots: shots.mkdir(parents=True,exist_ok=True)
    def capture(name):
        if shots is None: return
        assert name.endswith('.png') and '/' not in name
        target=shots/name
        assert not target.exists(), 'Never overwrite prior review evidence'
        page.screenshot(path=str(target),full_page=True)
        captured.append({'name':name,'sha256':hashlib.sha256(target.read_bytes()).hexdigest()})
    page.on('pageerror',lambda e:errors.append(str(e)))
    PURCHASE=BASE.split('/_preview/portal')[0]+'/_rehearsal/purchase'
    assert page.request.get(PURCHASE).status==403, 'Guest purchase access must fail closed'
    page.goto(BASE+'/client/overview')
    assert page.url.rstrip('/')==BASE
    assert page.locator('input[name=username]').count()==1,'CI test profiles are not ready'
    capture('01-login-desktop.png')
    page.locator('input[name=username]').fill('cliente.teste')
    page.locator('input[name=password]').fill('wrong-ci-password')
    page.get_by_role('button',name='Entrar',exact=True).click()
    assert page.get_by_role('alert').count()==1, ('login response',page.url,page.locator('body').inner_text()[:800])
    page.locator('input[name=password]').fill('ci-client-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    assert page.url.endswith('/client/overview')
    assert page.request.get(PURCHASE).status==200, 'Signed-in client may buy only synthetic offers'
    assert page.request.get(PURCHASE+'?offer=onepage').status==200
    # The purchase browser runs before this login test and saves three orders
    # into the same PostgreSQL volume. This is not a hardcoded service card.
    response=page.goto(BASE+'/client/services')
    assert response.status==200
    assert page.locator('tr[data-preview-order-row]').count()==3, 'Client must see all three persisted test purchases'
    capture('02-client-services-desktop.png')
    assert page.locator('text=Pedidos da empresa fictícia').count()==1
    assert page.get_by_text('sem cobrança').count()>=1 or page.get_by_text('Pagamentos e contratações são simulados').count()>=1
    page.reload()
    assert page.locator('tr[data-preview-order-row]').count()==3, 'Reload must re-read PostgreSQL, not browser storage'
    page.goto(BASE+'/client/finance')
    assert page.locator('tr[data-preview-order-row]').count()==3, 'Client finance must read the same persisted fake payments'
    capture('03-client-finance-desktop.png')
    assert page.get_by_text('Não existem faturas, cobranças nem recibos reais aqui.').count()==1
    page.get_by_role('link',name='Contratar serviço de teste').click()
    assert '/_rehearsal/purchase' in page.url
    page.get_by_role('link',name='Voltar ao Portal de teste').click()
    assert page.url.endswith('/client/overview'), 'Navigation from purchase must preserve authenticated test profile'
    for section in ['overview','services','available','contracts','finance','requests','journey','delivery-review','files','notices','modules','account','help']:
        response=page.goto(BASE+'/client/'+section)
        assert response.status==200,(section,response.status)
        assert page.get_by_role('button',name='Sair',exact=True).count()==1
        assert page.locator('img[alt="Imagine Comunicação Digital"]').count()==1
        assert page.locator('img').first.evaluate('(img)=>img.complete&&img.naturalWidth>0')
    assert page.request.get(BASE+'/admin/overview').status==403
    old_cookies=page.context.cookies()
    page.get_by_role('button',name='Sair',exact=True).click()
    assert page.url.rstrip('/')==BASE
    assert page.request.get(PURCHASE).status==403, 'Logout must revoke order access'
    old=browser.new_context(); old.add_cookies(old_cookies)
    old_page=old.new_page(); old_page.goto(BASE+'/client/overview')
    assert old_page.url.rstrip('/')==BASE
    old.close()
    # The anonymous APIRequest above may renew its session cookie. Reload the
    # login form to acquire the current CSRF token before switching profiles.
    page.goto(BASE)
    assert page.locator('input[name=username]').count()==1
    page.locator('input[name=username]').fill('administrador.teste')
    page.locator('input[name=password]').fill('ci-admin-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    assert page.url.endswith('/admin/overview'), (
        'Admin login after client logout',
        page.url,
        page.locator('body').inner_text()[:160],
    )
    for section in ['overview','clients','orders','services','finance','contracts','requests','settings']:
        response=page.goto(BASE+'/admin/'+section)
        assert response.status==200,(section,response.status)
        if section=='orders':
            assert page.locator('table tbody tr').count()==3, 'Admin must see the same restricted SQL order rows'
            capture('04-admin-orders-desktop.png')
    assert page.request.get(BASE+'/client/overview').status==403
    assert page.request.get(PURCHASE).status==403, 'Admin must not read client orders through checkout'
    csrf=page.locator('form[action="/_preview/portal/logout"] input[name="_token"]').get_attribute('value')
    assert csrf
    denied=page.request.post(PURCHASE+'/orders',form={
        '_token':csrf, 'product':'onepage', 'request_key':'TEST-admin-denied-123456',
    })
    assert denied.status==403, ('Admin must not create client orders', denied.status)
    for width in [320,390,768,1280]:
        page.set_viewport_size({'width':width,'height':900})
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'),('overflow',width)
        if width==390:
            capture('05-admin-mobile.png')
    assert not errors,errors
    page.get_by_role('button',name='Sair',exact=True).click()
    if shots:
        expected=['01-login-desktop.png','02-client-services-desktop.png','03-client-finance-desktop.png','04-admin-orders-desktop.png','05-admin-mobile.png']
        assert [item['name'] for item in captured]==expected, captured
        (shots/'manifest.json').write_text(json.dumps({
            'source':'isolated private Portal CI, synthetic users and data only',
            'files':captured,'no_real_customer_data':True,'no_public_deploy':True
        },sort_keys=True,indent=2)+'\n',encoding='utf-8')
    print('PASS client-only purchase, 13 client pages, 8 admin pages, SQL order listing, logout and snapshots')
    browser.close()
