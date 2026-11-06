@props(['state' => 'example'])
@php($status = \App\Presentation\JourneyState::tryFrom($state) ?? \App\Presentation\JourneyState::Example)
<x-portal.badge :label="$status->label()" :tone="$status->tone()" />
