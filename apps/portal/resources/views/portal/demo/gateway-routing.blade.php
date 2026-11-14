<x-portal.layout current="contracts" title="Gateways de pagamento — prévia interna">
    @php
        // Fixtures comerciais inventadas exclusivamente para revisão visual.
        // Nunca usar dados desta tela para autorizar checkout ou selecionar PSP.
        $fixture = [
            'mode' => 'manual',
            'currency' => 'BRL',
            'preferred_gateway' => 'asaas',
            'providers' => [
                'asaas' => [
                    'enabled' => true, 'approved' => true, 'healthy' => true,
                    'fee_version' => 'DEMO-AS-01',
                    'methods' => [
                        'pix' => ['enabled' => true, 'fixed_cents' => 100, 'variable_bps' => 100, 'settlement_days' => 1],
                        'boleto' => ['enabled' => true, 'fixed_cents' => 240, 'variable_bps' => 0, 'settlement_days' => 2],
                    ],
                ],
                'c6' => [
                    'enabled' => true, 'approved' => true, 'healthy' => true,
                    'fee_version' => 'DEMO-C6-01',
                    'methods' => [
                        'pix' => ['enabled' => true, 'fixed_cents' => 30, 'variable_bps' => 60, 'settlement_days' => 1],
                    ],
                ],
            ],
        ];
        $manual = \App\Domain\PaymentRouting\GatewayRoutingPreview::quote($fixture, 'pix', 10000);
        $automatic = \App\Domain\PaymentRouting\GatewayRoutingPreview::quote(array_replace($fixture, ['mode' => 'lowest_cost']), 'pix', 10000);
        $money = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
        $rows = [
            ['Manual (Asaas)', 'Pix', 'Asaas', $money($manual['estimated_fee_cents']), $money($manual['estimated_net_cents']), 'DEMO-AS-01'],
            ['Menor custo elegível', 'Pix', 'C6 (fictício)', $money($automatic['estimated_fee_cents']), $money($automatic['estimated_net_cents']), 'DEMO-C6-01'],
        ];
    @endphp
    <x-portal.message title="Somente prévia fictícia — sem cobranças" message="As tarifas, aprovações, disponibilidade e situação do C6 nesta página são 100% inventadas. Nenhuma configuração de gateway foi ativada, nenhuma cotação foi enviada a bancos e nenhum pagamento será criado." tone="warning" />
    <div class="pi-grid pi-grid-two">
        <x-portal.service-card name="Asaas" description="Gateway padrão previsto. Qualquer cobrança existente permanece vinculada ao provedor original." status="Padrão proposto" tone="info" />
        <x-portal.service-card name="C6 Bank" description="Segundo gateway candidato, dependente de contrato, credenciais, homologação e aprovação financeira para cada modalidade." status="Não ativado" tone="warning" />
    </div>
    <x-portal.panel title="Comparação ilustrativa — Pix de R$ 100,00">
        <p class="pi-muted">Exemplo com tarifas sintéticas. Modo manual respeita a preferência definida; modo automático avalia somente modalidades e provedores elegíveis. O resultado não representa taxas contratadas.</p>
        <x-portal.table caption="Comparação fictícia de gateways"
            :columns="['Política', 'Modalidade', 'Resultado', 'Tarifa fictícia', 'Líquido fictício', 'Versão simulada']"
            :rows="$rows" />
    </x-portal.panel>
    <x-portal.panel title="Condições para disponibilização">
        <x-portal.list label="Proteções obrigatórias"
            :items="[
                'Somente administrador autenticado pode aprovar políticas e tarifas por empresa.',
                'Taxas, método, gateway, versão da política e idempotência são congelados antes de qualquer cobrança.',
                'Cobranças existentes, estornos e assinaturas nunca migram automaticamente.',
                'Resultado incerto exige reconciliação no gateway original antes de qualquer nova tentativa.',
                'C6 permanece bloqueado até homologação contratual, técnica e financeira.'
            ]" />
        <x-portal.unavailable feature="A edição de gateways, publicação das taxas e a criação de pagamentos" />
    </x-portal.panel>
</x-portal.layout>
