/**
 * Portal GSC Slice F — synthetic original-source SEO-F88 to PHP contract.
 * Reads only a commit-pinned local module; never contacts Google or writes data.
 */
import {prepareOfflinePortalSeoFromOriginalGsc}
  from '../../../../.qa/seo-f88/src/portal-original-gsc-handoff.mjs';

const mode=process.argv[2];
if(process.argv.length!==3||!['complete','redacted'].includes(mode)){
  process.stderr.write('GSC Slice F: expected complete|redacted synthetic mode\n');
  process.exit(2);
}
const make=(query,clicks,impressions)=>({
  keys:[query],clicks,impressions,ctr:clicks/impressions,position:8
});
const input={
  audit:{
    summary:{pages:2,errors:1,warnings:0},
    errors:[{
      type:'wrong_canonical',
      file:'SYNTHETIC-SLICE-F-PRIVATE-PATH.html',
      message:'SYNTHETIC-SLICE-F-PRIVATE-MESSAGE'
    }],
    warnings:[]
  },
  previous:{
    dimensions:['query'],startDate:'2026-10-01',endDate:'2026-10-07',
    rows:[make('SYNTHETIC-SLICE-F-PRIVATE-QUERY landing pages',30,300)]
  },
  current:{
    dimensions:['query'],startDate:'2026-10-08',endDate:'2026-10-14',
    rows:[make('SYNTHETIC-SLICE-F-PRIVATE-QUERY landing pages',8,180),
      ...(mode==='redacted'?[make('123.456.789-09',1,100)]:[])]
  },
  scope:{
    tenantRef:'imagine-demo',propertyFingerprint:'a'.repeat(64),
    sourceRevision:'b'.repeat(40)
  }
};
const out=prepareOfflinePortalSeoFromOriginalGsc(input);
const encoded=JSON.stringify(out);
if(/SYNTHETIC-SLICE-F-PRIVATE|123\.456\.789-09/.test(encoded))
  throw Error('GSC Slice F: private source leaked into output');
process.stdout.write(encoded+'\n');
