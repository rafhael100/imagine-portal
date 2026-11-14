@if($quote['product'] === 'performance')
<dl>
@foreach($quote['media_by_channel_cents'] as $channel=>$amount)
<dt>Mídia {{ $channel === 'google_ads' ? 'Google Ads' : 'Meta (Instagram/Facebook)' }} — pagar ao canal</dt><dd>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($amount) }}</dd>
@endforeach
<dt>Gestão Imagine — estimativa de 20%</dt><dd>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['estimated_management_fee_cents']) }}</dd>
</dl><p>Somente os honorários serão cobrados pela Imagine via Asaas. A verba dos anúncios é paga diretamente aos canais. Esta estimativa não autoriza gasto e depende de apuração para cobrança real.</p>
@else
<p><strong>Implantação: {{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['setup_total_cents']) }}</strong></p>
<ul>@foreach($quote['setup_charges'] as $charge)<li>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($charge['amount_cents']) }} — {{ $charge['offset_days'] === 0 ? 'na contratação' : 'após '.$charge['offset_days'].' dias' }}</li>@endforeach</ul>
<p>Assinatura obrigatória de <strong>{{ \App\Homologation\Commerce\ApprovedOfferCatalog::brl($quote['monthly']['amount_cents']) }}/mês</strong>, com primeiro vencimento 90 dias após a contratação, independentemente da publicação.</p>
<p>Conteúdo em blocos existentes incluído, sob uso justo. Layout, novos blocos e funcionalidades exigem orçamento separado.</p>
@endif
