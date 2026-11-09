{{-- PVIEW-PERF-04: purely synthetic presentation inside the OFFLINE Portal demo.
     No server connection, auth, SSO, Google/Meta API, billing or actual client data. --}}
@php
    // Static offline fixture only. No HTTP, DB, cookies or client sessions.
    $performanceSnapshot = \App\Presentation\Preview\PerformanceDemoSnapshot::loadStatic('org-demo-aurora');
@endphp
<div data-pview-perf="offline-demo" aria-label="Painel ilustrativo de Performance AI">
@if ($performanceSnapshot === null)
    <section class="pi-soft" role="alert">
        <h2>Dados fictícios indisponíveis</h2>
        <p>Contrato demonstrativo incompatível ou inválido. Nenhum dado real foi consultado; a visualização foi bloqueada por segurança.</p>
    </section>
@else
    <div class="pi-grid four" aria-label="Indicadores ilustrativos, não reais">
        <article class="pi-card">
            <div class="pi-metric-label">Campanhas sob gestão (exemplo)</div>
            <div class="pi-metric-value">{{ str_pad((string) $performanceSnapshot['metrics']['campaigns_managed'], 2, '0', STR_PAD_LEFT) }}</div>
            <div class="pi-metric-note">Estados demonstrativos, sem publicação de anúncios</div>
        </article>
        <article class="pi-card">
            <div class="pi-metric-label">Cliques de demonstração</div>
            <div class="pi-metric-value">{{ $performanceSnapshot['metrics']['clicks'] }}</div>
            <div class="pi-metric-note">Número ilustrativo, sem fonte publicitária</div>
        </article>
        <article class="pi-card">
            <div class="pi-metric-label">Aprovações em análise (exemplo)</div>
            <div class="pi-metric-value">{{ str_pad((string) $performanceSnapshot['metrics']['approvals_pending'], 2, '0', STR_PAD_LEFT) }}</div>
            <div class="pi-metric-note">Nenhuma aprovação real emitida</div>
        </article>
        <article class="pi-card">
            <div class="pi-metric-label">Investimento em mídia</div>
            <div class="pi-metric-value">—</div>
            <div class="pi-metric-note">Sem gastos ou contas conectadas</div>
        </article>
    </div>

    <div class="pi-feature pi-section-gap">
        <section class="pi-card" aria-labelledby="pview-perf-channels">
            <div class="pi-rowhead">
                <h2 id="pview-perf-channels">Canais de divulgação</h2>
                <span class="pi-pill gray">Dados de exemplo</span>
            </div>
            <p>Os canais abaixo são apenas uma demonstração da gestão independente do Imagine Performance AI.</p>
            @foreach ($performanceSnapshot['campaigns'] as $campaign)
                <div class="pi-listitem">
                    <div class="pi-listcontent">
                        <span class="pi-symbol" aria-hidden="true">◎</span>
                        <div>
                            <b>{{ \App\Presentation\Preview\PerformanceDemoSnapshot::channelLabel($campaign['channel']) }}</b>
                            <small>{{ $campaign['title'] }} · registro fictício, sem acesso à conta</small>
                        </div>
                    </div>
                    <span class="pi-pill gray">{{ \App\Presentation\Preview\PerformanceDemoSnapshot::stateLabel($campaign['state']) }}</span>
                </div>
            @endforeach
        </section>

        <aside class="pi-soft" aria-labelledby="pview-perf-plan">
            <h2 id="pview-perf-plan">Plano e capacidade</h2>
            <p>Canal Único, Multicanal e Operação Ampliada são modalidades do produto. Preços e franquias quantitativas não estão homologados nesta prévia.</p>
            <div class="pi-section-gap">
                <span class="pi-pill gray">Plano: não contratado</span>
                <span class="pi-pill gray">Capacidade: indefinida</span>
            </div>
            <p style="margin-top:17px">Campanhas sob gestão, ativas ou pausadas, ocupam capacidade. Pausar não libera vaga. Trocas ordinárias não geram cobrança automática.</p>
        </aside>
    </div>

    <section class="pi-card pi-section-gap" aria-labelledby="pview-perf-campaigns">
        <div class="pi-rowhead">
            <h2 id="pview-perf-campaigns">Campanhas sob gestão — simulação</h2>
            <span class="pi-pill gray">Somente leitura</span>
        </div>
        <div class="pi-tablewrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Campanha fictícia</th>
                    <th scope="col">Canal</th>
                    <th scope="col">Estado na simulação</th>
                    <th scope="col">Capacidade</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($performanceSnapshot['campaigns'] as $campaign)
                    <tr>
                        <td>{{ $campaign['title'] }}</td>
                        <td>{{ \App\Presentation\Preview\PerformanceDemoSnapshot::channelLabel($campaign['channel']) }}</td>
                        <td>{{ \App\Presentation\Preview\PerformanceDemoSnapshot::stateLabel($campaign['state']) }}</td>
                        <td>Ocupa vaga ilustrativa</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="pi-feature pi-section-gap">
        <section class="pi-card" aria-labelledby="pview-perf-approvals">
            <div class="pi-rowhead">
                <h2 id="pview-perf-approvals">Central de aprovações ilustrativa</h2>
                <span class="pi-pill amber">{{ \App\Presentation\Preview\PerformanceDemoSnapshot::approvalLabel($performanceSnapshot['approvals'][0]['status']) }}</span>
            </div>
            <p>Recomendação fictícia: avaliar nova variação criativa de campanha. A decisão precisa de uma pessoa autorizada, versão imutável e verificação de alçada.</p>
            <div class="pi-listitem">
                <div class="pi-listcontent">
                    <span class="pi-symbol" aria-hidden="true">▤</span>
                    <div><b>Solicitação de aprovação — exemplo</b><small>Nenhum comando, anúncio ou verba é alterado nesta tela</small></div>
                </div>
                <span class="pi-pill gray">Sem execução</span>
            </div>
            <details style="margin-top:14px">
                <summary style="cursor:pointer;font-size:12px;font-weight:700;color:#3159c7">Entenda o fluxo de aprovação fictício</summary>
                <p style="margin-top:10px">Sem autoaprovação, sem aprovação por silêncio e sem execução em provedores externos. A versão da proposta e o responsável pela aprovação precisam ser verificados em implementação futura.</p>
            </details>
        </section>
        <section class="pi-card" aria-labelledby="pview-perf-reports">
            <div class="pi-rowhead">
                <h2 id="pview-perf-reports">Relatórios ilustrativos</h2>
                <span class="pi-pill gray">Sem dados de Ads</span>
            </div>
            <p>Números fictícios e sem atribuição de vendas, usados somente para explicar o formato do painel. Não há integração com Google Ads, Meta ou CRM.</p>
            <details style="margin-top:17px">
                <summary style="cursor:pointer;font-size:12px;font-weight:700;color:#3159c7">O que estará disponível na versão operacional?</summary>
                <p style="margin-top:10px">Quando cada integração for homologada: fontes e períodos identificados, campanhas sob gestão, indicadores de mídia e recomendações sujeitas à supervisão humana. Nenhuma entrega operacional é declarada por esta prévia.</p>
            </details>
        </section>
    </div>
    <div class="pi-soft pi-section-gap" role="note">
        <h2>Prévia offline, sem contratação ou conexão</h2>
        <p>Esta área representa visualmente os módulos F1-03, F1-04 e F1-05 em desenvolvimento no motor independente. Não existe API Portal ↔ Performance ativa, identidade real, anúncio publicado, cobrança, lead captado ou permissão de cliente.</p>
    </div>
@endif
</div>
