@props(['title' => 'Prévia navegável Imagine'])
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; script-src 'none'; img-src 'none'; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'">
    <title>{{ $title }} · Imagine Portal — Prévia fictícia</title>
    <x-portal.preview.styles />
</head>
<body>
    <a href="#pv-content" class="pv-skip">Pular para o conteúdo</a>
    <div class="pv-layout">
        <aside class="pv-sidebar">
            <div class="pv-brand"><span class="pv-brand-mark" aria-hidden="true">✦</span>Imagine Portal</div>
            <p class="pv-nav-label">Áreas ilustrativas</p>
            <nav aria-label="Navegação entre seções da prévia" aria-describedby="pv-nav-instructions" class="pv-nav">
                <x-portal.preview.nav-item anchor="inicio" label="Visão geral" />
                <x-portal.preview.nav-item anchor="servicos" label="Meus serviços" />
                <x-portal.preview.nav-item anchor="performance" label="Performance AI" />
                <x-portal.preview.nav-item anchor="solicitacoes" label="Solicitações" />
                <x-portal.preview.nav-item anchor="universidade" label="Universidade" />
                <x-portal.preview.nav-item anchor="financeiro" label="Financeiro" />
            </nav>
            <p class="pv-nav-help" id="pv-nav-instructions">No celular, deslize o menu para os lados para encontrar as seis áreas. Os links levam apenas a seções desta prévia.</p>
            <p class="pv-sidebar-note">Protótipo interno. Não existe sessão, acesso de cliente, contratação, cobrança ou conexão real.</p>
        </aside>
        <div class="pv-main">
            <header class="pv-topbar">
                <div class="pv-topbar-title">
                    <span class="pv-topbar-brand">Imagine Comunicação Digital</span>
                    <h1 class="pv-document-title">Espaço do cliente · prévia</h1>
                </div>
                <small>Empresa Aurora · fictícia</small>
            </header>
            <main id="pv-content" class="pv-container" tabindex="-1">
                <div class="pv-demo-warning" role="note">PRÉVIA — DADOS 100% FICTÍCIOS. Esta interface Blade não oferece login, formulários, serviços ativos, anúncios, cobranças ou acesso a clientes.</div>
                {{ $slot }}
            </main>
            <footer class="pv-footer">Imagine Portal · Prévia Blade sem rota pública, autenticação, backend, armazenamento ou integrações.</footer>
        </div>
    </div>
</body>
</html>
