import test from 'node:test';
import assert from 'node:assert/strict';
import {safePublishedSiteUrl} from '../server/account-projects.mjs';

test('F226: customer-facing published URL rejects dangerous and private protocols/hosts',()=>{
 assert.equal(safePublishedSiteUrl('https://empresa.example.test/site'),'https://empresa.example.test/site');
 for(const candidate of ['javascript:alert(1)','http://empresa.example.test','https://user:password@empresa.test','https://localhost/','https://db.internal','https://127.0.0.1/a','https://192.168.1.8/','https://site.local/','data:text/html,a','//missing.test','https://invalid','https://bad host','x'.repeat(1000)]){
  assert.equal(safePublishedSiteUrl(candidate),null,candidate);
 }
});
