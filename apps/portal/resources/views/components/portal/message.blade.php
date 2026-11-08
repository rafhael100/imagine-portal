@props(['title', 'message', 'tone' => 'info'])
@php($palette = in_array($tone, ['info', 'error', 'warning'], true) ? $tone : 'info')
<div class="pi-message pi-tone-{{ $palette }}" role="{{ $palette === 'error' ? 'alert' : 'note' }}">
    <strong>{{ $title }}</strong><p>{{ $message }}</p>
</div>
