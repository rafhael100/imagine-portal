/**
 * Portal RC50 - strict read-only supply-chain guard for GitHub Actions.
 * Reject mutable version tags or branches for all third-party actions and
 * reusable workflows in this checkout. No network, token or secret reads.
 */
import {readdir,readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';

const WORKFLOWS=fileURLToPath(new URL('../../../.github/workflows/',import.meta.url));

export function isImmutableActionRef(ref){
  if(typeof ref!=='string'||ref.length>300)return false;
  // Local action/workflow content is anchored to the reviewed checkout SHA.
  if(/^\.\/(?:[a-zA-Z0-9_.-]+\/)*[a-zA-Z0-9_.-]+(?:\.(?:yml|yaml))?$/.test(ref))
    return !ref.split('/').includes('..');
  // GH Marketplace action or reusable workflow with a full commit SHA.
  if(/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+(?:\/[a-zA-Z0-9_./-]+)?@[a-f0-9]{40}$/.test(ref))
    return !ref.includes('/../')&&!ref.includes('/./');
  // Docker action with an immutable image manifest digest.
  if(/^docker:\/\/[a-zA-Z0-9_.:/-]+@sha256:[a-f0-9]{64}$/.test(ref))
    return true;
  return false;
}
export function inspectActionReferences(source){
  if(typeof source!=='string')throw Error('invalid workflow source');
  const findings=[];
  const lines=source.split(/\r?\n/);
  let inspected=0;
  for(let i=0;i<lines.length;i++){
    const match=lines[i].match(/^\s*(?:-\s*)?uses:\s*([^\s#]+)/);
    if(!match)continue;
    inspected++;
    if(!isImmutableActionRef(match[1]))
      findings.push({line:i+1,code:'unpinned_action_reference'});
  }
  return {inspected,findings};
}
export async function auditRepositoryWorkflows(){
  const entries=await readdir(WORKFLOWS,{withFileTypes:true});
  const names=entries.filter(e=>e.name.endsWith('.yml')||e.name.endsWith('.yaml'))
    .sort((a,b)=>a.name.localeCompare(b.name));
  const violations=[];
  let refs=0;
  for(const entry of names){
    if(!entry.isFile()) {
      violations.push({file:entry.name,line:0,code:'unsafe_workflow_file'});
      continue;
    }
    const content=await readFile(new URL(entry.name,new URL('../../../.github/workflows/',import.meta.url)),'utf8');
    if(content.length>400000){
      violations.push({file:entry.name,line:0,code:'oversized_workflow_file'});
      continue;
    }
    const check=inspectActionReferences(content);
    refs+=check.inspected;
    for(const finding of check.findings)
      violations.push({file:entry.name,...finding});
  }
  return {workflowCount:names.length,actionReferencesChecked:refs,violations};
}
if(process.argv[1]===fileURLToPath(import.meta.url)){
  auditRepositoryWorkflows().then(result=>{
    if(result.violations.length){
      for(const issue of result.violations)
        process.stderr.write('RC50 action policy: '+issue.file+':'+issue.line+
          ' '+issue.code+'\n');
      process.exitCode=1;
    }else process.stdout.write(
      'RC50 action policy: '+result.workflowCount+' workflow files, '+
      result.actionReferencesChecked+' immutable action references verified\n'
    );
  }).catch(()=>{
    process.stderr.write('RC50 action policy: unreadable workflow inventory\n');
    process.exitCode=1;
  });
}
