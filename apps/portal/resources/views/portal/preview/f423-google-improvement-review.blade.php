<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Revisão de melhoria fictícia Google Ads — laboratório CI</title></head>
<body><main style="max-width:760px;margin:36px auto;font-family:system-ui,sans-serif;line-height:1.55">
<h1>Revisar proposta de melhoria (somente laboratório)</h1>
<p>Os resultados e as duas amostras abaixo são <strong>fictícios</strong>. A aprovação
não publica nem modifica campanhas, não autoriza conta Google Ads, não significa
consentimento operacional e não gera cobrança.</p>
<section aria-label="Proposta de melhoria offline">
<p>Pedido <code>{{ $id }}</code> · revisão <strong>{{ $revision }}</strong> ·
versão da proposta <strong>{{ $proposal['proposal_version'] }}</strong></p>
<p>Ação simulada: <strong>{{ $proposal['action'] }}</strong></p>
<p>Origem: <strong>{{ $proposal['metric_origin'] }}</strong></p>
<p>Referência do rascunho não publicado: <code>{{ $proposal['draft_fingerprint'] }}</code></p>
<p>SHA-256 das duas amostras fictícias: <code>{{ $proposal['evidence_sha256'] }}</code></p>
<p>SHA-256 exato desta proposta: <code id="proposal-digest">{{ $proposal['proposal_fingerprint'] }}</code></p>
<p>Estado da aprovação separada:
<strong id="approval-state">{{ $proposal['approval_state'] }}</strong></p>
</section>
@if(!$proposal['separate_approval_recorded'])
<form method="post" action="/_preview/portal/shop/orders/{{ $id }}/improvement-review">
@csrf
<input type="hidden" name="revision" value="{{ $revision }}">
<input type="hidden" name="proposal_version" value="{{ $proposal['proposal_version'] }}">
<input type="hidden" name="proposal_fingerprint" value="{{ $proposal['proposal_fingerprint'] }}">
<label><input type="checkbox" name="approval_ack" value="1" required>
Aprovo somente esta sugestão de melhoria OFFLINE exibida, sem aplicar ou publicar.</label>
<p><button type="submit">Aprovar melhoria fictícia exibida</button></p>
</form>
@else
<p><strong>O comprador aprovou esta proposta fictícia; aplicação ainda não realizada.</strong></p>
@endif
</main></body></html>
