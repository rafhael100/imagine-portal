#!/usr/bin/env python3
"""Chromium actual form click + independent PostgreSQL readback, CI-only."""
import hashlib
import json
import os
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parent
BASE='http://127.0.0.1:8765'
def cli(mode):
    p=subprocess.run(['php',str(ROOT/'setup.php'),mode],check=True,text=True,
                     capture_output=True,env=os.environ.copy())
    return p.stdout.strip()
def read(): return json.loads(cli('readback'))
def login(page,who):
    page.goto(BASE+'/lab/login')
    page.locator('#fixture').select_option(who)
    page.locator('#login').click()
def check_contract(page,rev):
    assert page.locator('h1').inner_text()=='Revisar contrato fictício Landing'
    assert page.locator('#order').inner_text()=='landing-order-001'
    assert page.locator('#contract-ref').inner_text()==read()['contractRef']
    assert page.locator('#revision').inner_text()==str(rev)
    doc=page.locator('#terms').inner_text()
    digest=page.locator('#digest').inner_text()
    assert hashlib.sha256(doc.encode('utf-8')).hexdigest()==digest
    assert read()['terms_sha256']==digest
    return digest
def accept(page):
    page.locator('#checkbox').check()
    page.locator('#confirm').click()
    assert 'LAB_ACCEPTED_ONLY' in page.locator('body').inner_text()

assert os.getenv('APP_ENV')=='testing' and os.getenv('LANDING_EXACT_CLICK_LAB')=='1'
print(cli('seed'))
assert read()['currentAccepted'] is False
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,args=['--no-sandbox'])
    foreign=browser.new_context()
    bad=foreign.new_page()
    login(bad,'buyer-foreign')
    assert bad.locator('body').inner_text().strip()=='LANDING LAB DENIED'
    other=browser.new_context()
    bad2=other.new_page()
    login(bad2,'buyer-B')
    assert bad2.locator('body').inner_text().strip()=='LANDING LAB DENIED'
    assert read()['currentAccepted'] is False
    correct=browser.new_context()
    page=correct.new_page()
    login(page,'buyer-A')
    first_hash=check_contract(page,1)
    assert not page.locator('#checkbox').is_checked()
    hidden={item['name']:item['value'] for item in page.locator('input[type=hidden]').evaluate_all(
        '(nodes) => nodes.map(n => ({name:n.name,value:n.value}))')}
    assert correct.request.post(BASE+'/lab/accept',form=hidden).status==409
    accept(page)
    assert correct.request.post(BASE+'/lab/accept',form={**hidden,'accept':'yes'}).status==409
    result1=read()
    assert result1['currentAccepted'] is True and result1['revision']==1
    page.goto(BASE+'/lab/terms?order=landing-order-001')
    check_contract(page,1)
    print(cli('revise'))
    assert read()['currentAccepted'] is False
    page.locator('#checkbox').check()
    page.locator('#confirm').click()
    assert 'LANDING LAB DENIED' in page.locator('body').inner_text()
    assert read()['currentAccepted'] is False
    page.goto(BASE+'/lab/terms?order=landing-order-001')
    second_hash=check_contract(page,2)
    assert second_hash!=first_hash
    accept(page)
    result2=read()
    assert result2['currentAccepted'] and result2['revision']==2
    assert result2['operationalAuthority'] is False and result2['pspInvoked'] is False
    bad2.goto(BASE+'/lab/terms?order=landing-order-001')
    assert 'LANDING LAB DENIED' in bad2.locator('body').inner_text()
    browser.close()
print('LANDING EXACT CLICK BROWSER PASS: same-order synthetic browser view + explicit checkbox/click + PG18 readback;')
print('REVISION_1_SHA256='+first_hash)
print('REVISION_2_SHA256='+second_hash)
print('CURRENT_REVISION='+str(result2['revision'])+' CURRENT_ACCEPTED='+str(result2['currentAccepted']).lower())
print('OPERATIONS=NO_GO; PSP=NOT_CALLED; APPROVED_BRIEF=NOT_COMPOSED; CUSTOMER_AUTH=FIXTURE_ONLY')
