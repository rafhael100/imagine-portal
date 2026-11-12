import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';
import {safeAsaasUrl} from '../server/security.mjs';

const src=await readFile(fileURLToPath(new URL('../portal.js',import.meta.url)),'utf8');
const start=src.indexOf('function externalLink('),end=src.indexOf('function renderAuth()',start);
assert.ok(start>=0&&end>start);
const escapeHtml=value=>String(value??'').replace(/[&<>"']/g,c=>({
 '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
}[c]));
const context={URL,escapeHtml};
runInNewContext(src.slice(start,end)+'\nthis.makeLink=externalLink;',context);
const externalLink=context.makeLink;

test('F262: official Asaas HTTPS links without userinfo or odd ports remain usable',()=>{
 const urls=[
  'https://sandbox.asaas.com/i/charge-id',
  'https://asaas.com/checkout',
  'https://api-sandbox.asaas.com/v3/payments?ok=1',
  'https://sandbox.asaas.com:443/i/id'
 ];
 for(const url of urls){
  const allowed=safeAsaasUrl(url);
  assert.ok(allowed&&allowed.startsWith('https://'),url);
  const markup=externalLink(url,'Pagar');
  assert.match(markup,/target="_blank" rel="noopener noreferrer"/,url);
  assert.ok(markup.includes('Pagar'),url);
 }
});

test('F262: malicious or ambiguous billing URLs are rejected in both layers',()=>{
 const rejected=[
  'http://sandbox.asaas.com/i/id',
  'javascript:alert(1)',
  'https://sandbox.asaas.com.evil.example/i/id',
  'https://evil.example/?next=https://sandbox.asaas.com/',
  'https://user:pass@sandbox.asaas.com/i/id',
  'https://user@sandbox.asaas.com/i/id',
  'https://sandbox.asaas.com:8443/i/id',
  'https://sandbox.asaas.com:81/i/id',
  'https://asaas.com@evil.example/',
  '',
  null
 ];
 for(const url of rejected){
  assert.equal(safeAsaasUrl(url),null,JSON.stringify(url));
  assert.equal(externalLink(url,'Pagar'),'',JSON.stringify(url));
 }
});

test('F262: link markup escapes label, classes and destination',()=>{
 const markup=externalLink('https://sandbox.asaas.com/i/id?x=1&y=2',
  '<img src=x onerror=alert(1)>','btn" onclick="alert(1)');
 assert.ok(markup.includes('href="https://sandbox.asaas.com/i/id?x=1&amp;y=2"'));
 assert.ok(markup.includes('&lt;img src=x onerror=alert(1)&gt;'));
 assert.ok(markup.includes('class="btn&quot; onclick=&quot;alert(1)"'));
 assert.equal(markup.includes('<img'),false);
 assert.equal(markup.includes('class="btn" onclick="'),false);
});
