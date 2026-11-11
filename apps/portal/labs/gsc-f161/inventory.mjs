import {execFileSync} from 'node:child_process';
import {mkdirSync,writeFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * GSC-F161 — exact Git-based change inventory, never a merge or promotion.
 * Unlike GitHub REST compare, git diff returns ALL matching paths.
 */
const SHA=/^[0-9a-f]{40}$/;
export function classifyPath(name){
  const categories=[];
  if(/(^|\/)(auth|identity|oauth|iam|rls|security|policy|permission|grant|session|tenant)(\/|[_.-])/i.test(name)||
     /(permission|oauth|token|grant|security|tenant)/i.test(name))
    categories.push('identity_security');
  if(/(^|\/)(database|migrations?|schema|sql)(\/|[_.-])/i.test(name))
    categories.push('database_migration');
  if(/(asaas|checkout|billing|invoice|payment|cobranca|financeir)/i.test(name))
    categories.push('billing');
  if(/(seo|gsc|search.console|crm|sites|performance|integration)/i.test(name))
    categories.push('integration');
  if(name.startsWith('.github/workflows/'))categories.push('ci_workflow');
  if(/(views|resources|assets|styles|templates|ui|html|css)/i.test(name))
    categories.push('ui');
  return categories.length?categories:['other'];
}
export function summarizeDiff(pairs){
  if(!Array.isArray(pairs)||pairs.length>20000)
    throw Error('F161: unbounded change list');
  const seen=new Set(),counts={},statusCounts={};
  const records=pairs.map(record=>{
    if(!record||typeof record.name!=='string'||!record.name||
       typeof record.status!=='string'||!/^[A-Z]$/.test(record.status)||
       seen.has(record.name))
      throw Error('F161: incompatible Git change record');
    seen.add(record.name);
    const tags=classifyPath(record.name);
    for(const tag of tags)counts[tag]=(counts[tag]||0)+1;
    statusCounts[record.status]=(statusCounts[record.status]||0)+1;
    return {status:record.status,path:record.name,tags};
  });
  records.sort((a,b)=>a.path.localeCompare(b.path,'en'));
  return {totalPaths:records.length,categories:counts,statuses:statusCounts,files:records};
}

function inventory(root,baseSha,headSha){
  const text=execFileSync('git',['diff','--no-renames','--name-status','-z',
    baseSha,headSha],{cwd:root,encoding:'utf8',maxBuffer:20*1024*1024,
    timeout:90000});
  const cells=text.split('\0');
  if(cells.at(-1)==='')cells.pop();
  if(cells.length%2!==0)throw Error('F161: malformed Git diff');
  const changes=[];
  for(let i=0;i<cells.length;i+=2)changes.push({
    status:cells[i],name:cells[i+1]
  });
  return summarizeDiff(changes);
}

function git(root,...args){
  return execFileSync('git',args,{cwd:root,encoding:'utf8',
    timeout:90000,maxBuffer:2*1024*1024}).trim();
}
export function main([repositoryPath,expectedHead,prBaseSha]=[]){
  if(typeof repositoryPath!=='string'||!SHA.test(expectedHead||'')||
     !SHA.test(prBaseSha||''))
    throw Error('F161: explicit pinned revisions required');
  const root=resolve(repositoryPath);
  const head=git(root,'rev-parse','HEAD');
  const dev=git(root,'rev-parse','refs/remotes/origin/dev/fast-track-portal-2026-11-05');
  if(head!==expectedHead||!SHA.test(dev))
    throw Error('F161: checkout revision mismatch');
  for(const base of [dev,prBaseSha])
    if(git(root,'merge-base',base,head)!==base)
      throw Error('F161: missing ancestry; review divergent branches');
  const againstDev=inventory(root,dev,head);
  const againstPr=inventory(root,prBaseSha,head);
  const commitsDev=Number(git(root,'rev-list','--count',dev+'..'+head));
  const commitsPr=Number(git(root,'rev-list','--count',prBaseSha+'..'+head));
  if(!Number.isSafeInteger(commitsDev)||!Number.isSafeInteger(commitsPr)||
     commitsDev<commitsPr)
    throw Error('F161: invalid revision counts');

  const out={contractVersion:'portal-seo-read-only-diff-inventory-v1',
    operationalAuthorization:'NO_GO_REVIEW_ONLY',
    headSha:head,devBaseSha:dev,prBaseSha,commitsAheadDev:commitsDev,
    commitsAheadPr:commitsPr,againstDev,againstPr,
    limitations:[
      'Paths and change counts do not establish compatibility or security approval.',
      'No merge, migration, deployment, OAuth or customer data access is performed.'
    ]};
  const outDir=resolve('.portal-gsc-f161-inventory');
  mkdirSync(outDir,{recursive:true});
  writeFileSync(resolve(outDir,'full-diff.json'),JSON.stringify(out,null,2)+'\n');
  const overview=(title,part,commits)=>{
    const tags=Object.entries(part.categories).sort((a,b)=>a[0].localeCompare(b[0]))
      .map(([k,v])=>'- '+k+': '+v).join('\n');
    return '## '+title+'\n\n**Commits exclusivos:** '+commits+
      ' | **Caminhos alterados (sem limite REST de 300):** '+part.totalPaths+
      '\n\n'+tags+'\n\n';
  };
  const md='# Portal SEO — inventário de integração, somente revisão\n\n'+
    '**Head SHA:** \`'+head+'\`  \n**Dev base SHA:** \`'+dev+
    '\`  \n**PR base SHA:** \`'+prBaseSha+'\`\n\n'+
    overview('Dev → candidata',againstDev,commitsDev)+
    overview('Base do PR → candidata',againstPr,commitsPr)+
    '**NO-GO:** revisão humana de conteúdo, AppSec/DBA/LGPD, staging e autorização de merge necessárias. Nenhuma alteração foi aplicada.\n';
  writeFileSync(resolve(outDir,'summary.md'),md);
  process.stdout.write('F161 inventory: '+againstDev.totalPaths+
    ' full Git paths vs dev, '+againstPr.totalPaths+' vs PR base; NO-GO\n');
}
if(process.argv[1]===fileURLToPath(import.meta.url)){
  try{main(process.argv.slice(2));}
  catch{process.stderr.write('F161: review inventory refused\n');process.exitCode=1;}
}
