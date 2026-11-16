import {createHash} from 'node:crypto';
import {reviewOfflineGscQueryPagePagination}
  from '../../../../.qa/seo-rc32/src/gsc-query-page-pagination.mjs';

const property='sc-domain:example.com';
const scope={
  tenantRef:'imagine-demo',siteUrl:property,
  propertyFingerprint:createHash('sha256').update(property).digest('hex'),
  startDate:'2026-10-01',endDate:'2026-10-07',rowLimit:2
};
const row=(query,path,clicks)=>({
  keys:[query,'https://www.example.com/'+path],
  clicks,impressions:100,ctr:clicks/100,position:8
});
const result=reviewOfflineGscQueryPagePagination([
  {startRow:0,rows:[
    row('sites profissionais','a',5),
    row('redacao digital','b',4)
  ]},
  {startRow:2,rows:[
    row('sites profissionais','c',3),
    row('consulta empresarial','secret?token=PERSON-SECRET-F149',2)
  ]}
],scope);
if(result.summary.queriesOnMultiplePages!==1 ||
  result.summary.redactedRows!==1||
  result.pagination.pagesReceived!==2||
  result.pagination.nextStartRow!==4||
  result.pagination.mayHaveMore!==true||
  result.customerDisplayAuthorized!==false||
  result.releaseStatus!=='HOLD_EXTERNAL_BACKEND_VERIFICATION')
  throw Error('GSC-F143: synthetic two-page report mismatch');
const json=JSON.stringify(result);
if(/sites profissionais|PERSON-SECRET-F149|consulta empresarial|https:\/\/www\.example\.com/.test(json))
  throw Error('GSC-F143: private input leaked in output');
process.stdout.write(json+'\n');
