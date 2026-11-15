@extends('portal-admin.layout')

@section('title', 'Revisão SEO por organização')

@section('content')
    <h1>Revisão SEO · Acesso restrito</h1>
    <p>A sessão administrativa e a permissão <strong>seo.view</strong> foram verificadas no servidor para este escopo.</p>
    <div class="note" role="note">
        <strong>Dados indisponíveis nesta etapa</strong>
        <p>O vínculo com serviços contratados, a propriedade do Search Console e os relatórios assinados do SEO Engine ainda exigem verificação independente.</p>
        <p>Não foram carregados dados de clientes, páginas públicas, métricas de visitas, leads ou vendas.</p>
    </div>
    <form method="POST" action="{{ route('portal-admin.logout') }}">
        @csrf
        <button class="secondary" type="submit">Encerrar sessão</button>
    </form>
@endsection
