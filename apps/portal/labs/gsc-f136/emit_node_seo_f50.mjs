/**
 * GSC-F136: prepare one fully synthetic F50 payload using the real,
 * pinned Imagine SEO Engine source checked out inside CI.
 */
import {planGscQuery} from '../../../../.qa/seo-engine/src/gsc-readonly.mjs';
import {reviewOfflineGscReadiness} from '../../../../.qa/seo-engine/src/gsc-offline-workflow.mjs';
const property='sc-domain:example.com';
const plan=planGscQuery({
  tenantRef:'imagine-demo',siteUrl:property,
  startDate:'2026-10-01',endDate:'2026-10-07',
  authorizationConfirmed:true,rowLimit:10
});
const output=reviewOfflineGscReadiness({
  tenantRef:'imagine-demo',
  requestedProperty:property,
  listedProperties:[{siteUrl:property,permissionLevel:'siteOwner'}],
  plan,
  pages:[{startRow:0,rows:[
    {keys:['seo para empresas'],clicks:2,impressions:10,ctr:.2,position:8},
    {keys:['person@example.com'],clicks:1,impressions:10,ctr:.1,position:6}
  ]}]
});
if(output.summary?.redactedRows!==1 ||
  output.releaseStatus!=='HOLD_EXTERNAL_BACKEND_VERIFICATION' ||
  output.customerDisplayAuthorized!==false)
  throw Error('GSC-F136 synthetic SEO contract mismatch');
const json=JSON.stringify(output);
if(json.includes('person@example.com')||json.includes('seo para empresas'))
  throw Error('GSC-F136 sensitive raw query emitted');
process.stdout.write(json+'\n');
