from pathlib import Path
import json
import os
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parent
with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH', '/usr/bin/google-chrome'), headless=True, args=['--no-sandbox'])
    page = browser.new_page(viewport={'width':1280,'height':900}, accept_downloads=True)
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.goto((root / 'imagine-jornada-guiada.html').as_uri())
    page.locator('#next').click()
    assert page.locator('#error').inner_text()
    page.locator('#understood').check()
    page.locator('#next').click()
    page.locator('#selected').check()
    page.locator('#next').click()
    page.locator('#company').fill('')
    page.locator('#next').click()
    assert page.locator('#error').inner_text()
    page.locator('#company').fill('<img src=x onerror=alert(1)>')
    page.locator('#notes').fill('Rever orientação do briefing')
    page.locator('#next').click()
    assert page.locator('img').count() == 0
    page.locator('#back').click()
    assert page.locator('#notes').input_value() == 'Rever orientação do briefing'
    page.locator('#company').fill('Ateliê Aurora')
    page.locator('#next').click()
    page.locator('#next').click()
    page.locator('#adjustment').fill('Trocar título por presentes únicos')
    page.locator('#next').click()
    page.locator('#revised').fill('Presentes únicos, feitos para você')
    assert 'Presentes únicos' in page.locator('#revised-preview').inner_text()
    page.locator('#next').click()
    assert page.locator('#error').inner_text()
    page.locator('#approved').check()
    page.locator('#next').click()
    assert page.locator('#progress').get_attribute('value') == '7'
    with page.expect_download() as download:
        page.locator('#next').click()
    report = json.loads(Path(download.value.path()).read_text())
    assert report['sitePublished'] is False and report['simulatedApproval'] is True
    assert report['observations'][2]['note'] == 'Rever orientação do briefing'
    for width in [320, 390, 768, 1280]:
        page.set_viewport_size({'width':width,'height':900})
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), width
    assert not errors, errors
    browser.close()
print('PASS guided journey: complete flow, validation, back navigation, notes, HTML escaping, revised preview, simulated approval, report, four widths.')
