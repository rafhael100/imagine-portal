@if($quote['product'] === 'performance')
<dl>
@foreach($quote['media_by_channel_cents'] as $channel=>$amount)
<dt>Mídia {{ $channel === 'google_ads' ? 'Google Ads' : 'Meta (Instagram/Facebook)' }} — pagar ao canal</dt><dd>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($amount) }}</dd>
@endforeach
@if(($quote['pricing_policy'] ?? null) === 'performance-postpaid-170-per-channel-2026-11-14')
<dt>Ativação única dos canais selecionados (R$ 490 por canal)</dt>
<dd><strong>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['activation_total_cents']) }}</strong></dd>
<dt>Gestão mensal estimada — 20% ou R$ 170 por canal (o maior, mês completo)</dt>
<dd>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['estimated_management_fee_cents']) }}</dd>
@else
<dt>Gestão Imagine — honorários antecipados estimados de 20% (contrato legado)</dt>
<dd>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['estimated_management_fee_cents']) }}</dd>
@endif
</dl>
@if(($quote['pricing_policy'] ?? null) === 'performance-postpaid-170-per-channel-2026-11-14')
<p><strong>Nesta proposta, paga-se apenas a ativação dos canais na contratação.</strong>
A gestão é apurada após o mês civil, por canal, com base na mídia efetivamente
comprovada. A verba dos anúncios é paga diretamente ao Google ou à Meta.
O primeiro mês parcial precisa de avaliação contratual e não gera cobrança
automática. Esta simulação não é uma fatura, não habilita Asaas/C6 para Performance
nem autoriza a veiculação dos anúncios.</p>
@else
<p>Condições legadas: somente os honorários antecipados são cobrados
pela Imagine; a mídia é paga diretamente às plataformas. O acerto mensal
compara o adiantamento com 20% do gasto efetivo, segundo os termos históricos.
A simulação não autoriza cobrança real.</p>
@endif
@else
<p><strong>Implantação: {{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['setup_total_cents']) }}</strong></p>
<ul>@foreach($quote['setup_charges'] as $charge)<li>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($charge['amount_cents']) }} — {{ $charge['offset_days'] === 0 ? 'na contratação' : 'após '.$charge['offset_days'].' dias' }}</li>@endforeach</ul>
<p>Assinatura obrigatória de <strong>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['monthly']['amount_cents']) }}/mês</strong>, com primeiro vencimento 90 dias após a contratação, independentemente da publicação.</p>
<p>Conteúdo em blocos existentes incluído, sob uso justo. Layout, novos blocos e funcionalidades exigem orçamento separado.</p>
@endif
