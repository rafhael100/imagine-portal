@props(['anchor', 'label'])
@php($target = in_array($anchor, ['inicio', 'servicos', 'performance', 'solicitacoes', 'universidade', 'financeiro'], true) ? $anchor : 'inicio')
<a class="pv-nav-link" href="#pv-{{ $target }}">{{ $label }}</a>
