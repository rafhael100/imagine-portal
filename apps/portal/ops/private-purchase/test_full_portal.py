"""Role-based navigation in the isolated private Portal; no real accounts."""
import os, shutil
from playwright.sync_api import sync_playwright
BASE=os.environ.get('PURCHASE_PREVIEW_BASE','http://127.0.0.1:18083/_rehearsal/purchase').split('/_rehearsal/')[0]+'/_preview/portal'
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1280,'height':900})
    errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    PURCHASE=BASE.split('/_preview/portal')[0]+'/_rehearsal/purchase'
    assert page.request.get(PURCHASE).status==403, 'Guest purchase access must fail closed'
    page.goto(BASE+'/client/overview')
    assert page.url.rstrip('/')==BASE
    assert page.locator('input[name=username]').count()==1,'CI test profiles are not ready'
    page.locator('input[name=username]').fill('cliente.teste')
    page.locator('input[name=password]').fill('wrong-ci-password')
    page.get_by_role('button',name='Entrar',exact=True).click()
    assert page.get_by_role('alert').count()==1, ('login response',page.url,page.locator('body').inner_text()[:800])
    page.locator('input[name=password]').fill('ci-client-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    assert page.url.endswith('/client/overview')
    assert page.request.get(PURCHASE).status==200, 'Signed-in client may access synthetic purchase'
    assert page.request.get(PURCHASE+'?offer=onepage').status==200
    # The purchase browser runs before this login test and saves three orders
    # into the same PostgreSQL volume. This is not a hardcoded service card.
    response=page.goto(BASE+'/client/services')
    assert response.status==200
    assert page.locator('tr[data-preview-order-row]').count()==3, 'Client must see all three persisted test purchases'
    assert page.locator('text=Pedidos da empresa fictícia').count()==1
    assert page.get_by_text('sem cobrança').count()>=1 or page.get_by_text('Pagamentos e contratações são simulados').count()>=1
    page.reload()
    assert page.locator('tr[data-preview-order-row]').count()==3, 'Reload must re-read PostgreSQL, not browser storage'
    page.goto(BASE+'/client/finance')
    assert page.locator('tr[data-preview-order-row]').count()==3, 'Client finance must read the same persisted fake payments'
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
    assert page.request.get(PURCHASE).status==403, 'Logout revokes purchase access'
    old=browser.new_context(); old.add_cookies(old_cookies)
    old_page=old.new_page(); old_page.goto(BASE+'/client/overview')
    assert old_page.url.rstrip('/')==BASE
    old.close()
    page.locator('input[name=username]').fill('administrador.teste')
    page.locator('input[name=password]').fill('ci-admin-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    assert page.url.endswith('/admin/overview')
    for section in ['overview','clients','orders','services','finance','contracts','requests','settings']:
        response=page.goto(BASE+'/admin/'+section)
        assert response.status==200,(section,response.status)
        if section=='orders':
            assert page.locator('table tbody tr').count()==3, 'Admin must see the same restricted SQL order rows'
    assert page.request.get(BASE+'/client/overview').status==403
    assert page.request.get(PURCHASE).status==403, 'Admin must not read a client purchase'
    csrf=page.locator('form[action="/_preview/portal/logout"] input[name="_token"]').get_attribute('value')
    assert csrf
    denied=page.request.post(PURCHASE+'/orders',form={
        '_token':csrf, 'product':'onepage', 'request_key':'TEST-admin-denied-123456',
    })
    assert denied.status==403, ('Admin must not create client orders', denied.status)
    for width in [320,390,768,1280]:
        page.set_viewport_size({'width':width,'height':900})
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'),('overflow',width)
    assert not errors,errors
    page.get_by_role('button',name='Sair',exact=True).click()
    print('PASS client-only purchase gate, 13 client pages, 8 admin pages, SQL orders, logout revocation and responsive layout')
    browser.close()
