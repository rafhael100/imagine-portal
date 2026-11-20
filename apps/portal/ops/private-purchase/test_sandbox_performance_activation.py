"""F423: owned Performance V5 activation in disposable Asaas sandbox.

Chromium + PostgreSQL FORCE RLS + injected provider API only.
No production account, fiscal invoice, recurring management fee or real money.
"""
import os
import shutil
import uuid
import time
from playwright.sync_api import sync_playwright
from asaas_sandbox_worker import Database, Worker
from test_asaas_sandbox_worker import API

class PerOrderSyntheticAPI(API):
    """Distinct fake PSP references per order; keep DB uniqueness enabled."""
    def __init__(self, order_id):
        super().__init__()
        self.suffix = order_id.split('-')[-1][:20]
        assert len(self.suffix)==20 and all(c in '0123456789abcdef' for c in self.suffix)

    def call(self, method, path, payload=None):
        result=super().call(method,path,payload)
        if method=='POST' and path=='/customers':
            ref='cus_f423'+self.suffix
            self.customer['id']=ref
            result['id']=ref
        elif method=='POST' and path=='/payments':
            ref='pay_f423'+self.suffix
            self.payment['id']=ref
            result['id']=ref
        return result

BASE = os.environ.get(
    'PURCHASE_PREVIEW_BASE',
    'http://127.0.0.1:18083/_rehearsal/purchase'
).split('/_rehearsal/')[0] + '/_preview/portal'

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(
        executable_path=shutil.which('google-chrome') or shutil.which('chromium'),
        headless=True, args=['--no-sandbox']
    )
    page = browser.new_page()

    # Do not reuse a customer who already logged in repeatedly earlier
    # in the full CI suite. Keep the real account throttle enabled and
    # create a one-time fictitious user rather than bypassing security.
    user = 'demo-f423-' + uuid.uuid4().hex[:12]
    assert page.goto(BASE+'/register').status == 200
    for name, value in [
        ('name','Cliente ficticio F423'),
        ('company','Empresa sintética F423'),
        ('username',user),
        ('password','ci-owned-customer-only-2026'),
        ('password_confirmation','ci-owned-customer-only-2026'),
    ]:
        page.locator('[name='+name+']').fill(value)
    page.get_by_role('button', name='Criar conta e continuar', exact=True).click()
    assert '/client/' in page.url, page.locator('body').inner_text()[:200]

    assert page.goto(BASE+'/shop?product=performance').status == 200
    page.locator('[name=google_media]').fill('1000')
    page.locator('[name=meta_media]').fill('700')
    page.locator('[name=offer_ack]').check()
    page.get_by_role('button', name='Criar pedido de teste', exact=True).click()
    url = page.url
    assert '/shop/orders/' in url
    oid = url.rsplit('/',1)[1]
    snapshot_url = url + '/performance-snapshot'

    def snapshot():
        response = page.context.request.get(snapshot_url)
        assert response.status == 200, 'Real authenticated Portal source read must return HTTP 200'
        payload = response.json()
        assert payload['schema'] == 'portal_f423_ci_authenticated_order_source_v1'
        assert payload['order_id'] == oid
        assert payload['company_id'].startswith('demo-')
        assert payload['source'] == 'ci_only_portal_customer_purchase_pg18'
        assert payload['ready_channels_count'] == 0
        assert payload['decision_persisted_by_performance'] is False
        assert payload['performance_s2s_proven'] is False
        assert payload['operational_enabled'] is False
        assert payload['ads_write_enabled'] is False
        assert payload['billable'] is False
        assert set(payload['channels']) == {'google_ads','meta_ads'}
        assert payload['channels']['google_ads']['media_cents'] == 100000
        assert payload['channels']['meta_ads']['media_cents'] == 70000
        for channel in payload['channels'].values():
            assert channel['activation_cents'] == 49000
            assert channel['decision_state'] == 'blocked'
            assert channel['connection_state'] == 'not_connected'
            assert channel['campaign_state'] == 'not_created'
        return payload

    unauthed = browser.new_context()
    assert unauthed.request.get(snapshot_url).status == 403, 'No session must deny source read'
    unauthed.close()
    initial_snapshot = snapshot()
    assert initial_snapshot['purchase_state'] == 'pending_or_unverified'
    assert initial_snapshot['purchase_evidence'] is None
    assert initial_snapshot['onboarding_state'] == 'awaiting_briefing'
    assert initial_snapshot['approved_briefing_revision'] is None
    assert page.context.request.get(
        BASE + '/shop/orders/TEST-' + uuid.uuid4().hex + '/performance-snapshot'
    ).status == 404, 'Missing order fails closed'
    text = page.locator('body').inner_text()
    assert 'R$ 980,00' in text and 'R$ 370,00' in text
    assert 'Ativação única' in text
    assert page.locator('[name=sandbox_ack]').count() == 1

    def csrf():
        return page.locator('[name=_token]').first.input_value()
    def revision():
        return page.locator('[name=revision]').first.input_value()

    # Forbidden amount_cents POST is already proven by the generic
    # sandbox checkout suite and the unchanged controller whitelist.
    # Do not consume another shared per-IP rate-limit slot here.
    def submit_sandbox(label):
        for attempt in range(5):
            # Reload after a 429 without reusing form state or CSRF token.
            response = page.goto(url)
            assert response is not None and response.status == 200
            page.locator('[name=sandbox_ack]').check()
            with page.expect_response(
                lambda response: response.request.method == 'POST'
                    and response.url.endswith('/sandbox')
            ) as pending:
                page.get_by_role('button',name=label,exact=True).click()
            accepted=pending.value
            if accepted.status == 429:
                header=accepted.headers.get('retry-after','60')
                assert header.isdecimal(), 'Bounded Retry-After required'
                seconds=int(header)
                assert 0<=seconds<=90, 'Unexpected throttle delay'
                print('F423: respecting application Retry-After for sandbox POST')
                time.sleep(max(1,seconds+1))
                continue
            assert accepted.status in (302,303), (
                'F423 activation POST refused (HTTP '+str(accepted.status)+')'
            )
            return
        raise AssertionError('F423 sandbox POST refused after bounded rate-limit retries')

    submit_sandbox('Gerar cobrança no Asaas sandbox')
    assert page.locator('[name=outcome]').count() == 0
    db = Database()
    rows = [row for row in db.rows() if row['order_id'] == oid]
    assert len(rows) == 1, 'Exactly one owned Performance sandbox intent'
    row = rows[0]
    assert row['amount_cents'] == 98000, 'Only the activation is charged'
    assert row['state']=='queued'

    api = PerOrderSyntheticAPI(oid)
    worker = Worker(db,api)
    worker.process(row)
    assert len(api.posts)==2
    payment_requests=[entry[1] for entry in api.posts if entry[0]=='/payments']
    assert len(payment_requests)==1
    payment = payment_requests[0]
    assert payment['value']==980.0
    assert not ({'media_budget','management_fee','planned_spend','ads_spend'} & set(payment)), 'No media or management fields sent to PSP'
    assert payment['externalReference']=='imagine:'+oid+':initial'
    assert api.posts[0][1]['notificationDisabled'] is True
    assert not ({'email','mobilePhone','phone'} & set(api.posts[0][1]))

    page.reload()
    assert page.locator('[name=business]').count()==0, 'Pending means no briefing'
    submit_sandbox('Solicitar atualização do Asaas')
    row = next(x for x in db.rows() if x['order_id']==oid)
    api.payment['status']='RECEIVED'
    worker.process(row)
    page.reload()
    assert page.locator('[name=business]').count()==1, 'Trusted provider confirmation unlocks briefing'
    confirmed = snapshot()
    assert confirmed['purchase_state'] == 'synthetic_confirmed'
    assert confirmed['purchase_evidence'] == 'fake_provider_callback_only'
    assert confirmed['onboarding_state'] == 'awaiting_briefing'
    assert confirmed['approved_briefing_revision'] is None
    assert len(api.posts)==2, 'No duplicated provider writes'
    assert page.locator('form[action$="/future-billing"]').count()==0
    assert page.locator('form[action$="/performance-report"]').count()==0
    assert 'R$ 980,00' in page.locator('body').inner_text()

    # Persist actual briefing submission via the real Portal HTTP form.
    # Submission is NOT an approval and must never mint a channel mandate.
    for field in ('business','goal','audience','materials'):
        page.locator('[name='+field+']').fill('Conteudo ficticio F423 '+field)
    page.get_by_role('button',name='Salvar briefing de teste',exact=True).click()
    submitted = snapshot()
    assert submitted['purchase_state'] == 'synthetic_confirmed'
    assert submitted['onboarding_state'] == 'briefing_submitted_unreviewed'
    assert submitted['briefing_review_state'] == 'not_approved'
    assert submitted['approved_briefing_revision'] is None
    assert submitted['briefing_submission_revision'] > confirmed['order_revision']
    assert len(submitted['briefing_submission_sha256']) == 64
    assert snapshot() == submitted, 'Repeated authenticated GET is stable; no ledger invented'

    # A different signed individual-customer scope cannot read this order.
    other = browser.new_context()
    other_page = other.new_page()
    assert other_page.goto(BASE+'/register').status == 200
    for name,value in [
        ('name','Cliente ficticio de outra empresa'),
        ('company','Empresa sintética B'),
        ('username','demo-f423-other-'+uuid.uuid4().hex[:10]),
        ('password','ci-other-customer-only-2026'),
        ('password_confirmation','ci-other-customer-only-2026'),
    ]:
        other_page.locator('[name='+name+']').fill(value)
    other_page.get_by_role('button',name='Criar conta e continuar',exact=True).click()
    assert '/client/' in other_page.url
    assert other.request.get(snapshot_url).status == 404, 'Cross-customer order read blocked by signed FORCE RLS'
    other.close()
    browser.close()
print('PASS F423: live Portal HTTP RLS-scoped order/briefing readback, fake payment and per-channel NO-GO; not Performance S2S or persisted decision.')
