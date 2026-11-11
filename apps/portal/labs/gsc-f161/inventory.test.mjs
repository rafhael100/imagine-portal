import test from 'node:test';
import assert from 'node:assert/strict';
import {classifyPath,summarizeDiff} from './inventory.mjs';

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
