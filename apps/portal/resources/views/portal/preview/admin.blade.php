@php($titles=['overview'=>'Visão geral','clients'=>'Clientes','orders'=>'Pedidos','services'=>'Serviços','finance'=>'Financeiro','contracts'=>'Contratos','requests'=>'Solicitações','settings'=>'Configurações'])
<x-portal.layout :title="$titles[$section]" :current="$section">
@if($section==='overview')
<div class="pi-grid"><x-portal.indicator label="Cliente de teste" value="1"/><x-portal.indicator label="Pedidos gravados no teste" :value="count($orders)"/><x-portal.indicator label="Cobranças reais" value="0"/></div><x-portal.panel title="Central de administração"><p>Visão de trabalho da Imagine para acompanhar clientes, pedidos, serviços e pendências.</p><a class="preview-link" href="/_preview/portal/admin/orders">Ver pedidos de teste</a></x-portal.panel>
@elseif(in_array($section,['orders','services','finance'],true))
<x-portal.panel :title="$section==='finance'?'Pagamentos fictícios dos pedidos':'Pedidos da empresa de exemplo'"><div class="preview-table"><table><thead><tr><th>Referência</th><th>Serviço</th><th>Pagamento de teste</th><th>Briefing</th></tr></thead><tbody>@forelse($orders as $order)<tr><td>{{ $order['id'] }}</td><td>{{ ['onepage'=>'Onepage','landing-pages'=>'Landing Page','performance'=>'Performance'][$order['product']] }}</td><td>{{ ['approved'=>'Aprovado','pending'=>'Pendente','declined'=>'Recusado'][$order['payment']] }}</td><td>{{ in_array($order['briefing_saved'],[true,'t',1,'1'],true)?'Salvo':'Aguardando' }}</td></tr>@empty<tr><td colspan="4">Nenhum pedido de teste foi criado.</td></tr>@endforelse</tbody></table></div><p>Os registros desta tabela vêm do banco de homologação. Não representam cobrança ou execução real de serviço.</p></x-portal.panel>
@elseif($section==='clients')
<x-portal.panel title="Empresa de exemplo"><p>Empresa Aurora · cliente fictício para avaliação da navegação.</p><p>Cadastro, convites e administração de empresas reais ainda não estão habilitados nesta versão.</p></x-portal.panel>
@elseif($section==='settings')
<x-portal.panel title="Configurações do ambiente"><p>Perfil: administrador de teste.</p><p>Esta homologação usa dados fictícios e mantém a cobrança real desabilitada.</p><p>As configurações operacionais de empresas e permissões ainda não estão disponíveis nesta tela.</p></x-portal.panel>
@else
<x-portal.panel :title="$titles[$section]"><p>Esta área está prevista para a administração do Portal. O fluxo operacional ainda não está integrado nesta versão de avaliação.</p></x-portal.panel>
@endif
</x-portal.layout>
