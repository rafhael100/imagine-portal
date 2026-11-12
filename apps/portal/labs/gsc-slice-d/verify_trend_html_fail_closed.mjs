/**
 * GSC Slice D — fail-closed HTML review policy for SEO RC32 F67 trends.
 * Private test only: synthetic fixture, no tokens, HTTP, browser, or deploy.
 */
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,symlinkSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

const render=fileURLToPath(
  new URL('../../tools/f153-render-gsc-overlap-trend-offline.php',import.meta.url));
const emit=fileURLToPath(
  new URL('../gsc-f157/emit_node_overlap_trend_rc32.mjs',import.meta.url));
const env={...process.env,APP_ENV:'testing',
  PORTAL_GSC_TRENDS_LAB:'1',PORTAL_GSC_TREND_HTML_LAB:'1'};
const run=(path,overrides={},extra=[])=>spawnSync('php',[render,path,...extra],{
  encoding:'utf8',env:{...env,...overrides},timeout:15000
});
const generate=mode=>{
  const p=spawnSync(process.execPath,[emit,mode],{
    encoding:'utf8',timeout:15000});
  assert.equal(p.status,0,'GSC Slice D synthetic emitter failed');
  return p.stdout;
};
const dir=mkdtempSync(join(tmpdir(),'gsc-slice-d-'));
const file=join(dir,'SYNTHETIC-SECRET-FILE.json');
const link=join(dir,'link.json');
const mustReject=result=>{
  assert.notEqual(result.status,0,'unsafe trend was accepted');
  assert.doesNotMatch(result.stdout,/<html[\s>]/i);
  assert.doesNotMatch(result.stdout+result.stderr,
    /SYNTHETIC-SECRET-FILE|SYNTHETIC-SECRET-TERM|SYNTHETIC-SECRET-TOKEN|https:\/\/www\.example\.com/);
};
try{
  for(const scenario of ['complete','redacted','partial']){
    const content=generate(scenario);
    writeFileSync(file,content);
    const ok=run(file);
    assert.equal(ok.status,0,ok.stderr);
    assert.match(ok.stdout,/DEMONSTRAÇÃO NÃO HOMOLOGADA/);
    assert.match(ok.stdout,/noindex,nofollow/);
    assert.match(ok.stdout,/connect-src 'none'/);
    assert.doesNotMatch(ok.stdout,/<(script|form|a)([ >])/i);
    assert.doesNotMatch(ok.stdout,
      /SYNTHETIC-PRIVATE|123\.456\.789-09|https:\/\/www\.example\.com/);
    if(scenario==='complete'){
      assert.match(ok.stdout,/10,0%/);
      assert.match(ok.stdout,/50,0%/);
      assert.match(ok.stdout,/Investigar possível aumento/);
    }else{
      assert.ok((ok.stdout.match(/Indisponível/g)||[]).length>=3,
        'redacted/partial must hide both trend ratios and delta');
      assert.doesNotMatch(ok.stdout,/[0-9]+,[0-9]+%/);
      assert.match(ok.stdout,/Amostra incompleta: tendências suprimidas/);
    }

    mustReject(run(file,{APP_ENV:'production'}));
    mustReject(run(file,{PORTAL_GSC_TRENDS_LAB:'0'}));
    mustReject(run(file,{PORTAL_GSC_TREND_HTML_LAB:'0'}));
    mustReject(run(file,{},['--publish']));
    if(scenario==='complete'){
      symlinkSync(file,link);
      mustReject(run(link));
    }
    const forged=JSON.parse(content);
    forged.oauthVerified=true;
    writeFileSync(file,JSON.stringify(forged));
    mustReject(run(file));
    delete forged.oauthVerified;
    forged.oauthVerified=false;
    forged.rawQuery='SYNTHETIC-SECRET-TERM';
    writeFileSync(file,JSON.stringify(forged));
    mustReject(run(file));
    writeFileSync(file,'{"raw":"SYNTHETIC-SECRET-TOKEN",}');
    mustReject(run(file));
    writeFileSync(file,'X'.repeat(65537));
    mustReject(run(file));
  }
  process.stdout.write('GSC Slice D trend HTML: privacy, input and production guards passed\n');
}finally{
  rmSync(dir,{recursive:true,force:true});
}
