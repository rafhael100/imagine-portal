#!/usr/bin/env node
// PORTAL-F69: independent, offline CSV integrity check against F64-validated
// synthetic DTO. The attestation is diagnostic, not a signature, audit witness,
// customer approval, or authorization to spend or deploy.
import {spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {lstatSync,readFileSync,realpathSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname,resolve} from 'node:path';

const fail=()=>{process.stderr.write('F69 denied invalid or modified offline CSV\n');process.exit(2);};
if(process.env.APP_ENV!=='testing' ||
   process.env.PORTAL_F64_BUDGET_PREVIEW_LAB!=='1' ||
   process.env.PORTAL_F66_BUDGET_CSV_LAB!=='1' ||
   process.env.PORTAL_F69_CSV_INTEGRITY_LAB!=='1' ||
   process.argv.length!==4)fail();

function localFile(path,limit){
  const stat=lstatSync(path);
  if(!stat.isFile() || stat.isSymbolicLink() || stat.size>limit || stat.size===0)throw Error('bad file');
  return readFileSync(path);
}

try {
  const [dtoPath,csvPath]=process.argv.slice(2);
  const dto=localFile(dtoPath,128000);
  const csv=localFile(csvPath,1024*1024);
  const exporter=resolve(dirname(fileURLToPath(import.meta.url)),
    'f66-export-budget-calendar-csv.php');
  if(realpathSync(dtoPath)===realpathSync(csvPath))fail();
  const validated=spawnSync('php',[exporter,dtoPath],{
    env:{...process.env},encoding:null,timeout:12000,maxBuffer:1024*1024,
  });
  if(validated.error || validated.status!==0 || validated.stderr.length!==0 ||
     !validated.stdout.equals(csv))fail();
  const text=csv.toString('utf8');
  if(!text.startsWith('\ufeff') || !text.endsWith('\r\n') ||
     text.includes('\uFFFD') || text.includes('\0'))fail();
  const lines=text.slice(1).split('\r\n');
  if(lines.pop()!=='' || lines.shift()!==
    'cenario_demo;data_utc;google_ads_centavos;meta_ads_centavos;total_centavos')fail();
  for(const row of lines){
    const cells=row.split(';');
    if(cells.length!==5 || !/^scenario-demo-[a-z0-9-]{1,50}$/.test(cells[0])
      || !/^\d{4}-\d{2}-\d{2}$/.test(cells[1])
      || !cells.slice(2).every(s=>/^(0|[1-9][0-9]*)$/.test(s)))fail();
  }
  const hash=bytes=>createHash('sha256').update(bytes).digest('hex');
  // Only hashes and counts, never payloads, tokens or customer identifiers.
  process.stdout.write(JSON.stringify({
    schema_version:'portal.f69.offline-csv-integrity.synthetic.v1',
    simulation_only:true,operational_authority:false,
    dto_sha256:hash(dto),csv_sha256:hash(csv),data_rows:lines.length,
  })+'\n');
} catch {fail();}
