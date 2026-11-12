/**
 * GSC Slice E — cross-version privacy display parity, RC32 F74 DTO and
 * SEO-F79 view policy against Portal F159 static PHP HTML renderer.
 * Test-only synthetic fixture. No OAuth, network, customer data or deploy.
 */
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {planOfflineGscQueryPage} from '../../../../.qa/seo-rc32/src/gsc-query-page-planner.mjs';
import {prepareOfflinePortalQueryPageReview as fromRC32}
  from '../../../../.qa/seo-rc32/src/portal-query-page-review.mjs';
import {prepareOfflinePortalQueryPageDisplayModel as fromF79}
  from '../../../../.qa/seo-f79/src/portal-query-page-display-model.mjs';

const render=fileURLToPath(
  new URL('../../tools/f159-render-gsc-query-page-review-offline.php',import.meta.url));
const sha='fdae08985e89b9bd1323e42d1d64678ddc69e446';
const row=(query,path,clicks)=>({
  keys:[query,'https://example.com/'+path],
  clicks,impressions:100,ctr:clicks/100,position:8
});
const make=mode=>{
  const pageSize=(mode==='partial'||mode==='multi')?2:3;
  const config={tenantRef:'imagine-demo',siteUrl:'sc-domain:example.com',
    startDate:'2026-10-01',endDate:'2026-10-07',
    authorizationConfirmed:true,rowLimit:pageSize,startRow:0};
  const rows=[
    row('PRIVATE-F79-PARITY','page-first',6),
    row(mode==='redacted'?'123.456.789-09':'PRIVATE-F79-PARITY','page-second',5)
  ];
  const pages=mode==='empty'?
    [{startRow:0,rows:[],responseAggregationType:'byPage'}]:
    mode==='multi'?
    [{startRow:0,rows:[
      row('PRIVATE-F79-PARITY','page-first',6),
      row('ANOTHER-F79-PARITY','page-second',5)
    ],responseAggregationType:'byPage'},
    {startRow:2,rows:[row('PRIVATE-F79-PARITY','page-third',4)],
      responseAggregationType:'byPage'}]:
    [{startRow:0,rows,responseAggregationType:'byPage'}];
  return {sourceRevision:sha,plan:planOfflineGscQueryPage(config),pages};
};
const readStat=(html,label)=>{
  const needle='<h2>'+label+'</h2><strong class="stat">';
  const start=html.indexOf(needle);
  assert.ok(start>=0,'missing metric '+label);
  const tail=html.slice(start+needle.length);
  const end=tail.indexOf('</strong>');
  assert.ok(end>=0);
  return tail.slice(0,end);
};
const dir=mkdtempSync(join(tmpdir(),'portal-gsc-e-'));
try{
  const evidencePath=join(dir,'synthetic-review.json');
  for(const mode of ['complete','redacted','partial','multi','empty']){
    const input=make(mode);
    const raw=fromRC32(input),safe=fromF79(input);
    assert.equal(raw.coverageStatus,safe.coverageStatus,mode);
    assert.equal(raw.summary.receivedRows,safe.summary.receivedRows);
    assert.equal(raw.summary.redactedRows,safe.summary.redactedRows);
    assert.equal(safe.customerDisplayAuthorized,false);
    assert.equal(safe.publicationAuthorized,false);
    assert.equal(safe.oauthVerified,false);
    assert.equal(safe.networkPerformed,false);
    assert.equal(safe.releaseStatus,'HOLD_EXTERNAL_AUTHORIZATION');
    assert.equal(safe.metricsWithheld,
      mode==='redacted'||mode==='partial',mode);
    assert.equal(safe.summary.queriesOnMultiplePages,
      safe.metricsWithheld?null:raw.summary.queriesOnMultiplePages);

    writeFileSync(evidencePath,JSON.stringify(raw));
    const result=spawnSync('php',[render,evidencePath],{
      encoding:'utf8',timeout:15000,env:{
        ...process.env,
        APP_ENV:'testing',
        PORTAL_GSC_QUERY_PAGE_REVIEW_LAB:'1',
        PORTAL_GSC_REVIEW_HTML_LAB:'1'
      }
    });
    assert.equal(result.status,0,mode+': '+result.stderr);
    const shownQueries=readStat(result.stdout,'Consultas visíveis');
    const shownOverlaps=readStat(result.stdout,'Consultas em múltiplas páginas');
    if(safe.metricsWithheld){
      assert.equal(shownQueries,'Indisponível',mode);
      assert.equal(shownOverlaps,'Indisponível',mode);
      assert.deepEqual(safe.suggestedReviews,[]);
    }else{
      assert.equal(shownQueries,String(safe.summary.distinctQueries),mode);
      assert.equal(shownOverlaps,String(safe.summary.queriesOnMultiplePages),mode);
    }
    assert.doesNotMatch(result.stdout+JSON.stringify(safe),
      /PRIVATE-F79-PARITY|ANOTHER-F79-PARITY|123\.456\.789-09|https:\/\/example\.com/);
    assert.match(result.stdout,/DEMONSTRAÇÃO NÃO HOMOLOGADA/);
    assert.doesNotMatch(result.stdout,/<(script|form|a)([ >])/i);
  }
  process.stdout.write('GSC Slice E: RC32/F79 display policy and PHP F159 parity passed\n');
}finally{
  rmSync(dir,{recursive:true,force:true});
}
