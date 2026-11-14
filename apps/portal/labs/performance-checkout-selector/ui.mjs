import {quoteChannelSelection, formatBRL, CHANNELS, APPROVED_FOR_DEMO} from './calculator.mjs';

// Local in-browser demonstration. No fetch, form submit, storage or PSP/API.
const toggles = [...document.querySelectorAll('input[data-channel]')];
const summaries = {
  count: document.getElementById('channel-count'),
  activation: document.getElementById('activation'),
  media: document.getElementById('media'),
  management: document.getElementById('management'),
  error: document.getElementById('error')
};
function update(){
  const selected=[];
  const budgets={};
  for(const checkbox of toggles){
    const id=checkbox.dataset.channel;
    const row=document.querySelector('[data-budget="'+id+'"]');
    if(row){
      row.hidden=!checkbox.checked;
      const input=row.querySelector('input[type=number]');
      input.disabled=!checkbox.checked;
      if(checkbox.checked){
        selected.push(id);
        const value=Number(input.value);
        budgets[id]=Number.isSafeInteger(value) ? value*100 : NaN;
      }
    }
  }
  try {
    if(!selected.length) throw new RangeError('Selecione pelo menos um canal.');
    const quote=quoteChannelSelection(selected,budgets,APPROVED_FOR_DEMO);
    summaries.count.textContent=String(quote.rows.length);
    summaries.activation.textContent=formatBRL(quote.activation_now_cents);
    summaries.media.textContent=formatBRL(quote.media_total_cents);
    summaries.management.textContent=formatBRL(quote.illustrative_twenty_percent_cents);
    summaries.error.textContent='';
  } catch (e) {
    summaries.count.textContent=String(selected.length);
    summaries.activation.textContent='—';
    summaries.media.textContent='—';
    summaries.management.textContent='—';
    summaries.error.textContent=e instanceof Error ? e.message : 'Valores inválidos';
  }
}
for(const element of document.querySelectorAll('input[data-channel], input[type=number]')){
  element.addEventListener('input',update);
  element.addEventListener('change',update);
}
update();
