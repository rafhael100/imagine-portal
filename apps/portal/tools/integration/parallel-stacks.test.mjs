import test from 'node:test';
import assert from 'node:assert/strict';
import {
  readNulPaths, compareCandidatePaths, mergeAssessment,
  renderMergeAssessment, PINNED_SEO_HEAD,
} from './parallel-stacks.mjs';

test('NUL-delimited Git paths parse without splitting spaces or loss of names',()=>{
  assert.deepEqual(
    readNulPaths('apps/portal/app/a file.php\0apps/portal/app/B.php\0'),
    ['apps/portal/app/a file.php','apps/portal/app/B.php'],
  );
  assert.deepEqual(readNulPaths(''),[]);
  assert.throws(()=>readNulPaths('a/partial'),/Truncated/);
  assert.throws(()=>readNulPaths('/absolute\0'),/Unexpected/);
});

test('overlap is exact, sorted and categorized; a shared path is not automatically a merge conflict',()=>{
  const p=compareCandidatePaths([
    'apps/portal/app/Domain/Identity/Session.php',
    'apps/portal/resources/views/portal/demo/overview.blade.php',
    '.github/workflows/portal.yml',
  ],[
    'apps/portal/resources/views/portal/demo/overview.blade.php',
    'apps/portal/app/Domain/Identity/Session.php',
    'apps/portal/docs/unshared.md',
  ]);
  assert.equal(p.portal_changed_files,3);
  assert.equal(p.seo_changed_files,3);
  assert.equal(p.shared_changed_files,2);
  assert.deepEqual(p.overlap_categories,{
    identity_security:1,presentation:1,
  });
  assert.throws(()=>compareCandidatePaths(['a','a'],['a']),/Duplicate/);
});

function sample(status){
  return mergeAssessment({
    portalSha:'a'.repeat(40),seoSha:PINNED_SEO_HEAD,
    mergeBase:'b'.repeat(40),
    counts:'174\t241',
    paths:compareCandidatePaths(['apps/portal/app/test.php'],['apps/portal/app/test.php']),
    mergeStatus:status,mergeOutput:'synthetic merge-tree result',
  });
}

test('clean simulation still forbids merge, deploy and implied security approval',()=>{
  const report=sample(0);
  assert.equal(report.commits_only_seo,174);
  assert.equal(report.commits_only_portal,241);
  assert.equal(report.simulated_merge_clean,true);
  assert.equal(report.simulated_merge_conflicts,false);
  assert.deepEqual(report.decision,{
    integration_approved:false,may_merge:false,may_deploy:false,
    manual_conflict_review_required:true,independent_appsec_dba_required:true,
  });
  assert.match(renderMergeAssessment(report),/NO-GO/);
});

test('conflict is recorded without pretending a successful integration',()=>{
  const report=sample(1);
  assert.equal(report.simulated_merge_conflicts,true);
  assert.equal(report.simulated_merge_clean,false);
  assert.match(renderMergeAssessment(report),/SIM/);
  assert.equal(report.decision.may_merge,false);
  assert.throws(()=>sample(2),/definitive result/);
});

test('invalid commits and graph counts fail closed',()=>{
  assert.throws(()=>mergeAssessment({
    portalSha:'bad',seoSha:PINNED_SEO_HEAD,
    mergeBase:'a'.repeat(40),counts:'1\t2',
    paths:compareCandidatePaths([],[]),mergeStatus:0,mergeOutput:'',
  }),/Invalid pinned/);
  assert.throws(()=>mergeAssessment({
    portalSha:'a'.repeat(40),seoSha:PINNED_SEO_HEAD,
    mergeBase:'b'.repeat(40),counts:'n/a',
    paths:compareCandidatePaths([],[]),mergeStatus:0,mergeOutput:'',
  }),/divergence/);
});
