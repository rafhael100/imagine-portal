{{-- PVIEW-02: not routed, does not authenticate or fetch data. HTML is demo only. --}}
<x-portal.pview-shell>
    <p class="pi-preview-muted">Bem-vindo ao ecossistema Imagine · Demonstração visual sem contas reais.</p>
    <h1 class="pi-preview-head">Seu Portal Imagine</h1>
    <p class="pi-preview-muted">Navegue pelos oito conceitos de serviço usando os links na lateral. Os exemplos não constituem ofertas ou contratos.</p>
    <div class="pi-preview-grid" aria-label="Resumo de funcionalidades demonstrativas">
        <article class="pi-preview-card"><h3>Sites Imagine</h3><p>Onepage e Landing Page como serviços independentes, com jornadas próprias.</p></article>
        <article class="pi-preview-card"><h3>CRM Imagine</h3><p>Plataforma independente, identidade e login próprios, sem sessão compartilhada com o Portal.</p></article>
        <article class="pi-preview-card"><h3>Performance AI</h3><p>Campanhas Google Ads + Meta, com operação em motor separado, sem campanhas ativas nesta prévia.</p></article>
        <article class="pi-preview-card"><h3>Universidade Imagine</h3><p>Benefício exclusivo para clientes elegíveis com serviços ativos; sem matrícula ou acesso concedidos.</p></article>
    </div>
    @foreach (\App\Presentation\PviewSection::cases() as $section)
        <section class="pi-preview-panel" id="pview-{{ $section->value }}" tabindex="-1" aria-labelledby="h-pview-{{ $section->value }}">
            <h2 class="pi-preview-subhead" id="h-pview-{{ $section->value }}">{{ $section->label() }}</h2>
            <p class="pi-preview-muted">{{ $section->description() }}</p>
            <span class="pi-preview-tag">Visualização fictícia · sem operação</span>
        </section>
    @endforeach
</x-portal.pview-shell>
