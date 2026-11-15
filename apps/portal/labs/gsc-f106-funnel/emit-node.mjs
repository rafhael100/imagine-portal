/**
 * Portal SEO F106 — immutable cross-repo synthetic fixture emitter.
 * SOURCE SHA fixed in GitHub Actions to 7a072c...; never provider data.
 */
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';

const scenario=process.argv[2];
if(process.argv.length!==3||!['complete','partial','zero'].includes(scenario))
  throw Error('SEO F106 Portal lab: fixed synthetic scenario required');

const modulePath=pathToFileURL(
  resolve('.qa/seo-f106/src/seo-funnel-preview.mjs')
).href;
const {prepareOfflineSeoFunnelPreview}=await import(modulePath);

const row=(date,clicks,impressions)=>({
  keys:[date],clicks,impressions,
  ctr:impressions?clicks/impressions:0,
  position:impressions?8:null
});
function original(startDate,endDate,rows){
  return {
    tenantRef:'demo-tenant',
    expectedProperty:'sc-domain:example.com',
    snapshot:{
      siteUrl:'sc-domain:example.com',
      startDate,endDate,
      dimensions:['date'],rows,rowCount:rows.length,hasMore:false
    }
  };
}
const before=[
  row('2026-10-01',2,20),row('2026-10-02',1,10)
];
const after=scenario==='zero'?[
  row('2026-10-03',0,10),row('2026-10-04',0,10)
]:[
  row('2026-10-03',1,10),row('2026-10-04',2,20)
];
const dto=prepareOfflineSeoFunnelPreview({
  comparisonInput:{
    previous:original('2026-10-01','2026-10-02',
      scenario==='partial'?before.slice(0,1):before),
    current:original('2026-10-03','2026-10-04',after)
  }
});
process.stdout.write(JSON.stringify(dto)+'\n');
