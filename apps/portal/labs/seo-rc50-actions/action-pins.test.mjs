import test from 'node:test';
import assert from 'node:assert/strict';
import {
  isImmutableActionRef,inspectActionReferences,auditRepositoryWorkflows
} from '../../tools/audit-workflow-action-pins.mjs';

const gitSha='0123456789abcdef0123456789abcdef01234567';
const dockerHash='f'.repeat(64);
test('RC50 only accepts immutable or checkout-relative action references',()=>{
  for(const ref of [
    'actions/checkout@'+gitSha,
    'owner/reusable/.github/workflows/test.yml@'+gitSha,
    './.github/actions/internal',
    './.github/workflows/build.yml',
    'docker://ghcr.io/example/runner@sha256:'+dockerHash
  ])assert.equal(isImmutableActionRef(ref),true,ref);
  for(const ref of [
    'actions/checkout@v7','shivammathur/setup-php@v2',
    'actions/setup-node@main','actions/checkout@latest',
    'owner/reusable/.github/workflows/test.yml@v1',
    'docker://ghcr.io/example/runner:latest',
    './../../../secrets','',null
  ])assert.equal(isImmutableActionRef(ref),false,String(ref));
});
test('RC50 reports only safe filenames and row numbers, never YAML body',()=>{
  const check=inspectActionReferences([
    'name: test','jobs:','  runner:','    steps:',
    '      - uses: actions/checkout@v4',
    '      - uses: actions/setup-node@'+gitSha,
    '      - uses: shivammathur/setup-php@v2 # forbidden'
  ].join('\n'));
  assert.deepEqual(check,{
    inspected:3,
    findings:[
      {line:5,code:'unpinned_action_reference'},
      {line:7,code:'unpinned_action_reference'}
    ]
  });
  assert.doesNotMatch(JSON.stringify(check),/checkout|setup-php|secret/);
});
test('RC50 checks the actual checked-out GitHub Actions directory',async()=>{
  const result=await auditRepositoryWorkflows();
  assert.ok(result.workflowCount>=20,'expected the Portal workflows');
  assert.ok(result.actionReferencesChecked>=25,'expected third-party actions');
  assert.deepEqual(result.violations,[]);
});
