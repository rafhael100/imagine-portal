@props(['current' => 'overview'])
@php($activeSection = \App\Presentation\ClientSection::tryFrom($current))
<aside class="pi-sidebar">
    <x-portal.brand />
    <nav aria-label="Seções ilustrativas do Portal">
        <ul>
            @foreach (\App\Presentation\ClientSection::cases() as $section)
                <li><span class="{{ $activeSection === $section ? 'pi-current' : '' }}" @if($activeSection === $section) aria-current="page" @endif>{{ $section->label() }}</span></li>
            @endforeach
        </ul>
    </nav>
    <p class="pi-sidebar-note">Navegação ilustrativa. As opções não abrem outras telas.</p>
</aside>
