import test from 'node:test';
import assert from 'node:assert/strict';
import {ACCOUNT_PROJECT_MAX_PAGE,ACCOUNT_PROJECT_PAGE_SIZE,boundedProjectPages} from '../server/account-projects.mjs';
test('F210: advertised pages never exceed maximum and are never silently infinite',()=>{
 assert.equal(ACCOUNT_PROJECT_PAGE_SIZE,8);
 assert.equal(ACCOUNT_PROJECT_MAX_PAGE,1000);
 assert.deepEqual(boundedProjectPages(0),{pages:1,limited:false});
 assert.deepEqual(boundedProjectPages(8000),{pages:1000,limited:false});
 assert.deepEqual(boundedProjectPages(8001),{pages:1000,limited:true});
 assert.deepEqual(boundedProjectPages(80000),{pages:1000,limited:true});
});
