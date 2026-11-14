@if($quote['product'] === 'performance')
@if(($quote['pricing_policy']??null) === \App\Homologation\Commerce\PerformancePostpaidPolicy::VERSION)
<h3>Pagar agora: ativação</h3><p><strong>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['activation_total_cents']) }}</strong> · R$490 por canal, uma única vez.</p>
<h3>Gestão mensal após apuração</h3><p>20% do investimento efetivo, com mínimo de <strong>R$170/mês em cada canal contratado</strong>. Estimativa para o orçamento informado: {{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['estimated_management_fee_cents']) }}/mês.</p>
<p>A gestão é apurada por mês civil e cobrada depois. O investimento em anúncios é pago diretamente às plataformas. Facebook e Instagram contam como um único canal Meta Ads.</p>
<dl>@foreach($quote['media_by_channel_cents'] as $channel=>$amount)<dt>Mídia planejada · {{ $channel==='google_ads'?'Google Ads':'Meta Ads' }}</dt><dd>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($amount) }} · pagar à plataforma</dd>@endforeach</dl>
@else
<dl>
@foreach($quote['media_by_channel_cents'] as $channel=>$amount)
<dt>Mídia {{ $channel === 'google_ads' ? 'Google Ads' : 'Meta (Instagram/Facebook)' }} — pagar ao canal</dt><dd>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($amount) }}</dd>
@endforeach
<dt>Gestão Imagine — honorários antecipados estimados de 20%</dt><dd>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['estimated_management_fee_cents']) }}</dd>
</dl><p>Somente os honorários serão cobrados pela Imagine via Asaas. A verba dos anúncios é paga diretamente aos canais. O acerto mensal compara os honorários antecipados com 20% da mídia efetivamente investida. Esta simulação não autoriza gasto ou cobrança real.</p>
@endif
@else
<p><strong>Implantação: {{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['setup_total_cents']) }}</strong></p>
<ul>@foreach($quote['setup_charges'] as $charge)<li>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($charge['amount_cents']) }} — {{ $charge['offset_days'] === 0 ? 'na contratação' : 'após '.$charge['offset_days'].' dias' }}</li>@endforeach</ul>
<p>Assinatura obrigatória de <strong>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['monthly']['amount_cents']) }}/mês</strong>, com primeiro vencimento 90 dias após a contratação, independentemente da publicação.</p>
<p>Conteúdo em blocos existentes incluído, sob uso justo. Layout, novos blocos e funcionalidades exigem orçamento separado.</p>
@endif
