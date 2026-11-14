@php($latest=$order['performance_reports'][0]??null)
<style>.performance-report label{display:block;margin:14px 0}.performance-report input:not([type=hidden]):not([type=checkbox]),.performance-report textarea{display:block;box-sizing:border-box;width:100%;max-width:100%;padding:10px;border:1px solid #bdc6cf;border-radius:8px;font:inherit}.performance-report textarea{min-height:90px}.performance-report button{font:inherit;border:0;cursor:pointer;margin:5px 0}.performance-report dd{margin:0 0 12px}.performance-report details{margin:16px 0}</style>
<div class="performance-report"><x-portal.panel title="Acerto de Performance (teste)">
<p>Compare os honorários antecipados com 20% da mídia efetivamente investida. A mídia é paga diretamente aos canais.</p>
<p>Esta apuração usa somente relatório fictício da primeira antecipação deste pedido. A competência identifica o relatório e não define vencimento ou novo ciclo contratual. Não há cobrança adicional, crédito ou estorno automático.</p>
@forelse($order['performance_reports'] as $entry)
<details @if($loop->first) open @endif><summary>Relatório {{ $entry['report']['period'] }} · versão {{ $entry['version'] }} · {{ ['pending'=>'Aguardando conferência do cliente','acknowledged'=>'Conferido pelo cliente; aguarda financeiro','disputed'=>'Correção solicitada'][$entry['decision']] }}</summary>
<dl><dt>Referência fictícia</dt><dd>{{ $entry['report']['evidence_ref'] }}</dd><dt>Honorários antecipados</dt><dd>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($entry['report']['advance_fee_cents']) }}</dd>
@foreach($entry['report']['channels'] as $channel=>$amounts)
<dt>{{ ['google_ads'=>'Google Ads','meta_ads'=>'Meta Ads'][$channel] }}</dt><dd>Mídia planejada: {{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($amounts['planned_cents']) }} · Investida: {{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($amounts['actual_cents']) }}@if($amounts['below_planned_minimum'])<p>Gasto abaixo do mínimo do planejamento. Revisão necessária; não há complemento automático sobre mídia não investida.</p>@endif</dd>
@endforeach
<dt>Honorários apurados (20%)</dt><dd>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($entry['report']['actual_management_fee_cents']) }}</dd>
<dt>{{ $entry['report']['adjustment_cents']>0?'Diferença a cobrar em análise':($entry['report']['adjustment_cents']<0?'Crédito em análise':'Sem diferença') }}</dt><dd>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl(abs($entry['report']['adjustment_cents'])) }}</dd>
@if($entry['client_note'])<dt>Observação do cliente</dt><dd>{{ $entry['client_note'] }}</dd>@endif
<dt>Registro</dt><dd>{{ $entry['created_at'] }}@if($entry['decided_at']) · Conferência: {{ $entry['decided_at'] }}@endif</dd></dl></details>
@empty<p>A equipe ainda não enviou a apuração de teste.</p>@endforelse
@if($isAdmin&&($latest===null||$latest['decision']==='disputed'))
<form method="post" action="/_preview/portal/work/{{ $order['id'] }}/performance-report">@csrf<input type="hidden" name="revision" value="{{ $order['revision'] }}">
<label>Competência do relatório<input type="month" name="period" required value="{{ old('period',$latest['report']['period']??'') }}"></label>
@foreach($order['offer_snapshot']['media_by_channel_cents'] as $channel=>$planned)
<label>Gasto efetivo fictício · {{ ['google_ads'=>'Google Ads','meta_ads'=>'Meta Ads'][$channel] }} (R$)<input name="actual_{{ $channel }}" inputmode="decimal" maxlength="12" required placeholder="0,00"></label>
@endforeach
<label>Referência fictícia do relatório<input name="evidence_ref" maxlength="80" required placeholder="SIM-RELATORIO-001" pattern="SIM-[A-Z0-9][A-Z0-9-]{2,76}"></label>
<label><input type="checkbox" name="synthetic_ack" value="1" required> Estou usando valores e referência fictícios. Esta apuração não cobra nem concede crédito.</label>
<button class="preview-link" type="submit">{{ $latest===null?'Enviar apuração de teste':'Enviar apuração corrigida' }}</button></form>
@elseif(!$isAdmin&&$latest!==null&&$latest['decision']==='pending')
<form method="post" action="/_preview/portal/work/{{ $order['id'] }}/performance-response">@csrf<input type="hidden" name="revision" value="{{ $order['revision'] }}"><input type="hidden" name="version" value="{{ $latest['version'] }}">
<label>Observação (obrigatória para solicitar correção)<textarea name="note" maxlength="1000"></textarea></label>
<label><input type="checkbox" name="report_ack" value="1" required> Conferência de valores fictícios; não autoriza cobrança real.</label>
<button class="preview-link" name="decision" value="acknowledged" type="submit">Conferir apuração</button> <button class="preview-link" name="decision" value="disputed" type="submit">Solicitar correção da apuração</button></form>
@endif
</x-portal.panel></div>
