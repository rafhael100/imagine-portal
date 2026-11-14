"""Persistent post-briefing delivery cycle with role/ownership/concurrency rejection."""
import os,json,pathlib,shutil
from playwright.sync_api import sync_playwright
BASE=os.environ.get('PURCHASE_PREVIEW_BASE','http://127.0.0.1:18083/_rehearsal/purchase').split('/_rehearsal/')[0]+'/_preview/portal'
saved=json.loads(pathlib.Path('/tmp/portal-customer-ci.json').read_text())
work=BASE+'/work/'+saved['order'].rsplit('/',1)[1]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page()
    def login(user,password):
        page.goto(BASE)
        page.locator('[name=username]').fill(user);page.locator('[name=password]').fill(password)
        page.get_by_role('button',name='Entrar',exact=True).click()
        assert '/client/' in page.url or '/admin/' in page.url
    def logout():
        page.get_by_role('button',name='Sair',exact=True).click()
    def token():return page.locator('[name=_token]').first.input_value()
    def revision():return page.locator('[name=revision]').first.input_value()
    def request(action,note='',rev=None,extra=None):
        data={'_token':token(),'revision':revision() if rev is None else rev,'action':action,'note':note}
        if extra:data.update(extra)
        return page.request.post(work,form=data)
    login(saved['user'],'ci-owned-customer-only-2026')
    response=page.goto(work)
    assert response.status==200
    assert 'no-store' in response.headers.get('cache-control','')
    assert response.headers.get('x-robots-tag')=='noindex, nofollow'
    if os.environ.get('CUSTOMER_WORK_REOPEN')=='1':
        assert page.get_by_role('status').inner_text()=='Concluído no teste'
        assert 'Entrega final revisada ficticia' in page.locator('body').inner_text()
        assert 'Mensagem final ficticia' in page.locator('body').inner_text()
        print('PASS delivery decision and conversation survived PostgreSQL/application restart')
    else:
        assert request('start').status==403
        assert request('message','forged',extra={'customer_id':'forged'}).status==422
        page.locator('#message').fill('Solicitacao ficticia do cliente')
        page.get_by_role('button',name='Enviar mensagem',exact=True).click()
        logout();login(saved['user'].replace('demo-a-','demo-b-'),'ci-owned-customer-only-2026')
        assert page.request.get(work).status==404
        assert request('message','intrusion',rev='0').status==404
        logout();login('administrador.teste','ci-admin-only-2026');page.goto(work)
        assert request('approve').status==403
        assert request('deliver','Premature delivery').status==409
        old=revision()
        page.get_by_role('button',name='Iniciar produção de teste',exact=True).click()
        assert request('start',rev=old).status==409
        page.locator('#delivery').fill('Primeira entrega ficticia')
        page.get_by_role('button',name='Enviar entrega para aprovação',exact=True).click()
        logout();login(saved['user'],'ci-owned-customer-only-2026');page.goto(work)
        page.locator('#changes').fill('Ajustar o titulo ficticio')
        page.get_by_role('button',name='Solicitar ajustes',exact=True).click()
        assert page.get_by_role('status').inner_text()=='Ajustes solicitados'
        logout();login('administrador.teste','ci-admin-only-2026');page.goto(work)
        page.get_by_role('button',name='Iniciar produção de teste',exact=True).click()
        page.locator('#delivery').fill('Entrega final revisada ficticia')
        page.get_by_role('button',name='Enviar entrega para aprovação',exact=True).click()
        logout();login(saved['user'],'ci-owned-customer-only-2026');page.goto(work)
        page.get_by_role('button',name='Aprovar entrega de teste',exact=True).click()
        assert page.get_by_role('status').inner_text()=='Concluído no teste'
        assert request('approve').status==409
        page.locator('#message').fill('Mensagem final ficticia');page.get_by_role('button',name='Enviar mensagem',exact=True).click()
        for section in ['journey','delivery-review','requests','files','notices']:
            assert page.goto(BASE+'/client/'+section).status==200
            assert page.locator('a[href="'+work.replace(BASE,'/_preview/portal')+'"]').count()>=1
        page.goto(work)
        for width in [320,390,1280]:
            page.set_viewport_size({'width':width,'height':900})
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
        print('PASS staff production/delivery, customer adjustment/approval, persistent messages, stale-write rejection, cross-owner and cross-role denial')
    browser.close()
