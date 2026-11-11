import test from 'node:test';
import assert from 'node:assert/strict';
import {
  assessCommit, buildCommitReview, markdownCommitReview,
} from './commit-review.mjs';
import { classify } from './inventory.mjs';

function commit(shaChar, paths, options={}) {
  const changedPaths=paths.map(path=>({
    status:'M',path,category:classify(path),
  }));
  return assessCommit({
    sha: shaChar.repeat(40),
    parents: options.parents??1,
    subject: options.subject??'F145 synthetic review fixture',
    changedPaths,
  });
}

test('IAM and database changes always require a separate security/runtime review',()=>{
  const c=commit('a',[
    'apps/portal/database/migrations/demo.php',
    'apps/portal/app/Domain/Identity/TrustedSession.php',
    'apps/portal/tests/Feature/TrustedSessionTest.php',
  ]);
  assert.equal(c.risk,'requires_security_or_runtime_review');
  assert.equal(c.mixed_areas,true);
  assert.equal(c.touches_tests_in_same_commit,true);
  assert.equal(c.approved,false);
  assert.equal(c.may_merge,false);
  assert.equal(c.may_deploy,false);
  assert.deepEqual(c.categories,[
    'automated_tests','database_migration','identity_security',
  ]);
});

test('isolated laboratory, domain and unmatched code are visibly distinguished',()=>{
  assert.equal(commit('b',['apps/portal/labs/f145/test.php']).risk,
    'requires_standard_review');
  assert.equal(commit('c',['apps/portal/app/Domain/ServiceRequests/A.php']).risk,
    'requires_business_review');
  assert.equal(commit('d',['private/custom.bin']).risk,
    'requires_security_or_runtime_review');
  assert.equal(commit('e',['.github/workflows/workflow.yml']).risk,
    'requires_security_or_runtime_review');
});

test('merge and path-mix signals are metadata only, not grants',()=>{
  const c=commit('f',[
    'apps/portal/app/Presentation/Example.php',
    'apps/portal/app/Domain/ServiceRequests/Preview.php',
  ],{parents:2,subject:'Merge example\n  with unsafe subject'});
  assert.equal(c.merge_commit,true);
  assert.equal(c.mixed_areas,true);
  assert.equal(c.subject,'Merge example   with unsafe subject');
  assert.equal(c.approved,false);
});

test('review report is complete, bounded, deduplicated and always NO-GO',()=>{
  const list=[
    commit('a',['apps/portal/database/migrations/Migration.php']),
    commit('b',['apps/portal/app/Domain/Request.php']),
    commit('c',['apps/portal/labs/lab.php']),
  ];
  const report=buildCommitReview({base:'dev',head:'head',commits:list});
  assert.equal(report.stats.commits_total,3);
  assert.equal(report.stats.security_or_runtime_review,1);
  assert.equal(report.stats.business_review,1);
  assert.equal(report.stats.standard_review,1);
  assert.equal(report.stats.commits_without_tests_in_same_commit,3);
  assert.equal(report.decision.may_merge,false);
  assert.equal(report.decision.may_deploy,false);
  assert.match(markdownCommitReview(report),/NO-GO/);
  assert.throws(()=>buildCommitReview({
    base:'dev',head:'head',commits:[list[0],list[0]],
  }),/duplicated/);
  assert.throws(()=>buildCommitReview({
    base:'dev',head:'head',commits:Array.from({length:5001},()=>list[0]),
  }));
});

test('invalid paths, categories and commit hashes do not pass classification',()=>{
  const a=commit('a',['apps/portal/tests/Unit/Fake.php']);
  assert.throws(()=>assessCommit({
    sha:'not-a-sha',parents:1,subject:'',changedPaths:a.changed_paths,
  }));
  assert.throws(()=>assessCommit({
    sha:'a'.repeat(40),parents:1,subject:'',changedPaths:[{
      path:'apps/portal/database/migrations/Fake.php',category:'presentation',
    }],
  }),/Untrusted/);
});
