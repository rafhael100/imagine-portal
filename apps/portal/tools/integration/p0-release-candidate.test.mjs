import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {
  assertManifest, assessCandidate, assessDivergence,buildReport,formatSummary,
} from './p0-release-candidate.mjs';
const source=JSON.parse(readFileSync(new URL('./p0-release-candidate.json',import.meta.url),'utf8'));
const manifest=assertManifest(source);
function fixtureCandidate(id='portal'){
  const c=manifest.candidates.find(c=>c.id===id);
  const pr={
    number:c.pr,state:'open',draft:true,merged_at:null,
    head:{sha:c.head,repo:{full_name:c.repo}},
  };
  const runs=c.required_workflows.map((name,i)=>({
    name,id:100+i,head_sha:c.head,event:'pull_request',status:'completed',conclusion:'success',
  }));
  return {c,pr,runs,compare:{status:'ahead',ahead_by:150,behind_by:0}};
}
test('all three pin declarations and operational NO-GO fields are strict',()=>{
  assert.equal(manifest.candidates.length,3);
  assert.deepEqual(manifest.candidates.map(x=>x.id),['comercial','portal','sites']);
  assert.throws(()=>assertManifest({...manifest,restrictions:{...manifest.restrictions,may_deploy:true}}));
  assert.throws(()=>assertManifest({...manifest,operational_gates:{
    ...manifest.operational_gates,real_customer_iam_mfa:true,
  }}));
  assert.throws(()=>assertManifest({...manifest,candidates:manifest.candidates.slice(0,2)}));
});
test('Sites release candidate requires all nine suites on SAME head, not isolated old PR successes',()=>{
  const {c,pr,runs,compare}=fixtureCandidate('sites');
  assert.equal(c.pr,470);
  assert.equal(c.required_workflows.length,9);
  assert.ok(c.required_workflows.some(n=>n.includes('F1-381 Portal PG18')));
  assert.ok(c.required_workflows.some(n=>n.includes('F1-386 Portal Ed25519')));
  assert.ok(c.required_workflows.some(n=>n.includes('F1-383 versioned operational')));
  assert.equal(assessCandidate(c,pr,runs,compare).ci_status,'PINNED_CI_PASS');
  const withoutTls=runs.filter(x=>!x.name.includes('F1-381 Portal PG18'));
  assert.equal(assessCandidate(c,pr,withoutTls,compare).ci_status,'REVIEW_REQUIRED');
  const fromOldBranch=runs.map(x=>({...x,head_sha:'9'.repeat(40)}));
  assert.equal(assessCandidate(c,pr,fromOldBranch,compare).ci_status,'REVIEW_REQUIRED');
});
test('only same-SHA completed successful checks count',()=>{
  const {c,pr,runs,compare}=fixtureCandidate();
  const done=assessCandidate(c,pr,runs,compare);
  assert.equal(done.ci_status,'PINNED_CI_PASS');
  assert.equal(done.checks.filter(x=>x.status==='PASS').length,runs.length);
  const stale=runs.map(x=>({...x,head_sha:'b'.repeat(40)}));
  assert.equal(assessCandidate(c,pr,stale,compare).ci_status,'REVIEW_REQUIRED');
  assert.equal(assessCandidate(c,pr,runs.map(x=>({...x,status:'in_progress'})),compare).ci_status,'REVIEW_REQUIRED');
  assert.equal(assessCandidate(c,{...pr,head:{...pr.head,sha:'b'.repeat(40)}},runs,compare).ci_status,'REVIEW_REQUIRED');
  assert.equal(assessCandidate(c,pr,runs,{...compare,behind_by:2}).ci_status,'REVIEW_REQUIRED');
});
test('newest run overrides stale success on same SHA',()=>{
  const {c,pr,runs,compare}=fixtureCandidate();
  const failed={...runs[0],id:999,status:'completed',conclusion:'failure'};
  const r=assessCandidate(c,pr,[...runs,failed],compare);
  assert.equal(r.checks[0].status,'FAIL');
  assert.equal(r.ci_status,'REVIEW_REQUIRED');
});
test('cancelled push cannot override successful PR review of same head',()=>{
  const {c,pr,runs,compare}=fixtureCandidate();
  const cancelled={...runs[0],id:999,event:'push',conclusion:'cancelled'};
  const report=assessCandidate(c,pr,[...runs,cancelled],compare);
  assert.equal(report.ci_status,'PINNED_CI_PASS');
  assert.equal(report.checks[0].status,'PASS');
});
test('parallel Sites line must be reconciled instead of presented as included',()=>{
  const line=manifest.parallel_lines[0];
  const bad=assessDivergence(line,{ahead_by:25,behind_by:8});
  assert.equal(bad.state,'DIVERGED_RECONCILIATION_REQUIRED');
  assert.equal(bad.commits_missing_from_right,8);
  const good=assessDivergence(line,{ahead_by:2,behind_by:0});
  assert.equal(good.state,'ANCESTRY_CONTAINS_LEFT');
});
test('successful CI can never switch production or merge gates on',()=>{
  const candidates=manifest.candidates.map(x=>{
    const f=fixtureCandidate(x.id);
    return assessCandidate(f.c,f.pr,f.runs,f.compare);
  });
  const divergences=[assessDivergence(manifest.parallel_lines[0],{
    ahead_by:25,behind_by:8,
  })];
  const report=buildReport(manifest,{candidates,divergences});
  assert.equal(report.ci_pins_verified,true);
  assert.equal(report.integration_reconciled,false);
  assert.deepEqual(Object.values(report.decision),[
    'NO_GO',false,false,false,false,false,
  ]);
  assert.ok(report.blockers.some(x=>x.startsWith('UNMERGED_PARALLEL_LINE')));
  assert.match(formatSummary(report),/NO-GO/);
  assert.throws(()=>buildReport(manifest,{candidates:[],divergences}));
});
