import {createHash} from 'node:crypto';
import {reviewOfflineGscQueryPageOverlap} from '../../../../.qa/seo-engine/src/gsc-query-page-overlap.mjs';

const property='sc-domain:example.com';
const scope={tenantRef:'imagine-demo',siteUrl:property,
  propertyFingerprint:createHash('sha256').update(property).digest('hex'),
  startDate:'2026-10-01',endDate:'2026-10-07',rowLimit:10};
const row=(query,page,clicks)=>({
  keys:[query,'https://www.example.com/'+page],
  clicks,impressions:100,ctr:clicks/100,position:7
});
const result=reviewOfflineGscQueryPageOverlap({
  rows:[
    row('landing page empresarial','a',5),
    row('landing page empresarial','b',4),
    row('pessoa@example.com','sensitive',3),
    row('seo','home',2)
  ],
  responseAggregationType:'byPage',
  metadata:{}
},scope);
if(result.summary.queriesOnMultiplePages!==1||
  result.summary.redactedRows!==1||
  result.reviewStatus!=='POSSIBLE_QUERY_PAGE_OVERLAP_REVIEW'||
  result.productionReadAuthorized!==false)
  throw Error('GSC-F141: synthetic query/page count mismatch');
const json=JSON.stringify(result);
if(/landing page empresarial|pessoa@example.com|https:\/\/www\.example\.com/.test(json))
  throw Error('GSC-F141: private query/page leaked');
process.stdout.write(json+'\n');
