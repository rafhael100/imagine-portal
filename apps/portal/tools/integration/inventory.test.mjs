import test from 'node:test';
import assert from 'node:assert/strict';
import {
  classify, parseNameStatusZ, buildReport, markdownReport,
} from './inventory.mjs';

test('NUL diff parser handles ordinary, deletion and rename records without shell interpretation', () => {
  const entries = parseNameStatusZ([
    'M','apps/portal/app/Presentation/DemoClientOverview.php',
    'A','apps/portal/database/migrations/2030_01_01_000000_fixture.php',
    'R100','apps/portal/docs/old.md','apps/portal/docs/new.md',
    'D','apps/portal/resources/views/old.blade.php',''
  ].join('\0'));
  assert.equal(entries.length,4);
  assert.deepEqual(entries.map(x=>x.status),['M','A','R100','D']);
  assert.equal(entries[2].previous_path,'apps/portal/docs/old.md');
  assert.equal(entries[2].path,'apps/portal/docs/new.md');
  assert.equal(entries[1].category,'database_migration');
  assert.equal(entries[0].category,'presentation');
});

test('unknown, truncated and absolute-path records are rejected', () => {
  for(const value of ['Z\0bad\0','M\0','R100\0old\0','M\0/absolute\0']) {
    assert.throws(()=>parseNameStatusZ(value));
  }
});

test('classification highlights identity and separates UI, tests, labs and workflows', () => {
  assert.equal(classify('apps/portal/app/Domain/Identity/TrustedSession.php'),'identity_security');
  assert.equal(classify('apps/portal/database/migrations/demo.php'),'database_migration');
  assert.equal(classify('apps/portal/app/Domain/ServiceRequests/Preview.php'),'domain');
  assert.equal(classify('apps/portal/labs/identity-f77/a.php'),'isolated_lab');
  assert.equal(classify('apps/portal/tests/Unit/Foo.php'),'automated_tests');
  assert.equal(classify('.github/workflows/portal-x.yml'),'ci_workflow');
  assert.equal(classify('apps/portal/resources/views/portal/demo/overview.blade.php'),'presentation');
});

test('report is explicitly NON-authoritative regardless of successful checks', () => {
  const records=parseNameStatusZ('A\0apps/portal/app/Domain/Identity/A.php\0');
  const result=buildReport({
    base:'origin/dev',head:'abc123',mergeBase:'zero',leftRight:'0\t700',records,
  });
  assert.equal(result.changed_files_total,1);
  assert.equal(result.commits_only_on_candidate,700);
  assert.equal(result.commits_only_on_dev,0);
  assert.equal(result.categories.identity_security,1);
  assert.deepEqual(result.decision,{
    integration_approved:false,
    independent_appsec_dba_review_required:true,
    staging_homologation_required:true,
    may_merge:false,
    may_deploy:false,
  });
  const summary=markdownReport(result);
  assert.match(summary,/NO-GO/);
  assert.match(summary,/700/);
  assert.doesNotMatch(summary,/Aprovado para produção/);
});
