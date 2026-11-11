import test from 'node:test';
import assert from 'node:assert/strict';
import {classifyPath,summarizeDiff,verifyGscReviewSlice} from './inventory.mjs';

test('F161 inventory contains all paths even beyond GitHub REST 300',()=>{
  const entries=Array.from({length:351},(_,i)=>({
    status:i%2?'M':'A',
    name:'apps/portal/labs/gsc-f161/fixture-'+String(i).padStart(4,'0')+'.txt'
  }));
  const out=summarizeDiff(entries);
  assert.equal(out.totalPaths,351);
  assert.equal(out.files.length,351);
  assert.equal(out.files[0].path,entries[0].name);
  assert.equal(out.files[350].path,entries[350].name);
  assert.equal(out.statuses.A,176);
  assert.equal(out.statuses.M,175);
});
test('F161 flags sensitive integration, database and CI paths for review',()=>{
  assert.ok(classifyPath('apps/portal/app/Domain/Security/Permission.php').includes('identity_security'));
  assert.ok(classifyPath('apps/portal/database/migrations/2026_11_fake.php').includes('database_migration'));
  assert.ok(classifyPath('apps/portal/app/Domain/SeoReview/GoogleSearchConsoleOAuth.php').includes('integration'));
  assert.ok(classifyPath('apps/portal/app/Domain/Payments/Asaas.php').includes('billing'));
  assert.deepEqual(classifyPath('.github/workflows/portal.yml'),['ci_workflow']);
});
test('F161 rejects duplicate, invalid and unbounded Git diff results',()=>{
  for(const values of [
    [{status:'M',name:'same'},{status:'A',name:'same'}],
    [{status:'R100',name:'renamed'}],
    [{status:'M',name:''}],
    Array.from({length:20001},(_,i)=>({status:'M',name:String(i)}))
  ])assert.throws(()=>summarizeDiff(values));
});

test('F162 accepts only the bounded SEO/GSC review slice',()=>{
  const approved=[
    '.github/workflows/portal-f79-seo-review-ci.yml',
    'apps/portal/app/Domain/SeoReview/OfflineGscQueryPageReviewPreview.php',
    'apps/portal/labs/gsc-f152/verify_php_overlap_trend.php',
    'apps/portal/labs/gsc-f155/test_php_query_page_review.php',
    'apps/portal/labs/gsc-f156/emit_node_query_page_review.mjs',
    'apps/portal/labs/gsc-f157/verify_php_overlap_trend_rc32.php',
    'apps/portal/labs/gsc-f159/README.md',
    'apps/portal/labs/gsc-f160/check_f159_renderer_fail_closed.mjs',
    'apps/portal/labs/gsc-f161/inventory.test.mjs',
    'apps/portal/tools/f159-render-gsc-query-page-review-offline.php'
  ].map(path=>({path,status:'A'}));
  const result=verifyGscReviewSlice(approved);
  assert.equal(result.verified,true);
  assert.equal(result.permittedPaths,approved.length);
  assert.equal(result.operationalAuthorization,'NO_GO_REVIEW_ONLY');
});
test('F162 denies operational routes, identity, migrations and unrelated workflows',()=>{
  for(const path of [
    'apps/portal/routes/web.php',
    'apps/portal/app/Providers/AuthServiceProvider.php',
    'apps/portal/database/migrations/2026_11_seo_grants.php',
    'apps/portal/app/Domain/SeoReview/EnableGoogleOAuth.php',
    'apps/portal/labs/gsc-f151/override.php',
    '.github/workflows/deploy-production.yml',
    'server/secrets.json',
    'infra/homologation/compose.yaml'
  ])assert.throws(()=>verifyGscReviewSlice([{path,status:'A'}]),
    /F162: out-of-scope review change refused/);
});
