@extends('portal-admin.layout')

@section('title', 'Área administrativa autenticada')

@section('content')
    <h1>Administração autenticada</h1>
    <p>Sua senha e o segundo fator foram verificados. A infraestrutura de acesso administrativo está em fase de homologação.</p>
    <div class="note" role="note">
        <strong>Sem autorização de clientes</strong>
        <p>Este acesso não concede permissões sobre empresas, serviços contratados, SEO Engine, CRM, Google Search Console ou dados de clientes.</p>
    </div>
    @if(\App\Domain\PerformanceAdministration\PrivateDemonstrationPolicy::allowed())
    <p><a href="{{ route('performance-admin.index') }}">Abrir Central Administrativa Performance AI · Laboratório →</a></p>
    @endif
    @if(\App\Domain\AutomationAdministration\AutomationPolicyCatalog::available())
    <p><a href="{{ route('automation-admin.index') }}">Abrir Central de Automações · Políticas candidatas →</a></p>
    @endif
    <form method="POST" action="{{ route('portal-admin.logout') }}">
        @csrf
        <button class="secondary" type="submit">Encerrar sessão</button>
    </form>
@endsection
