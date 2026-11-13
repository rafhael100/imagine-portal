@php($finance=($section??'services')==='finance')
<x-portal.layout :title="$finance?'Financeiro de teste':'Meus serviços'" :current="$finance?'finance':'services'">
    <x-portal.panel title="Pedidos da empresa fictícia">
        <p>Estes pedidos foram salvos no PostgreSQL do ambiente privado de avaliação. Pagamentos e contratações são simulados; nenhum serviço foi ativado.</p>
        @if($finance)<p>Não existem faturas, cobranças nem recibos reais aqui. Os resultados abaixo são apenas estados de pagamento de teste.</p>@endif
        <div class="preview-table">
            <table>
                <thead><tr><th scope="col">Referência</th><th scope="col">Serviço solicitado</th><th scope="col">Pagamento fictício</th><th scope="col">Briefing</th></tr></thead>
                <tbody>
                    @forelse($orders as $order)
                        <tr data-preview-order-row>
                            <td>{{ $order['id'] }}</td>
                            <td>{{ ['onepage'=>'Onepage','landing-pages'=>'Landing Page','performance'=>'Performance AI'][$order['product']] ?? 'Serviço indisponível' }}</td>
                            <td>{{ ['approved'=>'Aprovado no teste','pending'=>'Pendente no teste','declined'=>'Recusado no teste'][$order['payment']] ?? 'Indisponível' }}</td>
                            <td>{{ in_array($order['briefing_saved'],[true,'t',1,'1'],true)?'Recebido no teste':'Não enviado' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Nenhum pedido fictício salvo até agora. Utilize a contratação de teste para criar um.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p>Esta lista é apenas leitura e limitada à empresa sintética do banco. Não representa contrato, direito de acesso, execução de serviço, cobrança real ou identidade de cliente.</p>
        <a class="preview-link" href="/_rehearsal/purchase">Criar outro pedido de teste</a>
    </x-portal.panel>
</x-portal.layout>
