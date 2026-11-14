<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Contratação de teste · Imagine Portal</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f7f8fa;color:#20262b;font:16px/1.6 system-ui,sans-serif}header,main{max-width:1000px;margin:auto;padding:24px}header{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap}h1{font-size:clamp(26px,5vw,38px);line-height:1.2}h2{font-size:22px}.brand{font-size:26px;font-weight:750}.brand span{color:#f9941d}.notice{background:#fff2db;padding:16px;border-radius:12px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:20px}.card,section{background:white;padding:24px;border-radius:16px;border:1px solid #e1e5e9;margin:20px 0}button{background:#f9941d;border:0;border-radius:9px;padding:13px 20px;font:inherit;font-weight:650;cursor:pointer}a{color:#704000}label{display:block;margin:14px 0}input:not([type=checkbox]),textarea,select{display:block;width:100%;padding:12px;font:inherit;border:1px solid #aab4bf;border-radius:8px}textarea{min-height:85px}input[type=checkbox]{width:20px;height:20px}small{display:block}dt{font-weight:650}dd{margin:0 0 16px;overflow-wrap:anywhere}code{overflow-wrap:anywhere}button:focus-visible,a:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible{outline:3px solid #075c9e;outline-offset:3px}.status{font-weight:700}
</style></head><body><header><div class="brand">imagine<span>.</span> <small>Portal · contratação de teste</small></div><nav aria-label="Navegação de teste"><a href="{{ url('/_preview/portal/shop') }}">Escolher outro serviço</a> · <a href="/_preview/portal">Voltar ao Portal de teste</a></nav></header><main>
<p class="notice"><strong>Ambiente de homologação com dados fictícios.</strong> O pedido e o briefing são gravados no banco de teste. A conta é exclusiva de teste. Não há contrato jurídico, pagamento ou execução real de serviço. Use somente informações de exemplo.</p>
@if($errors->any())<section role="alert"><h2>Confira os campos</h2><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></section>@endif
@if($order === null)
<h1>Escolha o serviço para testar a contratação</h1><p>Selecione uma oferta e acompanhe seu pedido até o briefing.</p><div class="cards">
@foreach(['onepage'=>['Site Onepage','Apresente sua empresa em uma página.'],'landing-pages'=>['Landing Page','Uma página voltada para sua campanha.'],'performance'=>['Performance','Planeje uma campanha para seu negócio.']] as $slug=>$offer)
    @if($selectedProduct === null || $selectedProduct === $slug)
        <section class="card">
            <h2>{{ $offer[0] }}</h2>
            <p>{{ $offer[1] }}</p>
            @if($slug === 'performance')
                @if($selectedChannel === 'google_ads')
                    <p class="notice" data-channel-origin="google_ads">Você veio do serviço Google Ads no site Imagine. Informe e confirme o orçamento de mídia abaixo; nenhum valor foi escolhido automaticamente.</p>
                @elseif($selectedChannel === 'meta_ads')
                    <p class="notice" data-channel-origin="meta_ads">Você veio do serviço Meta Ads no site Imagine. Informe e confirme o orçamento de mídia abaixo; nenhum valor foi escolhido automaticamente.</p>
                @endif
                <p><strong>Gestão Imagine: 20% da mídia investida.</strong> Mínimo de R$ 700 de anúncios por canal, pago diretamente ao Google ou à Meta. Honorários Imagine são separados da verba de anúncios.</p>
                <p>A estimativa será registrada no pedido; período de apuração e acerto financeiro ainda precisam ser definidos para cobrança real.</p>
            @else
                @include('portal.preview.offer-summary',['quote'=>(new \App\Homologation\Commerce\ApprovedOfferCatalog())->quote($slug)])
            @endif
            <form method="post" action="{{ url('/_preview/portal/shop/orders') }}">
                @csrf
                <input type="hidden" name="product" value="{{ $slug }}">
                <input type="hidden" name="request_key" value="{{ $requestKeys[$slug] }}">
                <input type="hidden" name="offer_version" value="{{ $offerVersion }}">
                @if($slug === 'performance')
                    <label>Investimento Google Ads (R$; vazio se não contratar)<input name="google_media" inputmode="decimal" placeholder="700,00" maxlength="12"></label>
                    <label>Investimento Meta (R$; vazio se não contratar)<input name="meta_media" inputmode="decimal" placeholder="700,00" maxlength="12"></label>
                    <small>Instagram e Facebook pertencem ao mesmo canal Meta.</small>
                @endif
                <label><input type="checkbox" name="offer_ack" value="1" required> Revisei as condições para esta simulação, sem cobrança ou contrato real.</label>
                <button type="submit">Criar pedido de teste</button>
            </form>
        </section>
    @endif
