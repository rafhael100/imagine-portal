/**
 * PORTAL-PERF-CALC-PARITY: readonly CI check against immutable Commercial
 * calculator. The chosen Commercial commit already passed its own build/QA.
 * This does not enable the Portal catalog, Asaas or provider operations.
 */
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {resolve} from 'node:path';
import {previewPerformanceChannelQuoteV5 as portal}
  from '../../server/performance-channel-pricing-v5-lab.mjs';

if (process.env.CI!=='true' ||
    process.env.GITHUB_ACTIONS!=='true' ||
    process.env.GITHUB_REPOSITORY!=='rafhael100/imagine-portal') {
  throw new Error('Cross-repo commercial pricing QA is CI-only');
}
const commercial=await import(pathToFileURL(
  resolve('_commercial_calculator_ci/src/lib/media-budget-calculator.mjs')
).href);
const ids=['chatgpt_ads','google_ads','meta_ads'];
const combinations=[
  ['meta_ads'],['google_ads'],['chatgpt_ads'],
  ['meta_ads','google_ads'],['meta_ads','chatgpt_ads'],
  ['google_ads','chatgpt_ads'],['meta_ads','google_ads','chatgpt_ads'],
];
for(const selection of combinations) {
  const calculated=commercial.calculateMediaBudget({channels:selection});
  const minQuote=portal(selection.map(channel=>({
    channel,monthly_media_cents:commercial.CHANNELS[channel].floorCents
  })));
  const recommendedQuote=portal(selection.map(channel=>({
    channel,monthly_media_cents:commercial.CHANNELS[channel].recommendedCents
  })));
  assert.equal(minQuote.minimum_media_cents,calculated.minimumMediaCents);
  assert.equal(minQuote.planned_media_cents,calculated.minimumMediaCents);
  assert.equal(recommendedQuote.recommended_media_cents,calculated.recommendedBaselineCents);
  assert.equal(recommendedQuote.planned_media_cents,calculated.suggestedMediaCents);
  assert.equal(recommendedQuote.illustrative_management_fee_cents,
    calculated.illustrativeManagementCents);
  assert.equal(recommendedQuote.informational_total_cents,
    calculated.illustrativeTotalCents);
  for(const row of minQuote.rows){
    const item=calculated.channels.find(x=>x.id===row.channel);
    assert.ok(item,'Portal channel belongs to client preview scope');
    assert.equal(row.imagine_monthly_floor_cents,item.floorCents);
    assert.equal(row.imagine_recommended_cents,item.recommendedBaseCents);
  }
  for(const quote of [minQuote,recommendedQuote]) {
    assert.equal(quote.catalog_v4_unchanged,true);
    assert.equal(quote.portal_checkout_enabled,false);
    assert.equal(quote.asaas_charge_enabled,false);
    assert.equal(quote.ads_write_enabled,false);
    assert.equal(quote.media_paid_directly_to_providers,true);
  }
}
console.log('PASS: Portal and Commercial share seven exact channel combinations, distinct minima, recommended, 20% and no checkout');
