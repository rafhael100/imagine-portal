@props(['current' => 'overview'])
<aside class="pi-sidebar">
    <x-portal.brand />
    <nav aria-label="Seções ilustrativas do Portal">
        <ul>
            @foreach (['overview' => 'Visão geral', 'services' => 'Meus serviços Imagine', 'available' => 'Serviços disponíveis', 'contracts' => 'Contratos e assinaturas', 'modules' => 'Módulos Imagine'] as $key => $label)
                <li><span class="{{ $current === $key ? 'pi-current' : '' }}" @if($current === $key) aria-current="page" @endif>{{ $label }}</span></li>
            @endforeach
        </ul>
    </nav>
    <p class="pi-sidebar-note">Navegação ilustrativa. As opções não abrem outras telas.</p>
</aside>
