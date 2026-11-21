<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Revisão fictícia do plano Google Ads — CI somente</title></head>
<body>
<main style="max-width:760px;margin:36px auto;font-family:system-ui,sans-serif;line-height:1.55">
<h1>Revisão do plano Google Ads (laboratório)</h1>
<p>Este plano usa dados inteiramente <strong>fictícios</strong>. Não autoriza Google Ads,
não cria campanha e não confirma contrato jurídico ou cobrança.</p>
<section aria-label="Plano versionado fictício">
<p>Pedido: <code>{{ $id }}</code></p>
<p>Revisão do pedido: <strong>{{ $revision }}</strong></p>
<p>Versão do diagnóstico: <strong>{{ $plan['diagnostic_version'] }}</strong>.
Versão do plano: <strong>{{ $plan['plan_version'] }}</strong></p>
<p>Fonte das métricas: <strong>{{ $plan['metric_origin'] }}</strong></p>
<p>Impressões fictícias: {{ $plan['sample_impressions'] }};
cliques fictícios: {{ $plan['sample_clicks'] }};
conversões fictícias: {{ $plan['sample_conversions'] }}</p>
<p>Referência de mídia fictícia: R$ {{ number_format($plan['media_cents']/100, 2, ',', '.') }} (não debitada)</p>
<p>Proposta para revisão: <strong>{{ $plan['proposed_action'] }}</strong></p>
<p>Impressão digital SHA-256 exata do plano:
<code id="plan-digest">{{ $plan['plan_fingerprint'] }}</code></p>
<p>Decisão técnica: <strong>revisão interna somente</strong>.
Aprovação do comprador:
<strong id="approval-state">{{ $plan['plan_approval_state'] }}</strong></p>
</section>
@if(!$plan['buyer_plan_approved'])
<form method="post" action="/_preview/portal/shop/orders/{{ $id }}/plan-review">
@csrf
<input type="hidden" name="revision" value="{{ $revision }}">
<input type="hidden" name="plan_version" value="{{ $plan['plan_version'] }}">
<input type="hidden" name="plan_fingerprint" value="{{ $plan['plan_fingerprint'] }}">
<label><input type="checkbox" name="approval_ack" value="1" required>
Li e aprovo exclusivamente a versão fictícia do plano exibida acima.
Isto não autoriza campanha nem acesso à conta.</label>
<p><button type="submit">Aprovar plano fictício exibido</button></p>
</form>
@else
<p><strong>O comprador aprovou esta revisão fictícia do plano.</strong></p>
@endif
</main></body></html>
