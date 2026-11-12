import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

test('F239: overlapping push and pull-request checks use one source-branch concurrency group',()=>{
 const workflows=[
  '.github/workflows/test.yml',
  '.github/workflows/portal-int-f186-node-integration-batch.yml',
  '.github/workflows/portal-f144-integration-inventory.yml',
  '.github/workflows/portal-f102-gsc-oauth-lab.yml',
 ];
 const expression='$'+'{{ github.head_ref || github.ref_name }}';
 for(const file of workflows){
  const source=readFileSync(file,'utf8');
  assert.ok(source.includes(expression),file);
  assert.ok(source.includes('cancel-in-progress: true'),file);
  assert.equal(source.includes('github.event.pull_request.number || github.ref_name'),false,file);
 }
});
