@php($labels=['queued'=>'Aguardando análise do briefing','in_production'=>'Em produção de teste','awaiting_approval'=>'Aguardando sua aprovação','changes_requested'=>'Ajustes solicitados','completed'=>'Concluído no teste'])
<x-portal.layout title="Acompanhamento do pedido" :current="$isAdmin?'orders':'journey'">
<x-portal.panel title="Jornada do seu projeto"><p><strong>{{ $order['id'] }}</strong> · {{ $order['customer_name'] }} · {{ $order['company'] }}</p><p role="status">{{ $labels[$order['work_status']] }}</p><p>Esta jornada registra ações no ambiente de testes. Não ativa serviço ou publicação real.</p>
@if($order['offer_snapshot']!==null)<h3>Condições registradas no pedido</h3>@include('portal.preview.offer-summary',['quote'=>$order['offer_snapshot']])@endif
@if($order['briefing']===null)
<p>Envie o briefing após o pagamento simulado aprovado para que a equipe possa iniciar.</p>
@else
<h3>Briefing recebido</h3><dl>@foreach($order['briefing'] as $field=>$value)<dt>{{ ['business'=>'Empresa','goal'=>'Objetivo','audience'=>'Público','materials'=>'Materiais'][$field] }}</dt><dd>{{ $value }}</dd>@endforeach</dl>
@endif
@if($order['delivery_note'])<h3>Entrega de teste</h3><p style="white-space:pre-wrap">{{ $order['delivery_note'] }}</p>@endif
@if($isAdmin&&$order['briefing']!==null&&in_array($order['work_status'],['queued','changes_requested'],true))
<form method="post" action="/_preview/portal/work/{{ $order['id'] }}">@csrf<input type="hidden" name="revision" value="{{ $order['revision'] }}"><input type="hidden" name="action" value="start"><button class="preview-link" type="submit">Iniciar produção de teste</button></form>
@endif
@if($isAdmin&&$order['work_status']==='in_production')
<form method="post" action="/_preview/portal/work/{{ $order['id'] }}">@csrf<input type="hidden" name="revision" value="{{ $order['revision'] }}"><input type="hidden" name="action" value="deliver"><label for="delivery">Descreva a entrega de exemplo</label><textarea style="display:block;width:100%;max-width:100%" id="delivery" name="note" maxlength="1000" required></textarea><button class="preview-link" type="submit">Enviar entrega para aprovação</button></form>
@endif
@if(!$isAdmin&&$order['work_status']==='awaiting_approval')
<form method="post" action="/_preview/portal/work/{{ $order['id'] }}">@csrf<input type="hidden" name="revision" value="{{ $order['revision'] }}"><button class="preview-link" name="action" value="approve" type="submit">Aprovar entrega de teste</button></form>
<form method="post" action="/_preview/portal/work/{{ $order['id'] }}">@csrf<input type="hidden" name="revision" value="{{ $order['revision'] }}"><input type="hidden" name="action" value="request_changes"><label for="changes">Quais ajustes deseja?</label><textarea style="display:block;width:100%;max-width:100%" id="changes" name="note" maxlength="1000" required></textarea><button class="preview-link" type="submit">Solicitar ajustes</button></form>
@endif
</x-portal.panel>
<x-portal.panel title="Conversa e histórico"><p>As mensagens ficam registradas neste pedido. Não há envio de e-mail ou WhatsApp nesta etapa.</p><ol>@forelse($order['events'] as $event)<li><strong>{{ $event['actor_kind']==='admin'?'Equipe Imagine':'Cliente' }}</strong> · {{ ['start'=>'Produção iniciada','deliver'=>'Entrega enviada','approve'=>'Entrega aprovada','request_changes'=>'Ajustes solicitados','message'=>'Mensagem'][$event['action']] }} · {{ $event['created_at'] }}<p style="white-space:pre-wrap">{{ $event['note'] }}</p></li>@empty<li>Ainda não há mensagens ou ações da equipe.</li>@endforelse</ol><form method="post" action="/_preview/portal/work/{{ $order['id'] }}">@csrf<input type="hidden" name="revision" value="{{ $order['revision'] }}"><input type="hidden" name="action" value="message"><label for="message">Mensagem de exemplo</label><textarea style="display:block;width:100%;max-width:100%" id="message" name="note" maxlength="1000" required></textarea><button class="preview-link" type="submit">Enviar mensagem</button></form></x-portal.panel>
@if(!$isAdmin)<p><a href="/_preview/portal/shop/orders/{{ $order['id'] }}">Ver contratação, pagamento e briefing</a></p>@endif
</x-portal.layout>
