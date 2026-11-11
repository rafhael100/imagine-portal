import test from 'node:test';
import assert from 'node:assert/strict';
import {
  classify, parseNameStatusZ, buildReport, markdownReport, reviewPackets,
  requireCandidateHead,
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

test('review packets partition files exactly once and never imply approval', () => {
  const records = parseNameStatusZ(
    'A\0apps/portal/app/Domain/Identity/Trusted.php\0' +
    'M\0apps/portal/resources/views/portal/demo/overview.blade.php\0' +
    'D\0apps/portal/database/migrations/old.php\0'
  );
  const report = buildReport({
    base:'origin/dev',head:'head123',mergeBase:'base123',
    leftRight:'0\t10',records,
  });
  const packets = reviewPackets(report);
  assert.deepEqual(Object.keys(packets),[
    'identity_security','presentation','database_migration'
  ]);
  assert.equal(Object.keys(packets).length,3);
  for (const [category, content] of Object.entries(packets)) {
    assert.match(content,/NO-GO/);
    assert.match(content,/NÃO revisados/);
    assert.match(content,new RegExp(category));
  }
  assert.match(packets.identity_security,/Trusted\.php/);
  assert.doesNotMatch(packets.identity_security,/overview\.blade\.php/);
  assert.match(packets.presentation,/overview\.blade\.php/);
  assert.match(packets.database_migration,/old\.php/);
  assert.equal(report.decision.may_merge,false);
});

test('candidate SHA guard rejects a synthetic PR merge checkout even if it is a valid SHA', () => {
  const trueHead = 'a'.repeat(40);
  const syntheticMerge = 'b'.repeat(40);
  assert.equal(requireCandidateHead(trueHead, trueHead), trueHead);
  assert.throws(
    () => requireCandidateHead(syntheticMerge, trueHead),
    /actual candidate HEAD/,
  );
  for (const invalid of ['', 'not-a-sha', 'A'.repeat(40), 'a'.repeat(39)]) {
    assert.throws(() => requireCandidateHead(invalid, trueHead));
  }
});

test('F185: Node.js/SQLite operational files are precisely partitioned for independent reviewers', () => {
 const samples = {
  'server/app.mjs':'node_identity_security',
  'server/account.mjs':'node_identity_security',
  'server/store.mjs':'node_identity_security',
  'server/team.mjs':'node_identity_security',
  'server/security.mjs':'node_identity_security',
  'server/account-export.mjs':'node_sensitive_export',
  'server/asaas.mjs':'node_application_runtime',
  'server/fiscal.mjs':'node_application_runtime',
  'tests/account-export.test.mjs':'automated_tests',
  'portal.js':'node_presentation',
  'account-ui.js':'node_presentation',
  'client-care-ui.js':'node_presentation',
  'portal.html':'node_presentation',
  'apps/portal/tools/integration/inventory.mjs':'integration_review_tooling',
  'package.json':'other',
 };
 for(const [path, expected] of Object.entries(samples)) {
  assert.equal(classify(path),expected,path);
 }
 const records=parseNameStatusZ(Object.keys(samples).flatMap(path=>['M',path]).join('\\0')+'\\0');
 const report=buildReport({base:'dev',head:'candidate',mergeBase:'shared',leftRight:'0\\t20',records});
 const packets=reviewPackets(report);
 assert.equal(Object.values(packets).reduce((n,v)=>n+(v.match(/^\- M /gm)||[]).length,0),records.length);
 for(const category of Object.values(samples))assert.ok(packets[category]);
 assert.equal(report.decision.may_merge,false);
});
