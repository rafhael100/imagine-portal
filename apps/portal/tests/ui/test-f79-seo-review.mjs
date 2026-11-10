import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {readFileSync, writeFileSync, mkdtempSync, rmSync} from 'node:fs';
import {join} from 'node:path';
import {tmpdir} from 'node:os';

const fixture = 'apps/portal/tests/Fixtures/seo-review-offline-v1.json';
const script = 'apps/portal/tools/f79-render-seo-review.php';
const env = {...process.env, APP_ENV:'testing', PORTAL_SEO_HANDOFF_LAB:'1', PORTAL_F79_HTML_LAB:'1'};
const run = (input=fixture, settings=env) => spawnSync('php', [script,input], {env:settings,encoding:'utf8'});

const result = run();
assert.equal(result.status,0,result.stderr);
const html = result.stdout;
for (const [label,passed] of [
    ['Portuguese locale',html.includes('lang="pt-BR"')],
    ['deny network CSP',html.includes("default-src 'none'") && html.includes("connect-src 'none'")],
    ['untrusted review flag',html.includes('DEMONSTRAÇÃO NÃO HOMOLOGADA')],
    ['no GSC permission',html.includes('sem consentimento de cliente')],
    ['technical warning',html.includes('Avisos técnicos (DEMO)')],
    ['organic values',html.includes('Cliques fictícios') && html.includes('Impressões fictícias')],
    ['negative trends',html.includes('-50.0%') && html.includes('-20.0%')],
    ['review item',html.includes('Revisar metadados')],
    ['no script',!/<script(?:\s|>)/i.test(html)],
    ['no form',!/<form(?:\s|>)/i.test(html)],
    ['no hyperlinks',!/<a\s|<link(?:\s|>)|https?:\/\//i.test(html)],
    ['no remote media',!/<(?:img|iframe|video)(?:\s|>)/i.test(html)],
]) assert.equal(passed,true,'F79 '+label);

for (const settings of [
    {...env,APP_ENV:'production'},
    {...env,PORTAL_SEO_HANDOFF_LAB:'0'},
    {...env,PORTAL_F79_HTML_LAB:'0'},
]) assert.notEqual(run(fixture,settings).status,0,'F79 must fail outside offline test mode');

const directory = mkdtempSync(join(tmpdir(),'portal-seo-f79-'));
try {
    const base = JSON.parse(readFileSync(fixture,'utf8'));
    for (const [name,mutated] of [
        ['fake authorization',{...base,customerDisplayAuthorized:true}],
        ['fake property consent',{...base,propertyAuthorizationVerified:true}],
        ['real tenant',{...base,scope:{...base.scope,tenantRef:'customer-123'}}],
        ['unexpected token',{...base,accessToken:'fake-token'}],
        ['forged count',{...base,technical:{...base.technical,warnings:999999999}}],
        ['script injection',{...base,suggestedReviews:[{...base.suggestedReviews[0],code:'<script>alert(1)</script>'}]}],
        ['unknown version',{...base,contractVersion:'seo-portal-review-handoff-v2'}],
    ]) {
        const file = join(directory,name.replaceAll(' ','-')+'.json');
        writeFileSync(file,JSON.stringify(mutated));
        assert.notEqual(run(file).status,0,'F79 must reject '+name);
    }
    const missing = join(directory,'missing.json');
    assert.notEqual(run(missing).status,0,'missing fixture must fail');
} finally {
    rmSync(directory,{recursive:true,force:true});
}
console.log('PASS F79 SEO offline read-only HTML and fail-closed input checks');
