import test from 'node:test';
import assert from 'node:assert/strict';
import {safePublishedSiteUrl} from '../server/account-projects.mjs';

test('F280: root-dot DNS suffix cannot bypass private-host published-link checks',()=>{
 const malicious=[
  'https://localhost./private',
  'https://sub.localhost./admin',
  'https://printer.local./',
  'https://corp.internal./',
  'https://example.com./',
  'https://LOCALHOST./',
  'https://sub.INTERNAL./',
  'https://127.0.0.1./',
  'https://localhost%2e/private',
  'https://u:p@example.com/',
  'http://public.example.com/'
 ];
 for(const value of malicious)
  assert.equal(safePublishedSiteUrl(value),null,JSON.stringify(value));
});

test('F280: normal public HTTPS links keep their old canonical value',()=>{
 const safe=[
  'https://www.example.com/',
  'https://site.example.org/path?campaign=1',
  'https://xn--bcher-kva.example/path',
  'https://www.example.com:443/'
 ];
 for(const value of safe){
  const canonical=safePublishedSiteUrl(value);
  assert.equal(canonical,new URL(value).href,value);
 }
});
