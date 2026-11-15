/**
 * GSC-F157 — read-only synthetic SEO RC32 F67 -> Portal F152 parity.
 * No provider HTTP or private query/URL artifacts are emitted.
 */
import {createHash} from 'node:crypto';
import {compareOfflineGscQueryPageOverlapPeriods as compare}
  from '../../../../.qa/seo-rc32/src/gsc-query-page-overlap-trend.mjs';

const mode=process.argv[2];
if(process.argv.length!==3||!['complete','redacted','partial'].includes(mode))
  throw Error('GSC-F157: unrecognized synthetic fixture');
const property='sc-domain:example.com';
const scope={tenantRef:'imagine-demo',siteUrl:property,
  propertyFingerprint:createHash('sha256').update(property).digest('hex'),
  rowLimit:30};
function response(overlaps){
  const rows=[];
  for(let i=0;i<10;i++){
    const query='SYNTHETIC-PRIVATE-QUERY-F157-'+i;
    rows.push({keys:[query,'https://www.example.com/p-'+i],
      clicks:0,impressions:100,ctr:0,position:8});
    if(i<overlaps)rows.push({keys:[query,'https://www.example.com/other-'+i],
      clicks:0,impressions:100,ctr:0,position:8});
  }
  rows.forEach((row,i)=>{row.clicks=99-i;row.ctr=row.clicks/100;});
  return {rows};
}
const previous=response(1),current=response(5);
if(mode==='redacted')current.rows[0].keys[0]='123.456.789-09';
const input={
  previous:{
    scope:{...scope,startDate:'2026-10-01',endDate:'2026-10-07'},
    response:previous
  },
  current:{
    scope:{...scope,startDate:'2026-10-08',endDate:'2026-10-14',
      rowLimit:mode==='partial'?current.rows.length:30},
    response:current
  }
};
const result=compare(input);
if(result.oauthVerified!==false||result.customerDisplayAuthorized!==false||
  result.productionReadAuthorized!==false||result.networkPerformed!==false||
  result.releaseStatus!=='HOLD_EXTERNAL_BACKEND_VERIFICATION')
  throw Error('GSC-F157: untrusted provider claim');
const json=JSON.stringify(result);
if(/SYNTHETIC-PRIVATE|123\.456\.789-09|https:\/\/www\.example\.com/.test(json))
  throw Error('GSC-F157: source data leaked');
process.stdout.write(json+'\n');
