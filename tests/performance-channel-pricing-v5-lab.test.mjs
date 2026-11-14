import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {previewPerformanceChannelQuoteV5 as preview} from '../server/performance-channel-pricing-v5-lab.mjs';

const row=(channel,monthly_media_cents)=>({channel,monthly_media_cents});
test('approved channel minima sum independently; fee never charges media via Imagine',()=>{
  const r=preview([row('meta_ads',70000),row('google_ads',100000),
    row('chatgpt_ads',120000)]);
  assert.deepEqual(r.rows.map(x=>x.channel),['chatgpt_ads','google_ads','meta_ads']);
  assert.equal(r.minimum_media_cents,290000);
  assert.equal(r.recommended_media_cents,430000);
  assert.equal(r.planned_media_cents,290000);
  assert.equal(r.illustrative_management_fee_cents,58000);
  assert.equal(r.informational_total_cents,348000);
  assert.equal(r.media_paid_directly_to_providers,true);
  assert.equal(r.management_applies_to_verified_actual_media_spend,true);
  assert.equal(r.portal_checkout_enabled,false);
  assert.equal(r.asaas_charge_enabled,false);
  assert.equal(r.ads_write_enabled,false);
  assert.equal(r.estimate_is_not_invoice,true);
});
test('selected recommended values quote as separate media budgets',()=>{
  const r=preview([
    row('chatgpt_ads',180000),row('meta_ads',100000),row('google_ads',150000)
  ]);
  assert.equal(r.planned_media_cents,430000);
  assert.equal(r.illustrative_management_fee_cents,86000);
  assert.equal(r.informational_total_cents,516000);
});
test('single-channel floor and 20 percent preview without checkout',()=>{
  const r=preview([row('chatgpt_ads',120000)]);
  assert.equal(r.illustrative_management_fee_cents,24000);
  assert.equal(r.recommended_media_cents,180000);
  assert.equal(r.rows.length,1);
});
test('missing, duplicated, unknown, cross-channel compensating budgets fail closed',()=>{
  for(const values of [
    [],[row('chatgpt_ads',119999)],[row('google_ads',99999)],
    [row('meta_ads',69999)],[row('unknown_ads',120000)],
    [row('chatgpt_ads',180000),row('chatgpt_ads',180000)],
    [row('meta_ads',70000),row('google_ads',50000)],
    [row('chatgpt_ads', '120000')],[row('meta_ads', NaN)],
    [row('chatgpt_ads',100000001)],
    [row('chatgpt_ads',120000),row('meta_ads',70000),row('google_ads',100000),row('x',70000)]
  ])assert.throws(()=>preview(values),RangeError);
});
test('accessors, extra fields and sparse arrays cannot fake contractual scope',()=>{
  const strange=Object.defineProperty({channel:'meta_ads',monthly_media_cents:70000},
    'extra',{enumerable:false,get(){throw Error('must not execute')}});
  assert.throws(()=>preview([strange]),RangeError);
  let seen=false;
  const accessor=Object.defineProperty({channel:'meta_ads'},'monthly_media_cents',{
    enumerable:true,get(){seen=true;throw Error('unsafe')}
  });
  assert.throws(()=>preview([accessor]),RangeError);
  assert.equal(seen,false);
  const sparse=[row('meta_ads',70000),row('google_ads',100000)];
  delete sparse[0];
  assert.throws(()=>preview(sparse),RangeError);
});
test('approved commercial v4 stays untouched while v5 remains non-operational',()=>{
  const v4=JSON.parse(readFileSync(new URL(
    '../docs/decisions/approved-commercial-catalog.json',import.meta.url),'utf8'));
  assert.equal(v4.schema_version,4);
  assert.equal(v4.runtime_status,'migration_reference_only_not_connected_to_checkout');
  const performance=v4.offers.find(x=>x.id==='performance');
  assert.deepEqual(performance.channels,['google_ads','meta_ads']);
  assert.equal(performance.direct_checkout_enabled,false);
  const quote=preview([row('chatgpt_ads',180000)]);
  assert.equal(quote.catalog_v4_unchanged,true);
});
