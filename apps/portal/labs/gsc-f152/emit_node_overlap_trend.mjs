import {createHash} from 'node:crypto';
import {compareOfflineGscQueryPageOverlapPeriods}
  from '../../../../.qa/seo-engine/src/gsc-query-page-overlap-trend.mjs';

const property='sc-domain:example.com';
const base={tenantRef:'imagine-demo',siteUrl:property,
  propertyFingerprint:createHash('sha256').update(property).digest('hex'),
  rowLimit:30};
function response(overlaps){
  const rows=[];
  for(let i=0;i<10;i++){
    const query='SYNTHETIC-QUERY-'+i;
    rows.push({keys:[query,'https://www.example.com/p-'+i],
      clicks:0,impressions:100,ctr:0,position:8});
    if(i<overlaps)rows.push({keys:[query,'https://www.example.com/other-'+i],
      clicks:0,impressions:100,ctr:0,position:8});
  }
  rows.forEach((row,i)=>{
    row.clicks=99-i;
    row.ctr=row.clicks/100;
  });
  return {rows};
}
const result=compareOfflineGscQueryPageOverlapPeriods({
  previous:{
    scope:{...base,startDate:'2026-10-01',endDate:'2026-10-07'},
    response:response(1)
  },
  current:{
    scope:{...base,startDate:'2026-10-08',endDate:'2026-10-14'},
    response:response(5)
  }
});
if(result.previous.overlapShare!==.1||
  result.current.overlapShare!==.5||
  result.overlapShareChange!==.4||
  result.reviewSignals[0]!=='review_overlap_share_increase'||
  result.customerDisplayAuthorized!==false||
  result.networkPerformed!==false)
  throw Error('GSC-F152: synthetic overlap trend mismatch');
const json=JSON.stringify(result);
if(/SYNTHETIC-QUERY|https:\/\/www\.example\.com/.test(json))
  throw Error('GSC-F152: source identifiers leaked');
process.stdout.write(json+'\n');
