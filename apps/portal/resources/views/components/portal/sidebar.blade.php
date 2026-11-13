@props(['current' => 'overview'])
@php($activeSection = \App\Presentation\ClientSection::tryFrom($current))
<aside class="pi-sidebar">
    <x-portal.brand />
    <p class="pi-nav-title">Seu espaço Imagine</p>
    <nav aria-label="Seções ilustrativas do Portal">
        <ul>
            @foreach (\App\Presentation\ClientSection::cases() as $section)
                <li><span data-preview-section="{{ $section->value }}" class="{{ $activeSection === $section ? 'pi-current' : '' }}" @if($activeSection === $section) aria-current="page" @endif>{{ $section->label() }}</span></li>
            @endforeach
        </ul>
    </nav>
    <p class="pi-sidebar-note">Navegação ilustrativa. As opções não abrem outras telas.</p>
</aside>
