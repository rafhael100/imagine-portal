@props(['name', 'description', 'status' => 'Somente exemplo visual'])
<article class="pv-card">
    <h2>{{ $name }}</h2>
    <p>{{ $description }}</p>
    <span class="pv-service-status">{{ $status }}</span>
</article>
