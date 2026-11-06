@props(['label', 'tone' => 'neutral'])
@php($palette = in_array($tone, ['neutral', 'info', 'success', 'warning', 'error'], true) ? $tone : 'neutral')
<span class="pi-badge pi-tone-{{ $palette }}">{{ $label }}</span>
