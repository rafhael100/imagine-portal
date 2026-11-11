import {compareOfflineGscDailyPeriods} from '../../../../.qa/seo-engine/src/gsc-daily-compare.mjs';

const scope=(startDate,endDate)=>({
  tenantRef:'imagine-demo',propertyFingerprint:'a'.repeat(64),
  startDate,endDate,rowLimit:92
});
const row=(date,clicks,impressions,position)=>({
  keys:[date],clicks,impressions,ctr:clicks/impressions,position
});
const comparison=compareOfflineGscDailyPeriods({
  previous:{
    scope:scope('2026-10-01','2026-10-02'),
    response:{rows:[
      row('2026-10-01',30,250,5),
      row('2026-10-02',30,250,5)
    ]}
  },
  current:{
    scope:scope('2026-10-03','2026-10-04'),
    response:{rows:[
      row('2026-10-03',10,100,9),
      row('2026-10-04',10,100,9)
    ]}
  }
});
if(comparison.coverageComplete!==true||
  comparison.change.impressionsFraction!==-.6||
  comparison.reviewSignals.includes('review_click_drop')!==true||
  comparison.releaseStatus!=='HOLD_EXTERNAL_BACKEND_VERIFICATION')
  throw Error('GSC-F138: synthetic F54 report invalid');
process.stdout.write(JSON.stringify(comparison)+'\n');
