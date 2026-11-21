<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recibo de preparação pausada — teste Imagine</title></head>
<body><main style="max-width:780px;margin:36px auto;font-family:system-ui,sans-serif;line-height:1.55">
<h1>Recibo de preparação pausada — somente laboratório</h1>
<p>Este recibo confirma apenas que um <strong>rascunho fictício</strong> do mesmo pedido
foi recebido e relido em um <strong>simulador HTTP isolado</strong>.
Nenhuma campanha foi ativada, entregue, publicada ou cobrada;
a conta Google Ads não está conectada e não há autorização operacional.</p>
<section aria-label="Recibo do pedido de laboratório">
<p>Pedido: <code id="receipt-order">{{ $id }}</code></p>
<p>Revisão do briefing aprovada: <strong id="receipt-revision">{{ $revision }}</strong></p>
<p>SHA-256 do briefing: <code id="receipt-briefing">{{ $briefingSha }}</code></p>
<p>SHA-256 do plano: <code id="receipt-plan">{{ $planSha }}</code></p>
<p>SHA-256 do rascunho: <code id="receipt-draft">{{ $draftSha }}</code></p>
<p>Canal: <strong>Google Ads (simulado)</strong></p>
<p>Estado exato do simulador: <strong id="receipt-state">{{ $stageState }}</strong></p>
<p>Reenvios: <strong>um único registro pausado</strong></p>
<p><strong id="receipt-limits">Ativado: não · Entregue: não · Publicado: não · Cobrável: não</strong></p>
</section>
<p>Fonte de autoridade oficial e consentimento por conta não comprovados.
Este recibo não é aceite contratual, licença de uso de conta, publicação nem comprovante de pagamento real.</p>
</main></body></html>
