import test from 'node:test';
import assert from 'node:assert/strict';
import {quoteChannelSelection as quote,formatBRL} from './calculator.mjs';
const enabled=['meta_ads','google_ads'];
test('Meta as a single channel costs R$490 activation',()=>{
 const q=quote(['meta_ads'],{meta_ads:100000},enabled);
 assert.equal(q.activation_now_cents,49000);
 assert.equal(q.media_total_cents,100000);
 assert.equal(q.illustrative_twenty_percent_cents,20000);
 assert.equal(q.checkout_enabled,false);
 assert.equal(q.real_management_invoice_authorized,false);
});
test('two channels cost R$980 and 20pct remains illustrative',()=>{
 const q=quote(['meta_ads','google_ads'],{meta_ads:100000,google_ads:150000},enabled);
 assert.equal(q.activation_now_cents,98000);
 assert.equal(q.media_total_cents,250000);
 assert.equal(q.illustrative_twenty_percent_cents,50000);
 assert.equal(q.media_paid_directly_to_platforms,true);
 assert.equal(q.minimum_management_fee_cents,null);
});
test('three channels R$1470 only if explicitly enabled in a synthetic test',()=>{
 const q=quote(['meta_ads','google_ads','chatgpt_ads'],
   {meta_ads:100000,google_ads:150000,chatgpt_ads:180000},
   ['meta_ads','google_ads','chatgpt_ads']);
 assert.equal(q.activation_now_cents,147000);
 assert.equal(q.media_recommended_cents,430000);
});
test('ChatGPT not active in real preview',()=>{
 assert.throws(()=>quote(['chatgpt_ads'],{chatgpt_ads:180000},enabled),RangeError);
});
test('duplicate Meta not double counted',()=>{
 assert.throws(()=>quote(['meta_ads','meta_ads'],{meta_ads:100000},enabled),RangeError);
});
test('unselected budget or minimum violated fails closed',()=>{
 assert.throws(()=>quote(['meta_ads'],{meta_ads:69999},enabled),RangeError);
 assert.throws(()=>quote(['meta_ads'],{meta_ads:100000,google_ads:150000},enabled),RangeError);
 assert.throws(()=>quote([],{},enabled),RangeError);
 assert.throws(()=>quote(['other'],{other:100000},enabled),RangeError);
});
test('BRL format is local and does not imply a payment',()=>{
 assert.match(formatBRL(49000),/490,00/);
});
