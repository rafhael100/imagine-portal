@extends('performance-admin.private-layout')
@section('title', 'Imagine · Empresas Performance')
@section('content')
<h1>Escolha sua empresa.</h1>
<p class="muted">Você verá as consultas autorizadas para cada empresa. A situação contratual e cada concessão serão verificadas novamente ao abrir uma consulta.</p>
<ul class="companies">
@forelse($companies as $company)
<li><a href="{{ route('performance-private.show',['company'=>$company]) }}">Abrir empresa {{ $company }}</a></li>
@empty
<li>Nenhuma empresa disponível para consulta.</li>
@endforelse
</ul>
@endsection
