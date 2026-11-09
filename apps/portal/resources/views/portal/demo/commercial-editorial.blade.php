@php($review = $review ?? \App\Presentation\CommercialCatalogReview::fictionalExample())
<x-portal.layout title="Publicação do site comercial · DEMONSTRAÇÃO" current="commercial">
    <x-portal.panel title="Edição e revisão do catálogo">
        <p class="pi-eyebrow">Prévia interna do Backoffice comercial</p>
        <p>Compare versões antes de solicitar uma publicação no site Imagine. Todos os registros desta tela são fictícios e nenhuma ação é executada.</p>
        <p class="pi-muted">Versão anterior: {{ $review['oldReleaseId'] }} · Candidata: {{ $review['candidateReleaseId'] }}</p>
        <x-portal.badge tone="warning" label="Sem conexão com clientes, CMS real ou publicação" />
    </x-portal.panel>

    <div class="pi-grid" aria-label="Resumo da revisão fictícia">
        <x-portal.indicator label="Páginas adicionadas" :value="$review['counts']['added']" />
        <x-portal.indicator label="Páginas atualizadas" :value="$review['counts']['updated']" />
        <x-portal.indicator label="Pendências de revisão" :value="count($review['flags'])" />
    </div>

    <x-portal.panel title="Diferenças entre versões">
        <p class="pi-muted">Cada alteração é verificada por serviço, versão e endereço público. Preços, dados pessoais e contratos não fazem parte deste catálogo.</p>
        <div class="pi-table-wrap">
            <table aria-label="Tabela ilustrativa de comparação do catálogo comercial">
                <thead>
                    <tr><th scope="col">Serviço</th><th scope="col">URL planejada</th><th scope="col">Versão</th><th scope="col">Alteração</th><th scope="col">Revisão</th></tr>
                </thead>
                <tbody>
                    @foreach($review['rows'] as $item)
                        <tr>
                            <td><strong>{{ $item['title'] }}</strong><p class="pi-muted">{{ $item['description'] }}</p></td>
                            <td><code>{{ $item['path'] }}</code></td>
                            <td>{{ $item['version'] }}</td>
                            <td>{{ match($item['change']) {'added' => 'Nova', 'updated' => 'Atualizada', 'unchanged' => 'Sem mudanças', 'removed' => 'Removida', default => 'Bloqueada'} }}</td>
                            <td>
                                @if($item['flags'] !== [])
                                    @foreach($item['flags'] as $flag)
                                        <p><x-portal.badge tone="warning" :label="$flag" /></p>
                                    @endforeach
                                @else
                                    <x-portal.badge tone="neutral" label="Revisão humana necessária" />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-portal.panel>
    <x-portal.panel title="Próximas etapas (não operacionais)">
        <ol class="pi-journey">
            <li><strong>Rascunho editorial</strong><p>Revisão de conteúdo, links, serviços e ofertas por responsável identificado.</p></li>
            <li><strong>Aprovação independente</strong><p>Conferência do escopo e da versão, com autenticação MFA e auditoria, ainda não conectadas.</p></li>
            <li><strong>Publicação controlada</strong><p>Somente após backend transacional, assinatura, rollback e homologação em staging.</p></li>
        </ol>
        <p class="pi-muted"><strong>Não existe botão ativo de aprovação, salvamento ou publicação.</strong> Esta tela não está registrada nas rotas HTTP do Portal.</p>
    </x-portal.panel>
</x-portal.layout>
