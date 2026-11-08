@props(['label', 'value', 'detail' => 'Valor exclusivamente fictício'])
<article class="pv-card">
    <h2 class="pv-metric-label">{{ $label }}</h2>
    <div class="pv-metric-number">{{ $value }}</div>
    <p>{{ $detail }}</p>
</article>
