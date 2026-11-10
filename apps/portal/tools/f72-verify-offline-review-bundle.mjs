#!/usr/bin/env node
// F72: verify the three-file synthetic review bundle produced by F71.
// Local hash consistency is NOT a cryptographic signature, antirollback witness,
// evidence of approval or proof that an attacker did not edit the manifest too.
import {createHash} from 'node:crypto';
import {constants,closeSync,fstatSync,lstatSync,openSync,readFileSync,readdirSync} from 'node:fs';
import {basename,dirname,join,resolve} from 'node:path';
import {tmpdir} from 'node:os';

const fail=()=>{process.stderr.write('F72 denied unsafe or modified offline bundle\n');process.exit(2);};
if(process.argv.length!==3 || process.env.APP_ENV!=='testing' ||
  process.env.PORTAL_F72_BUNDLE_VERIFIER_LAB!=='1')fail();
const sha=data=>createHash('sha256').update(data).digest('hex');
const files=['budget.csv','manifest.json','preview.html'];
function readRestricted(root,name,max){
  const path=join(root,name);
  const st=lstatSync(path);
  if(!st.isFile() || st.isSymbolicLink() || st.nlink!==1 ||
    st.size<1 || st.size>max || (st.mode&0o077)!==0)throw Error('unsafe file');
  const fd=openSync(path,constants.O_RDONLY|constants.O_NOFOLLOW);
  try{
    const actual=fstatSync(fd);
    if(!actual.isFile() || actual.nlink!==1 || actual.size!==st.size ||
      actual.ino!==st.ino || actual.dev!==st.dev || (actual.mode&0o077)!==0)
      throw Error('changed file');
    return readFileSync(fd);
  }finally{closeSync(fd);}
}
const hex=v=>typeof v==='string' && /^[a-f0-9]{64}$/.test(v);
const exact=(object,keys)=>object!==null && typeof object==='object' &&
  !Array.isArray(object) && Object.keys(object).sort().join('|')===keys.slice().sort().join('|');
try{
  const root=resolve(process.argv[2]);
  if(dirname(root)!==resolve(tmpdir()) ||
    !/^portal-f71-[a-zA-Z0-9_-]{6,}$/.test(basename(root)))fail();
  const rootStat=lstatSync(root);
  if(!rootStat.isDirectory() || rootStat.isSymbolicLink() ||
    (rootStat.mode&0o077)!==0)fail();
  if(readdirSync(root).sort().join('|')!==files.join('|'))fail();
  const manifestBytes=readRestricted(root,'manifest.json',8192);
  const manifest=JSON.parse(manifestBytes.toString('utf8'));
  if(!exact(manifest,[
    'schema_version','simulation_only','approved_for_production',
    'authorized_to_spend','authenticated_customer_session',
    'html_file','csv_file','html_sha256','source_snapshot_removed','integrity'
  ]) || manifest.schema_version!=='portal.f71.offline-review-bundle.synthetic.v1' ||
    manifest.simulation_only!==true || manifest.approved_for_production!==false ||
    manifest.authorized_to_spend!==false ||
    manifest.authenticated_customer_session!==false ||
    manifest.source_snapshot_removed!==true ||
    manifest.html_file!=='preview.html' || manifest.csv_file!=='budget.csv' ||
    !hex(manifest.html_sha256))fail();
  const receipt=manifest.integrity;
  if(!exact(receipt,['schema_version','simulation_only','operational_authority',
    'dto_sha256','csv_sha256','data_rows']) ||
    receipt.schema_version!=='portal.f69.offline-csv-integrity.synthetic.v1' ||
    receipt.simulation_only!==true || receipt.operational_authority!==false ||
    !hex(receipt.dto_sha256) || !hex(receipt.csv_sha256) ||
    !Number.isInteger(receipt.data_rows) || receipt.data_rows<0 ||
    receipt.data_rows>7320)fail();
  const html=readRestricted(root,'preview.html',1024*1024);
  const csv=readRestricted(root,'budget.csv',1024*1024);
  if(sha(html)!==manifest.html_sha256 || sha(csv)!==receipt.csv_sha256)fail();
  const h=html.toString('utf8');
  if(!h.startsWith('<!doctype html>') || h.includes('\ufffd') ||
    !h.includes('DEMONSTRAÇÃO NÃO HOMOLOGADA') ||
    !h.includes("script-src 'none'") || !h.includes("connect-src 'none'") ||
    /<script\b|<form\b|<iframe\b|<button\b|<img\b|<a\b|<link\b/i.test(h))fail();
  const c=csv.toString('utf8');
  if(!c.startsWith('\ufeff') || !c.endsWith('\r\n') || c.includes('\ufffd') ||
    c.includes('\0'))fail();
  const lines=c.slice(1).split('\r\n');
  if(lines.pop()!=='' || lines.shift()!==
    'cenario_demo;data_utc;google_ads_centavos;meta_ads_centavos;total_centavos' ||
    lines.length!==receipt.data_rows)fail();
  for(const line of lines){
    const row=line.split(';');
    if(row.length!==5 || !/^scenario-demo-[a-z0-9-]{1,50}$/.test(row[0]) ||
      !/^\d{4}-\d{2}-\d{2}$/.test(row[1]) ||
      !row.slice(2).every(v=>/^(0|[1-9][0-9]*)$/.test(v)) ||
      BigInt(row[2])+BigInt(row[3])!==BigInt(row[4]))fail();
  }
  process.stdout.write(JSON.stringify({
    schema_version:'portal.f72.offline-bundle-check.synthetic.v1',
    simulation_only:true,operational_authority:false,
    rows:receipt.data_rows,html_sha256:sha(html),csv_sha256:sha(csv),
  })+'\n');
}catch{fail();}
