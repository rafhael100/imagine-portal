import {readFileSync,writeFileSync} from 'node:fs';
import {pathToFileURL} from 'node:url';

const HEX40=/^[a-f0-9]{40}$/;
const repoAllow=new Set([
  'rafhael100/imagine-comercial','rafhael100/imagine-portal',
  'rafhael100/imagine-sites',
]);
const approvedApi='https://api.github.com';

export function assertManifest(manifest) {
  if (!manifest || manifest.schema!=='imagine.p0.release-candidate-manifest.v1'
      || manifest.scope!=='synthetic_ci_only_no_operational_release'
      || !Array.isArray(manifest.candidates)
      || manifest.candidates.length!==3
      || !Array.isArray(manifest.parallel_lines)
      || !manifest.operational_gates || !manifest.restrictions) {
    throw new Error('Invalid pinned release-candidate manifest.');
  }
  if (new Set(manifest.candidates.map(x=>x.id)).size!==3 ||
      new Set(manifest.candidates.map(x=>x.repo)).size!==3 ||
      new Set(manifest.candidates.map(x=>x.pr)).size!==3 ||
      ['comercial','portal','sites'].some(id=>!manifest.candidates.some(x=>x.id===id))) {
    throw new Error('Missing or duplicated mandatory service candidates.');
  }
  for(const c of manifest.candidates) {
    if(!repoAllow.has(c.repo) || !HEX40.test(c.head) ||
        !Number.isInteger(c.pr) || c.pr<1 ||
        !/^[a-z0-9][a-z0-9_/.-]*$/.test(c.compare_base) ||
        !Array.isArray(c.required_workflows) || c.required_workflows.length<3 ||
        new Set(c.required_workflows).size!==c.required_workflows.length ||
        c.required_workflows.some(x=>typeof x!=='string' || x.length<12)) {
      throw new Error('Malformed candidate/pin or mandatory workflows.');
    }
  }
  const gates=Object.entries(manifest.operational_gates);
  if (gates.length<9 || gates.some(([k,v])=>!/^[_a-z0-9]+$/.test(k)||v!==false)
      || Object.values(manifest.restrictions).length!==5
      || Object.values(manifest.restrictions).some(x=>x!==false)) {
    throw new Error('No operational approval may be asserted by this CI manifest.');
  }
  for(const line of manifest.parallel_lines) {
    if(!repoAllow.has(line.repo) || typeof line.left!=='string' ||
       typeof line.right!=='string' || line.left===line.right) {
      throw new Error('Malformed parallel-branch divergence review.');
    }
  }
  return manifest;
}
function exactRun(runs,name,head) {
  // Never count success on an earlier SHA or another similarly named job.
  const matching=(Array.isArray(runs)?runs:[]).filter(
    x=>x.name===name && x.head_sha===head
  ).sort((a,b)=>Number(b.id||0)-Number(a.id||0));
  return matching[0]??null;
}
export function assessCandidate(candidate, pr, runs, compare) {
  const failures=[];
  if(pr?.state!=='open' || pr?.draft!==true ||
     pr?.head?.sha!==candidate.head || pr?.number!==candidate.pr ||
     pr?.head?.repo?.full_name!==candidate.repo ||
     pr?.merged_at!==null) failures.push('PR head/state/draft mismatch or merged');
  if (!compare || compare.behind_by!==0 ||
      !Number.isInteger(compare.ahead_by) ||
      compare.ahead_by<1 || compare.status!=='ahead') {
    failures.push('Review branch is not a strict descendant of its comparison base');
  }
  const checks=candidate.required_workflows.map(name=>{
    const run=exactRun(runs,name,candidate.head);
    return {
      name, status:!run?'MISSING':
       run.status!=='completed'?'PENDING':
       run.conclusion==='success'?'PASS':'FAIL',
      run_id:run&&Number.isInteger(run.id)?run.id:null,
    };
  });
  if(checks.some(c=>c.status!=='PASS')) {
    failures.push('Not all mandatory workflows passed on the pinned SHA');
  }
  return {
    service:candidate.id,repo:candidate.repo,pr:candidate.pr,
    expected_head:candidate.head,
    commits_ahead:Number.isInteger(compare?.ahead_by)?compare.ahead_by:null,
    commits_behind:Number.isInteger(compare?.behind_by)?compare.behind_by:null,
    checks,ci_status:failures.length===0?'PINNED_CI_PASS':'REVIEW_REQUIRED',
    failures,
  };
}
export function assessDivergence(line,comparison) {
  if(!comparison || !Number.isInteger(comparison.behind_by) ||
     !Number.isInteger(comparison.ahead_by) ||
     comparison.behind_by<0 || comparison.ahead_by<0) {
    return {repo:line.repo,state:'UNKNOWN',reason:line.reason};
  }
  return {
    repo:line.repo,branches:[line.left,line.right],
    commits_missing_from_right:comparison.behind_by,
    commits_only_on_right:comparison.ahead_by,
    state:comparison.behind_by===0?'ANCESTRY_CONTAINS_LEFT':'DIVERGED_RECONCILIATION_REQUIRED',
    reason:line.reason,
  };
}
export function buildReport(manifest,observations) {
  assertManifest(manifest);
  if(!Array.isArray(observations.candidates) ||
     observations.candidates.length!==3 ||
     !Array.isArray(observations.divergences) ||
     observations.divergences.length!==manifest.parallel_lines.length) {
    throw new Error('Missing cross-repository evidence; fail closed.');
  }
  const missing=observations.candidates.filter(x=>x.ci_status!=='PINNED_CI_PASS');
  const divergence=observations.divergences.filter(x=>x.state!=='ANCESTRY_CONTAINS_LEFT');
  return {
    schema:'imagine.p0.release-candidate-audit.v1',
    as_of_utc:new Date().toISOString(),
    candidates:observations.candidates,
    parallel_lines:observations.divergences,
    ci_pins_verified:missing.length===0,
    integration_reconciled:missing.length===0 && divergence.length===0,
    operational_gates:{...manifest.operational_gates},
    blockers:[
      ...missing.map(x=>'REVIEW_REQUIRED: '+x.service),
      ...divergence.map(x=>'UNMERGED_PARALLEL_LINE: '+x.repo),
      ...Object.keys(manifest.operational_gates).map(x=>'OPERATIONAL_UNVERIFIED: '+x),
    ],
    decision:{
      release_gate:'NO_GO',may_merge:false,may_deploy:false,
      may_bill:false,may_publish:false,may_use_real_customer_data:false,
    },
    note:'GitHub synthetic CI is not real payment, commercial authorization, legal consent, IAM or Leaseweb staging.',
  };
}
export function formatSummary(report) {
  const rows=report.candidates.map(c=>
    '| '+c.service+' | #'+c.pr+' | '+c.expected_head.slice(0,12)+
    ' | '+c.ci_status+' | '+c.checks.filter(x=>x.status==='PASS').length+
    '/'+c.checks.length+' | '+(c.commits_ahead??'?')+' |'
  );
  return [
    '## P0 — Concordância de SHA e CI (somente auditoria)',
    '',
    '| Serviço | PR | SHA | Evidência | Workflows | Commits acumulados |',
    '|---|---:|---|---|---:|---:|',
    ...rows,
    '',
    '### Convergência de branches paralelas',
    ...report.parallel_lines.map(x=>'- '+x.repo+': '+x.state+
      (Number.isInteger(x.commits_missing_from_right) ?
      ' (commits ausentes na branch candidata: '+x.commits_missing_from_right+')':'')
    ),
    '',
    '**Decisão operacional: NO-GO** — '+report.blockers.length+
    ' pendências detectadas; resultados de CI não autorizam cliente, pagamento, VPS ou publicação.',
    '',
  ].join('\n');
}
async function github(path,token){
  if(typeof path!=='string' || !/^\/repos\/rafhael100\/imagine-(?:portal|comercial|sites)\//.test(path) ||
     /(?:^|\/)\.\.(?:\/|$)/.test(path) || path.includes('#'))throw new Error('Unapproved GitHub API path');
  const controller=new AbortController();
  const timeout=setTimeout(()=>controller.abort(),20000);
  try {
    const response=await fetch(approvedApi+path,{
      headers:{
        Accept:'application/vnd.github+json',
        'X-GitHub-Api-Version':'2022-11-28',
        ...(token?{Authorization:'Bearer '+token}:{}),
      },signal:controller.signal,
    });
    if(!response.ok)throw new Error('GitHub API denied evidence HTTP '+response.status);
    return await response.json();
  } finally {clearTimeout(timeout);}
}
async function main(){
  if(process.env.GITHUB_ACTIONS!=='true' ||
     process.env.P0_RELEASE_INVENTORY!=='1' ||
     !process.env.RUNNER_TEMP ||
     !process.env.GITHUB_STEP_SUMMARY) {
    throw new Error('Audit requires read-only isolated GitHub Actions.');
  }
  const manifest=assertManifest(JSON.parse(
    readFileSync(new URL('./p0-release-candidate.json',import.meta.url),'utf8')
  ));
  const token=process.env.GITHUB_TOKEN;
  const candidates=[];
  for(const c of manifest.candidates){
    const root='/repos/'+c.repo+'/';
    const [pr,workflows,comparison]=await Promise.all([
      github(root+'pulls/'+c.pr,token),
      github(root+'actions/runs?head_sha='+c.head+'&per_page=100',token),
      github(root+'compare/'+encodeURIComponent(c.compare_base)+'...'+c.head,token),
    ]);
    if(!Array.isArray(workflows.workflow_runs))throw new Error('No GitHub Actions evidence');
    candidates.push(assessCandidate(c,pr,workflows.workflow_runs,comparison));
  }
  const divergences=[];
  for(const line of manifest.parallel_lines){
    const c=await github('/repos/'+line.repo+'/compare/'+
      encodeURIComponent(line.left)+'...'+encodeURIComponent(line.right),token);
    divergences.push(assessDivergence(line,c));
  }
  const report=buildReport(manifest,{candidates,divergences});
  const out=process.env.RUNNER_TEMP+'/imagine-p0-release-audit.json';
  writeFileSync(out,JSON.stringify(report,null,2)+'\n',{mode:0o600,flag:'wx'});
  const summary=formatSummary(report);
  writeFileSync(process.env.GITHUB_STEP_SUMMARY,summary,{flag:'a'});
  process.stdout.write(summary);
  if(!report.ci_pins_verified){
    // CI audit is red for stale commits/pending checks. NO operational action.
    process.exitCode=1;
  }
}
if(process.argv[1] && import.meta.url===pathToFileURL(process.argv[1]).href){
  main().catch(e=>{
    process.stderr.write('P0 audit unavailable: '+String(e.message||e)+'\n');
    process.exitCode=1;
  });
}
