@props(['title' => null, 'current' => 'overview'])
@php($title = $title ?? \App\Presentation\ClientSection::tryFrom($current)?->label() ?? 'Portal Imagine')
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; font-src 'none'; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'">
    <title>{{ $title }} · Portal Imagine</title>
    @include('components.portal.styles')
</head>
<body>
    <a class="pi-skip" href="#pi-content">Pular para o conteúdo</a>
    <div class="pi-layout">
        <x-portal.sidebar :current="$current" />
        <main id="pi-content" class="pi-main" tabindex="-1">
            <x-portal.header :title="$title" />
            <x-portal.message title="Ambiente demonstrativo interno" message="Informações exclusivamente fictícias. Sem contratação, pagamento, autenticação ou conexão com outros serviços." />
            <div class="pi-content">{{ $slot }}</div>
            <footer>Portal Imagine · Protótipo interno · sem publicação para clientes</footer>
        </main>
    </div>
</body>
</html>
