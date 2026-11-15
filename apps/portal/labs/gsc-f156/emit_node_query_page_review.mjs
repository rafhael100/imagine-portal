/**
 * GSC-F156 — Node RC32 (immutable SHA) to PHP F155 contract parity.
 * Synthetic data only; no credentials, network or live account input.
 */
import {createHash} from 'node:crypto';
import {planOfflineGscQueryPage} from '../../../../.qa/seo-rc32/src/gsc-query-page-planner.mjs';
import {prepareOfflinePortalQueryPageReview} from '../../../../.qa/seo-rc32/src/portal-query-page-review.mjs';

const sourceRevision='f46b65aaef9b10e32d02e415907cd916a43b548e';
const property='sc-domain:example.com';
const mode=process.argv[2];
if(process.argv.length!==3||!['complete','redacted','partial','empty','multi','multi_redacted'].includes(mode))
  throw Error('F156: invalid synthetic scenario');
const row=(term,page,clicks)=>({
  keys:[term,'https://example.com/'+page],
  clicks,impressions:100,ctr:clicks/100,position:8
});
const config={tenantRef:'imagine-demo',siteUrl:property,
  startDate:'2026-10-01',endDate:'2026-10-07',
  authorizationConfirmed:true,rowLimit:mode==='partial'||mode.startsWith('multi')?2:3,startRow:0};
const normal=[row('SYNTHETIC-PRIVATE-F156','first',6),
  row('SYNTHETIC-PRIVATE-F156','second',5)];
const rows=mode==='empty'?[]:mode==='redacted'?
  [normal[0],row('123.456.789-09','second',5)]:normal;
const pages=mode.startsWith('multi')?[
  {startRow:0,rows:[row('SYNTHETIC-PRIVATE-F156','first',6),
    row('OTHER-PRIVATE-F156','second',5)],responseAggregationType:'byPage'},
  {startRow:2,rows:mode==='multi_redacted'?
    [{...row('SYNTHETIC-PRIVATE-F156','private',4),
      keys:['SYNTHETIC-PRIVATE-F156',
        'https://example.com/private?session=SYNTHETIC-SECRET-F156']}]:
    [row('SYNTHETIC-PRIVATE-F156','third',4)],
    responseAggregationType:'byPage'}
]:[{startRow:0,rows,responseAggregationType:'byPage'}];
const report=prepareOfflinePortalQueryPageReview({
  sourceRevision,
  plan:planOfflineGscQueryPage(config),
  pages
});
if(report.scope.propertyFingerprint!==createHash('sha256').update(property).digest('hex')||
  report.scope.sourceRevision!==sourceRevision||
  report.oauthVerified!==false||report.backendTenantGrantVerified!==false||
  report.customerDisplayAuthorized!==false||report.publicationAuthorized!==false||
  report.networkPerformed!==false||report.releaseStatus!=='HOLD_EXTERNAL_AUTHORIZATION')
  throw Error('F156: unsafe synthetic envelope');
const json=JSON.stringify(report);
if(/SYNTHETIC-PRIVATE|OTHER-PRIVATE|SYNTHETIC-SECRET|123\.456\.789-09|https:\/\/example\.com|\/first|\/second|\/third/.test(json))
  throw Error('F156: private synthetic terms reached output');
process.stdout.write(json+'\n');
