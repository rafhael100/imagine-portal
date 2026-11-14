"""Two independent synthetic accounts, owned checkout and administrator visibility."""
import os, json, pathlib, shutil, uuid, hashlib
from playwright.sync_api import sync_playwright
BASE=os.environ.get('PURCHASE_PREVIEW_BASE','http://127.0.0.1:18083/_rehearsal/purchase').split('/_rehearsal/')[0]+'/_preview/portal'
fixture=pathlib.Path('/tmp/portal-customer-ci.json')
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1280,'height':900})
    evidence_dir=os.getenv('PORTAL_OWNED_PREVIEW_SHOTS_DIR') if os.getenv('GITHUB_ACTIONS')=='true' else None
    shots=pathlib.Path(evidence_dir) if evidence_dir else None
    evidence=[]
    if shots: shots.mkdir(parents=True,exist_ok=True)
    def capture(name):
        if shots is None: return
        assert name.endswith('.png') and '/' not in name
        target=shots/name
        assert not target.exists(), 'Never overwrite screenshot evidence'
        page.screenshot(path=str(target),full_page=True)
        evidence.append({'name':name,'sha256':hashlib.sha256(target.read_bytes()).hexdigest()})
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
            if not evidence: capture('01-registration-blank.png')
            for name,value in [('name','Cliente ficticio '+user),('company','Aurora ficticia'),('username',user),('password','ci-owned-customer-only-2026'),('password_confirmation','ci-owned-customer-only-2026')]:
                page.locator('[name='+name+']').fill(value)
            page.get_by_role('button',name='Criar conta e continuar').click()
        user='demo-a-'+suffix
        page.goto(BASE+'/shop?product=onepage')
        assert page.url.rstrip('/')==BASE
        signup(user)
        assert page.url.endswith('/shop?product=onepage'),page.url
        assert page.get_by_role('button',name='Criar pedido de teste').count()==1
        capture('02-selected-onepage-offer.png')
        token=csrf(); key=page.locator('[name=request_key]').input_value()
        version=page.locator('[name=offer_version]').input_value()
        assert 'R$ 990,00' in page.locator('body').inner_text()
        page.locator('[name=offer_ack]').check()
        page.get_by_role('button',name='Criar pedido de teste').click()
        order_url=page.url; order_id=order_url.rsplit('/',1)[1]
        duplicate=page.request.post(BASE+'/shop/orders',form={'_token':token,'request_key':key,'product':'onepage','offer_version':version,'offer_ack':'1'})
        assert duplicate.status==200 and duplicate.url==order_url
        assert page.request.post(BASE+'/shop/orders',form={'_token':csrf(),'request_key':key,'product':'onepage','offer_version':version,'offer_ack':'1','customer_id':'forged'}).status==422
        assert 'R$ 330,00' in page.locator('body').inner_text()
        assert page.locator('[data-billing-calendar="reference-only"]').count()==1
        assert page.locator('[data-billing-kind="setup"]').count()==3
        assert page.locator('[data-billing-kind="monthly"]').count()==3
        assert 'nenhuma cobrança automática' in page.locator('[data-billing-calendar]').inner_text()
        assert 'Mensalidade de referência 1' in page.locator('[data-billing-calendar]').inner_text()
        assert page.request.post(BASE+'/shop/orders',form={'_token':csrf(),'request_key':key,'product':'onepage','offer_version':version,'offer_ack':'1','price_cents':'1'}).status==422
        assert page.request.post(BASE+'/shop/orders',form={'_token':csrf(),'request_key':key,'product':'onepage','offer_version':'0'*64,'offer_ack':'1'}).status==409
        page.locator('[name=sample_ack]').check()
        page.locator('[name=outcome]').select_option('declined')
        page.get_by_role('button',name='Aplicar resultado de teste').click()
        assert page.locator('[name=business]').count()==0
        rev=page.locator('[name=revision]').first.input_value()
        rejected=page.request.post(order_url+'/briefing',form={'_token':csrf(),'revision':rev,'business':'x','goal':'x','audience':'x','materials':'x'})
        assert rejected.status==409
        page.locator('[name=sample_ack]').check()
        page.locator('[name=outcome]').select_option('approved')
        page.get_by_role('button',name='Aplicar resultado de teste').click()
        assert page.request.post(order_url+'/payment',form={'_token':csrf(),'revision':rev,'outcome':'pending','sample_ack':'1'}).status==409
        for name in ['business','goal','audience','materials']:
            page.locator('[name='+name+']').fill('Aurora ficticia')
        page.get_by_role('button',name='Salvar briefing de teste').click()
        page.reload()
        assert page.get_by_role('heading',name='Briefing salvo no Portal de teste').count()==1
        capture('03-owned-purchase-complete.png')
        page.goto(BASE+'/client/services')
        assert page.locator('tr[data-customer-order]').count()==1
        capture('04-customer-service-list.png')
        page.goto(BASE+'/client/account')
        assert user in page.locator('body').inner_text()
        logout()
        page.goto(BASE+'/shop?product=performance&channel=google_ads')
        assert page.url.rstrip('/')==BASE
        signup('demo-b-'+suffix)
        assert page.url.endswith('/shop?product=performance&channel=google_ads'),page.url
        assert page.locator('[data-channel-origin="google_ads"]').count()==1
        assert page.locator('[name=google_media]').input_value()==''
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
        capture('05-admin-owned-order.png')
        if shots:
            expected=['01-registration-blank.png','02-selected-onepage-offer.png',
                      '03-owned-purchase-complete.png','04-customer-service-list.png',
                      '05-admin-owned-order.png']
            assert [f['name'] for f in evidence]==expected, evidence
            (shots/'manifest.json').write_text(json.dumps({
                'source':'CI synthetic individual checkout only',
                'files':evidence,
                'no_real_customer_data':True,
                'no_public_deploy':True,
                'credentials_captured':False,
            },sort_keys=True,indent=2)+'\n',encoding='utf-8')
        fixture.write_text(json.dumps({'user':user,'order':order_url}))
        print('PASS offer preserved through signup, owned idempotent purchase, fake payment, briefing, relogin, two-customer isolation, forged-owner rejection and administrator listing')
    browser.close()
