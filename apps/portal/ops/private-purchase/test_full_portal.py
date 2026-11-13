"""Role-based navigation in the isolated private Portal; no real accounts."""
import os, shutil
from playwright.sync_api import sync_playwright
BASE=os.environ.get('PURCHASE_PREVIEW_BASE','http://127.0.0.1:18083/_rehearsal/purchase').split('/_rehearsal/')[0]+'/_preview/portal'
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1280,'height':900})
    errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.goto(BASE+'/client/overview')
    assert page.url.rstrip('/')==BASE
    assert page.locator('input[name=username]').count()==1,'CI test profiles are not ready'
    page.locator('input[name=username]').fill('cliente.teste')
    page.locator('input[name=password]').fill('wrong-ci-password')
    page.get_by_role('button',name='Entrar',exact=True).click()
    assert page.get_by_role('alert').count()==1
    page.locator('input[name=password]').fill('ci-client-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    assert page.url.endswith('/client/overview')
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
    assert page.request.get(BASE+'/client/overview').status==403
    for width in [320,390,768,1280]:
        page.set_viewport_size({'width':width,'height':900})
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'),('overflow',width)
    assert not errors,errors
    page.get_by_role('button',name='Sair',exact=True).click()
    print('PASS client login, 13 client pages, admin login, 8 admin pages, cross-role denial, logout revocation and responsive layout')
    browser.close()
