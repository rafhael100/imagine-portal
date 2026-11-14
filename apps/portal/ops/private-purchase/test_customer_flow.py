"""Two independent synthetic accounts, owned checkout and administrator visibility."""
import os, json, pathlib, shutil, uuid
from playwright.sync_api import sync_playwright
BASE=os.environ.get('PURCHASE_PREVIEW_BASE','http://127.0.0.1:18083/_rehearsal/purchase').split('/_rehearsal/')[0]+'/_preview/portal'
fixture=pathlib.Path('/tmp/portal-customer-ci.json')
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page()
    def login(user,password):
        page.goto(BASE)
        page.locator('[name=username]').fill(user)
        page.locator('[name=password]').fill(password)
        page.get_by_role('button',name='Entrar',exact=True).click()
        assert '/client/' in page.url or '/admin/' in page.url
    def logout():
        page.goto(BASE+'/client/overview')
        page.get_by_role('button',name='Sair',exact=True).click()
    def csrf():
        return page.locator('input[name=_token]').first.input_value()
    if os.environ.get('CUSTOMER_REOPEN')=='1':
        saved=json.loads(fixture.read_text())
        login(saved['user'],'ci-owned-customer-only-2026')
        assert page.goto(saved['order']).status==200
        assert page.get_by_role('heading',name='Briefing salvo no Portal de teste').count()==1
        assert 'Aurora ficticia' in page.locator('body').inner_text()
        print('PASS owned purchase, account and briefing recovered after database/application restart')
    else:
        suffix=uuid.uuid4().hex[:10]
        def signup(user):
            page.goto(BASE+'/register')
            for name,value in [('name','Cliente ficticio '+user),('company','Aurora ficticia'),('username',user),('password','ci-owned-customer-only-2026'),('password_confirmation','ci-owned-customer-only-2026')]:
                page.locator('[name='+name+']').fill(value)
            page.get_by_role('button',name='Criar conta e continuar').click()
        user='demo-a-'+suffix
        page.goto(BASE+'/shop?product=onepage')
        assert page.url.rstrip('/')==BASE
        signup(user)
        assert page.url.endswith('/shop?product=onepage'),page.url
        assert page.get_by_role('button',name='Criar pedido de teste').count()==1
        token=csrf(); key=page.locator('[name=request_key]').input_value()
        # Tampering with the chosen SKU after a Comercial onepage handoff
        # cannot silently create a different service contract.
        forged=page.request.post(BASE+'/shop/orders',form={
            '_token':token,'request_key':key,'product':'performance',
        })
        assert forged.status==422, ('POST may not change the chosen offer',forged.status)
        assert page.get_by_role('button',name='Criar pedido de teste').count()==1
        page.get_by_role('button',name='Criar pedido de teste').click()
        order_url=page.url; order_id=order_url.rsplit('/',1)[1]
        duplicate=page.request.post(BASE+'/shop/orders',form={'_token':token,'request_key':key,'product':'onepage'})
        assert duplicate.status==200 and duplicate.url==order_url
        assert page.request.post(BASE+'/shop/orders',form={'_token':csrf(),'request_key':key,'product':'onepage','customer_id':'forged'}).status==422
        page.get_by_role('checkbox').check()
        page.locator('[name=outcome]').select_option('declined')
        page.get_by_role('button',name='Aplicar resultado de teste').click()
        assert page.locator('[name=business]').count()==0
        rev=page.locator('[name=revision]').first.input_value()
        rejected=page.request.post(order_url+'/briefing',form={'_token':csrf(),'revision':rev,'business':'x','goal':'x','audience':'x','materials':'x'})
        assert rejected.status==409
        page.get_by_role('checkbox').check()
        page.locator('[name=outcome]').select_option('approved')
        page.get_by_role('button',name='Aplicar resultado de teste').click()
        assert page.request.post(order_url+'/payment',form={'_token':csrf(),'revision':rev,'outcome':'pending','sample_ack':'1'}).status==409
        for name in ['business','goal','audience','materials']:
            page.locator('[name='+name+']').fill('Aurora ficticia')
        page.get_by_role('button',name='Salvar briefing de teste').click()
        page.reload()
        assert page.get_by_role('heading',name='Briefing salvo no Portal de teste').count()==1
        page.goto(BASE+'/client/services')
        assert page.locator('tr[data-customer-order]').count()==1
        page.goto(BASE+'/client/account')
        assert user in page.locator('body').inner_text()
        logout(); signup('demo-b-'+suffix)
        page.goto(BASE+'/client/services')
        assert page.locator('tr[data-customer-order]').count()==0
        assert page.request.get(order_url).status==404
        assert page.request.post(order_url+'/payment',form={'_token':csrf(),'revision':'2','outcome':'approved','sample_ack':'1'}).status==404
        assert page.request.post(order_url+'/briefing',form={'_token':csrf(),'revision':'2','business':'x','goal':'x','audience':'x','materials':'x'}).status==404
        assert page.request.get(BASE+'/admin/orders').status==403
        logout(); login(user,'ci-owned-customer-only-2026')
        page.goto(BASE+'/client/finance')
        assert page.locator('tr[data-customer-order]').count()==1
        for width in [320,390,1280]:
            page.set_viewport_size({'width':width,'height':900})
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
        logout(); login('administrador.teste','ci-admin-only-2026')
        page.goto(BASE+'/admin/orders')
        assert order_id in page.locator('body').inner_text()
        fixture.write_text(json.dumps({'user':user,'order':order_url}))
        print('PASS offer preserved through signup, owned idempotent purchase, fake payment, briefing, relogin, two-customer isolation, forged-owner rejection and administrator listing')
    browser.close()
