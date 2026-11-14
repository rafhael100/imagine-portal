/**
 * Test-only cross-repository emitter. The exact SEO Engine SHA is pinned in
 * GitHub Actions; all data is synthetic and belongs to example.com.
 */
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';

const scenario=process.argv[2];
if(!['complete','partial','empty'].includes(scenario)||process.argv.length!==3)
  throw Error('Portal SEO F104 bridge: fixed fixture scenario required');
const modulePath=pathToFileURL(
  resolve('.qa/seo-f104/src/openseo-daily-bridge.mjs')
).href;
const {reviewOfflineOpenSeoDailySnapshot}=await import(modulePath);

const row=(date,clicks,impressions,position)=>({
  keys:[date],clicks,impressions,
  ctr:impressions?clicks/impressions:0,
  position:impressions?position:null
});
const data={
  complete:{
    startDate:'2026-10-01',endDate:'2026-10-02',
    rows:[row('2026-10-01',2,20,8),row('2026-10-02',1,10,9)]
  },
  partial:{
    startDate:'2026-10-01',endDate:'2026-10-03',
    rows:[row('2026-10-01',2,20,8),row('2026-10-03',1,10,9)]
  },
  empty:{
    startDate:'2026-10-01',endDate:'2026-10-02',rows:[]
  }
}[scenario];
const dto=reviewOfflineOpenSeoDailySnapshot({
  tenantRef:'imagine-demo',
  expectedProperty:'sc-domain:example.com',
  snapshot:{
    siteUrl:'sc-domain:example.com',
    startDate:data.startDate,
    endDate:data.endDate,
    dimensions:['date'],
    rows:data.rows,
    rowCount:data.rows.length,
    hasMore:false
  }
});
process.stdout.write(JSON.stringify(dto)+'\n');