@endforeach
</div>
@else
<h1>Seu pedido de teste</h1><p><code>{{ $order['id'] }}</code></p><section><h2>1. Resumo</h2><dl><dt>Serviço</dt><dd>{{ ['onepage'=>'Site Onepage','landing-pages'=>'Landing Page','performance'=>'Performance'][$order['product']] }}</dd><dt>Contratação</dt><dd>Somente demonstração; sem valor cobrado ou aceite jurídico.</dd><dt>Pagamento fictício</dt><dd class="status">{{ ['approved'=>'Aprovado','pending'=>'Pendente','declined'=>'Recusado'][$order['payment']] }}</dd></dl>
@if($order['offer_snapshot'] !== null)
    @include('portal.preview.offer-summary',['quote'=>$order['offer_snapshot']])
    <small>Condições registradas neste pedido em {{ $order['offer_accepted_at'] }}. A simulação não constitui assinatura de contrato.</small>
@else
    <p>Pedido anterior à inclusão do catálogo. Nenhum preço foi atribuído retroativamente.</p>
@endif
</section>
@if($order['briefing'] === null)
<section><h2>2. Testar o resultado do pagamento</h2><p>Escolha um cenário. Pendente e recusado impedem o envio do briefing.</p><form method="post" action="{{ url('/_preview/portal/shop/orders/'.$order['id'].'/payment') }}">@csrf<input type="hidden" name="revision" value="{{ $order['revision'] }}"><label><input name="sample_ack" type="checkbox" value="1" required> Entendi que este é um teste, sem contrato ou cobrança real.</label><label>Resultado fictício<select name="outcome"><option value="approved">Aprovado</option><option value="pending">Pendente</option><option value="declined">Recusado</option></select></label><button type="submit">Aplicar resultado de teste</button></form></section>
@if($order['payment'] === 'approved')
<section><h2>3. Preencher o briefing de exemplo</h2><form method="post" action="{{ url('/_preview/portal/shop/orders/'.$order['id'].'/briefing') }}">@csrf<input type="hidden" name="revision" value="{{ $order['revision'] }}">@foreach(['business'=>'Nome da empresa fictícia','goal'=>'Objetivo','audience'=>'Público','materials'=>'Materiais de exemplo'] as $field=>$label)<label>{{ $label }}<textarea name="{{ $field }}" required maxlength="200">{{ old($field) }}</textarea></label>@endforeach<button type="submit">Salvar briefing de teste</button></form></section>
@else<p>O briefing será liberado após selecionar pagamento fictício aprovado.</p>@endif
@else
<section><h2>Briefing salvo no Portal de teste</h2><p>Atualize esta página para conferir a recuperação do pedido e do briefing pelo servidor.</p><dl>@foreach($order['briefing'] as $field=>$value)<dt>{{ ['business'=>'Empresa fictícia','goal'=>'Objetivo','audience'=>'Público','materials'=>'Materiais'][$field] }}</dt><dd>{{ $value }}</dd>@endforeach</dl></section>
@endif
@endif
<p><a href="/_preview/portal/client/services">Ver meus pedidos no painel</a></p><footer>Imagine · homologação privada · sem cobrança real</footer></main></body></html>
