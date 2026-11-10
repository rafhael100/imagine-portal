#!/usr/bin/env node
// PORTAL F71: ephemeral offline bundle (HTML + CSV + integrity receipt).
// Synthetic previews ONLY. No API, publishing, execution, billing, or approval.
import {spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,lstatSync,readFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname,join,resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const fail=()=>{process.stderr.write('F71 rejected unsafe offline review bundle\n');process.exit(2);};
if(process.argv.length!==3 || process.env.APP_ENV!=='testing' ||
  process.env.PORTAL_F64_BUDGET_PREVIEW_LAB!=='1' ||
  process.env.PORTAL_F65_BUDGET_HTML_LAB!=='1' ||
  process.env.PORTAL_F66_BUDGET_CSV_LAB!=='1' ||
  process.env.PORTAL_F69_CSV_INTEGRITY_LAB!=='1' ||
  process.env.PORTAL_F71_REVIEW_BUNDLE_LAB!=='1')fail();
const base=dirname(fileURLToPath(import.meta.url));
const run=(command,args)=>{
  const r=spawnSync(command,args,{
    env:process.env,encoding:null,timeout:15000,maxBuffer:1024*1024
  });
  if(r.error || r.status!==0 || r.stderr.length!==0 ||
    r.stdout.length===0)throw Error('offline tool rejected input');
  return r.stdout;
};
let workspace;
try{
  const input=resolve(process.argv[2]);
  const stat=lstatSync(input);
  if(!stat.isFile() || stat.isSymbolicLink() ||
    stat.size===0 || stat.size>128000)throw Error('invalid DTO');
  const bytes=readFileSync(input);
  // Use the exact same immutable snapshot for both renderers, preventing
  // source-path changes between the HTML and CSV subprocesses.
  workspace=mkdtempSync(join(tmpdir(),'portal-f71-'));
  const dto=join(workspace,'input.json');
  writeFileSync(dto,bytes,{mode:0o600,flag:'wx'});
  const html=run('php',[join(base,'f65-render-budget-calendar.php'),dto]);
  const csv=run('php',[join(base,'f66-export-budget-calendar-csv.php'),dto]);
  const htmlPath=join(workspace,'preview.html');
  const csvPath=join(workspace,'budget.csv');
  writeFileSync(htmlPath,html,{mode:0o600,flag:'wx'});
  writeFileSync(csvPath,csv,{mode:0o600,flag:'wx'});
  const integrity=JSON.parse(run('node',[join(base,'f69-verify-budget-csv.mjs'),dto,csvPath]));
  if(integrity.simulation_only!==true || integrity.operational_authority!==false ||
    !Number.isInteger(integrity.data_rows))throw Error('integrity report invalid');
  const manifest={
    schema_version:'portal.f71.offline-review-bundle.synthetic.v1',
    simulation_only:true,approved_for_production:false,
    authorized_to_spend:false,authenticated_customer_session:false,
    html_file:'preview.html',csv_file:'budget.csv',integrity,
  };
  writeFileSync(join(workspace,'manifest.json'),
    JSON.stringify(manifest,null,2)+'\n',{mode:0o600,flag:'wx'});
  // Never print a DTO or CSV in logs; paths and diagnostic counts only.
  process.stdout.write(JSON.stringify({workspace,files:[
    'preview.html','budget.csv','manifest.json'],rows:integrity.data_rows})+'\n');
}catch{
  if(workspace)rmSync(workspace,{force:true,recursive:true});
  fail();
}
