/**
 * PORTAL PERF-P5 CANDIDATE — internal, OFFLINE commercial quotation preview.
 * NOT imported by checkout, app routes, Asaas or active catalog schema v4.
 *
 * Approved values from owner on 2026-11-14; read-only candidate file is NOT
 * authorization to charge, launch ads, accept contracts or reconcile spend.
 */
import {readFileSync} from 'node:fs';

const file=new URL('../docs/decisions/performance-channel-minima-proposal-v1.json',import.meta.url);
const candidate=JSON.parse(readFileSync(file,'utf8'));
const ORDER=['chatgpt_ads','google_ads','meta_ads'];
const MAX_CENTS=100000000;

function assertCandidate(){
  if(candidate.schema_version!=='performance_channel_minima_proposal_v1'||
     candidate.currency!=='BRL' ||
     candidate.commercial_minima_approved!==true ||
     candidate.management_fee_basis_points!==2000 ||
     candidate.checkout_enabled!==false ||
     candidate.asaas_charge_authorized!==false ||
     candidate.ad_write_enabled!==false ||
     candidate.provider_charge_authorized!==false)
    throw new Error('Candidate v5 commercial gate failed');
  const exact=(o)=>o&&typeof o==='object'&&!Array.isArray(o)&&
    Object.keys(o).sort().join(',')===ORDER.join(',');
  if(!exact(candidate.channel_minimums_cents)||
     !exact(candidate.channel_recommended_cents))
    throw new Error('Candidate channel inventory diverged');
  const floors=[120000,100000,70000],recommend=[180000,150000,100000];
  for(let i=0;i<ORDER.length;i++){
    const id=ORDER[i];
    if(candidate.channel_minimums_cents[id]!==floors[i]||
       candidate.channel_recommended_cents[id]!==recommend[i]) {
      throw new Error('Candidate pricing differs from approved decision');
    }
  }
}
assertCandidate();

export function previewPerformanceChannelQuoteV5(channels) {
  if(!Array.isArray(channels)||Object.getPrototypeOf(channels)!==Array.prototype||
     channels.length<1||channels.length>3||
     Reflect.ownKeys(channels).length!==channels.length+1)
    throw new RangeError('One to three media channels are required.');
  const selected=new Set(),rows=[];
  for(let i=0;i<channels.length;i++){
    const descriptor=Object.getOwnPropertyDescriptor(channels,String(i));
    if(!descriptor?.enumerable||!Object.hasOwn(descriptor,'value'))
      throw new RangeError('Invalid channel array.');
    const row=descriptor.value;
    if(!row||typeof row!=='object'||Array.isArray(row)||
       Object.getPrototypeOf(row)!==Object.prototype||
       Reflect.ownKeys(row).length!==2)
      throw new RangeError('Invalid channel quote row.');
    const keys=['channel','monthly_media_cents'];
    if(Reflect.ownKeys(row).some(x=>typeof x!=='string'||!keys.includes(x)||
       !Object.getOwnPropertyDescriptor(row,x)?.enumerable||
       !Object.hasOwn(Object.getOwnPropertyDescriptor(row,x),'value')))
      throw new RangeError('Unexpected channel quote property');
    const {channel,monthly_media_cents}=row;
    if(!ORDER.includes(channel)||selected.has(channel)||
       !Number.isSafeInteger(monthly_media_cents)||
       monthly_media_cents<candidate.channel_minimums_cents[channel]||
       monthly_media_cents>MAX_CENTS) {
      throw new RangeError('Unsupported, duplicate or below-minimum media plan');
    }
    selected.add(channel);
    rows.push(Object.freeze({
      channel,
      monthly_media_cents,
      imagine_monthly_floor_cents:candidate.channel_minimums_cents[channel],
      imagine_recommended_cents:candidate.channel_recommended_cents[channel]
    }));
  }
  rows.sort((a,b)=>a.channel.localeCompare(b.channel,'en'));
  const plannedMediaCents=rows.reduce((s,r)=>s+r.monthly_media_cents,0);
  const minimumMediaCents=rows.reduce((s,r)=>s+r.imagine_monthly_floor_cents,0);
  const recommendedMediaCents=rows.reduce((s,r)=>s+r.imagine_recommended_cents,0);
  const illustrativeManagementCents=Math.floor(
    (plannedMediaCents*candidate.management_fee_basis_points+5000)/10000);
  return Object.freeze({
    schema:'imagine.portal.performance.quote-v5-candidate-offline',
    catalog_status:'owner_approved_values_runtime_migration_pending',
    currency:'BRL',
    rows:Object.freeze(rows),
    minimum_media_cents:minimumMediaCents,
    recommended_media_cents:recommendedMediaCents,
    planned_media_cents:plannedMediaCents,
    illustrative_management_fee_cents:illustrativeManagementCents,
    informational_total_cents:plannedMediaCents+illustrativeManagementCents,
    media_paid_directly_to_providers:true,
    management_applies_to_verified_actual_media_spend:true,
    estimate_is_not_invoice:true,
    catalog_v4_unchanged:true,
    portal_checkout_enabled:false,
    asaas_charge_enabled:false,
    ads_write_enabled:false
  });
}
