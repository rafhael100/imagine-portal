/**
 * GSC-F140 — prove SEO F56 executive brief reaches the existing Portal
 * handoff without introducing a second presentation schema.
 */
import {buildOfflineDailySeoBrief} from '../../../../.qa/seo-engine/src/gsc-daily-brief.mjs';
import {preparePortalSeoHandoff} from '../../../../.qa/seo-engine/src/portal-handoff.mjs';

const scope=(startDate,endDate)=>({
  tenantRef:'imagine-demo',propertyFingerprint:'a'.repeat(64),
  startDate,endDate,rowLimit:92
});
const row=(date,clicks,impressions,position)=>({
  keys:[date],clicks,impressions,ctr:clicks/impressions,position
});
const audit={
  summary:{pages:3,errors:1,warnings:0},
  errors:[{
    type:'wrong_canonical',
    file:'private/customer-report.html',
    message:'PERSONAL-PRIVATE-RECORD'
  }],
  warnings:[],
  routes:['/','/private/customer-report.html']
};
const comparisonInput={
  previous:{scope:scope('2026-10-01','2026-10-02'),response:{rows:[
    row('2026-10-01',30,250,5),
    row('2026-10-02',30,250,5)
  ]}},
  current:{scope:scope('2026-10-03','2026-10-04'),response:{rows:[
    row('2026-10-03',10,100,9),
    row('2026-10-04',10,100,9)
  ]}}
};
const brief=buildOfflineDailySeoBrief({audit,comparisonInput});
const envelope=preparePortalSeoHandoff({
  tenantRef:'imagine-demo',
  propertyFingerprint:'a'.repeat(64),
  sourceRevision:'b'.repeat(40),
  brief
});
if(envelope.contractVersion!=='seo-portal-review-handoff-v1'||
  envelope.customerDisplayAuthorized!==false||
  envelope.organic?.previous?.impressions!==500||
  envelope.organic?.current?.impressions!==200||
  !envelope.suggestedReviews.some(x=>x.code==='repair_canonical')||
  !envelope.suggestedReviews.some(x=>x.code==='review_impression_drop'))
  throw Error('GSC-F140: executive brief contract parity failed');
const json=JSON.stringify(envelope);
if(/PERSONAL-PRIVATE-RECORD|private\/customer-report|Google OAuth access_token/i.test(json))
  throw Error('GSC-F140: private text leaked');
process.stdout.write(json+'\n');
