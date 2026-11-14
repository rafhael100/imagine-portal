/** F409: entirely offline preview; never imported by billing, Laravel routes or PSP. */
export const CHANNELS = Object.freeze({
  meta_ads: Object.freeze({name:'Meta (Facebook e Instagram)',activation:49000,minimum:70000,recommended:100000}),
  google_ads: Object.freeze({name:'Google Ads',activation:49000,minimum:100000,recommended:150000}),
  chatgpt_ads: Object.freeze({name:'ChatGPT Ads (candidato)',activation:49000,minimum:120000,recommended:180000})
});
export const APPROVED_FOR_DEMO = Object.freeze(['meta_ads','google_ads']);

export function quoteChannelSelection(channels,budgetsCents,eligible=APPROVED_FOR_DEMO){
  if(!Array.isArray(channels)||channels.length<1||channels.length>3
    ||new Set(channels).size!==channels.length||!Array.isArray(eligible)) {
    throw new RangeError('Selecione canais únicos e elegíveis');
  }
  if(!budgetsCents||typeof budgetsCents!=='object'||Array.isArray(budgetsCents)){
    throw new TypeError('Orçamentos por canal são obrigatórios');
  }
  const eligibility = new Set(eligible);
  let sumMedia=0,activation=0,minMedia=0,recommended=0;
  const rows=[];
  for(const id of channels){
    if(typeof id!=='string'||!Object.hasOwn(CHANNELS,id)||!eligibility.has(id)){
      throw new RangeError('Canal não habilitado');
    }
    const descriptor=CHANNELS[id],cents=budgetsCents[id];
    if(!Number.isSafeInteger(cents)||cents<descriptor.minimum||cents>100000000){
      throw new RangeError('Orçamento abaixo do mínimo ou inválido');
    }
    activation+=descriptor.activation;
    sumMedia+=cents;
    minMedia+=descriptor.minimum;
    recommended+=descriptor.recommended;
    rows.push(Object.freeze({id,name:descriptor.name,budget_cents:cents,
      minimum_cents:descriptor.minimum,recommended_cents:descriptor.recommended,
      activation_cents:descriptor.activation}));
  }
  if(Object.keys(budgetsCents).some(id=>!channels.includes(id))){
    throw new RangeError('Orçamento de canal não selecionado');
  }
  return Object.freeze({
    rows:Object.freeze(rows),currency:'BRL',
    activation_now_cents:activation,
    media_total_cents:sumMedia,
    media_minimum_cents:minMedia,
    media_recommended_cents:recommended,
    illustrative_twenty_percent_cents:Math.floor((sumMedia*2000+5000)/10000),
    real_management_invoice_authorized:false,
    minimum_management_fee_cents:null,
    media_paid_directly_to_platforms:true,
    checkout_enabled:false
  });
}
export const formatBRL=cents=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'})
  .format(cents/100);
