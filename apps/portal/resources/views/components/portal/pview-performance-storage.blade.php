{{-- PVIEW-PERF-06: optional OFFLINE static contract generated from fictional PostgreSQL.
     No HTTP routes, network, customer identity, advertisements, or billing. --}}
@php
    $storageDemo = \App\Presentation\Preview\PerformanceStoragePreview::loadStatic('org-demo-a', 'perf-demo-a');
@endphp
@if ($storageDemo === null)
    <section class="pi-soft pi-section-gap" role="note">
        <h2>Registros demonstrativos indisponíveis</h2>
        <p>A amostra de banco de dados não passou na validação do contrato. Nenhuma informação externa foi consultada.</p>
    </section>
@else
    <section class="pi-card pi-section-gap" data-pview-perf-storage="synthetic-pg-offline" aria-labelledby="pview-perf-storage-title">
        <div class="pi-rowhead" style="flex-wrap:wrap;min-width:0">
            <h2 id="pview-perf-storage-title" style="min-width:0;overflow-wrap:anywhere">Campanhas e aprovações registradas — ambiente de demonstração</h2>
            <span class="pi-pill gray" style="max-width:100%;white-space:normal">Exemplo PostgreSQL · somente leitura</span>
        </div>
        <p>Dados fictícios produzidos por testes do motor Imagine Performance AI e copiados para uma amostra JSON estática. Não existe conexão em tempo real, anúncio publicado ou investimento em mídia.</p>

        <div class="pi-grid four" aria-label="Contagens fictícias do banco de teste">
            <article class="pi-card">
                <div class="pi-metric-label">Campanhas sob gestão (teste)</div>
                <div class="pi-metric-value">{{ $storageDemo['metrics']['managed_campaigns'] }}</div>
                <div class="pi-metric-note">Inclui campanhas pausadas</div>
            </article>
            <article class="pi-card">
                <div class="pi-metric-label">Campanhas pausadas (teste)</div>
                <div class="pi-metric-value">{{ $storageDemo['metrics']['paused_campaigns'] }}</div>
                <div class="pi-metric-note">Continuam gerenciadas</div>
            </article>
            <article class="pi-card">
                <div class="pi-metric-label">Aguardando aprovação</div>
                <div class="pi-metric-value">{{ $storageDemo['metrics']['approvals_pending'] }}</div>
                <div class="pi-metric-note">Sem aprovação por silêncio</div>
            </article>
            <article class="pi-card">
                <div class="pi-metric-label">Aprovações internas sem execução</div>
                <div class="pi-metric-value">{{ $storageDemo['metrics']['approvals_approved_internal'] }}</div>
                <div class="pi-metric-note">Nenhuma campanha publicada</div>
            </article>
        </div>

        <h3 style="margin-top:18px">Campanhas registradas no banco de teste</h3>
        <div class="pi-tablewrap" style="max-width:100%;min-width:0;overflow-x:auto">
            <table>
                <thead><tr>
                    <th scope="col">Campanha fictícia</th>
                    <th scope="col">Canal</th>
                    <th scope="col">Situação</th>
                    <th scope="col">Sob gestão</th>
                </tr></thead>
                <tbody>
                @foreach ($storageDemo['campaigns'] as $campaign)
                    <tr>
                        <td>{{ $campaign['title'] }}</td>
                        <td>{{ \App\Presentation\Preview\PerformanceStoragePreview::channelLabel($campaign['channel']) }}</td>
                        <td>{{ \App\Presentation\Preview\PerformanceStoragePreview::stateLabel($campaign['state']) }}</td>
                        <td>Sim (DEMO)</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <h3 style="margin-top:18px">Solicitações registradas no banco de teste</h3>
        <div class="pi-tablewrap" style="max-width:100%;min-width:0;overflow-x:auto">
            <table>
                <thead><tr>
                    <th scope="col">Solicitação fictícia</th>
                    <th scope="col">Alteração</th>
                    <th scope="col">Estado da revisão</th>
                </tr></thead>
                <tbody>
                @foreach ($storageDemo['approvals'] as $approval)
                    <tr>
                        <td>{{ $approval['ref'] }}</td>
                        <td>{{ \App\Presentation\Preview\PerformanceStoragePreview::operationLabel($approval['operation']) }}</td>
                        <td>{{ \App\Presentation\Preview\PerformanceStoragePreview::approvalLabel($approval['status']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="pi-soft pi-section-gap" role="note">
            <p><b>Importante:</b> aprovação interna não significa autorização para anúncios. Campanhas pausadas continuam sob gestão; preços e franquias não estão homologados nesta prévia. Sem cliques ou investimento real.</p>
        </div>
    </section>
@endif
