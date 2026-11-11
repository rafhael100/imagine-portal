import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,symlinkSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * GSC-F160 — actual CLI negative tests for the F159 synthetic-only renderer.
 * This test never reaches production or network and leaves no private files.
 */
const renderer=fileURLToPath(new URL('../../tools/f159-render-gsc-query-page-review-offline.php',import.meta.url));
const source=fileURLToPath(new URL('../gsc-f156/emit_node_query_page_review.mjs',import.meta.url));
const good=spawnSync(process.execPath,[source,'complete'],{
  encoding:'utf8',timeout:15000
});
assert.equal(good.status,0,'F160 synthetic evidence generation failed');
assert.ok(good.stdout.startsWith('{'));
const env={
  ...process.env,
  APP_ENV:'testing',
  PORTAL_GSC_QUERY_PAGE_REVIEW_LAB:'1',
  PORTAL_GSC_REVIEW_HTML_LAB:'1'
};
const run=(path,overrides={},extra=[])=>spawnSync('php',[renderer,path,...extra],{
  env:{...env,...overrides},encoding:'utf8',timeout:15000
});
const dir=mkdtempSync(join(tmpdir(),'seo-f160-'));
const file=join(dir,'SECRET-F160.json');
const alias=join(dir,'alias-F160.json');
try{
  writeFileSync(file,good.stdout);
  const ok=run(file);
  assert.equal(ok.status,0,ok.stderr);
  assert.match(ok.stdout,/DEMONSTRAÇÃO NÃO HOMOLOGADA/);
  assert.match(ok.stdout,/noindex,nofollow/);
  assert.match(ok.stdout,/connect-src 'none'/);
  assert.doesNotMatch(ok.stdout+ok.stderr,/SYNTHETIC-PRIVATE|SECRET-F160|https:\/\/example\.com/);

  const failed=[
    run(file,{APP_ENV:'production'}),
    run(file,{PORTAL_GSC_QUERY_PAGE_REVIEW_LAB:'0'}),
    run(file,{PORTAL_GSC_REVIEW_HTML_LAB:'0'}),
    run(file,{},['--publish'])
  ];
  symlinkSync(file,alias);
  failed.push(run(alias));
  const altered=JSON.parse(good.stdout);
  altered.customerDisplayAuthorized=true;
  writeFileSync(file,JSON.stringify(altered));
  failed.push(run(file));
  altered.customerDisplayAuthorized=false;
  altered.rawQuery='PRIVATE_EMAIL_F160@example.com';
  writeFileSync(file,JSON.stringify(altered));
  failed.push(run(file));
  writeFileSync(file,'{"unsafe":"PRIVATE_TOKEN_F160",}');
  failed.push(run(file));
  writeFileSync(file,'X'.repeat(65537));
  failed.push(run(file));

  for(const result of failed){
    assert.notEqual(result.status,0,'F160 unsafe CLI input was accepted');
    assert.doesNotMatch(result.stdout+result.stderr,
      /SECRET-F160|alias-F160|PRIVATE_EMAIL_F160|PRIVATE_TOKEN_F160|SYNTHETIC-PRIVATE|https:\/\/example\.com/);
    assert.doesNotMatch(result.stdout,/<html[\s>]/i);
  }
  process.stdout.write('GSC-F160: renderer fails closed without leaking synthetic inputs\n');
}finally{
  rmSync(dir,{force:true,recursive:true});
}
