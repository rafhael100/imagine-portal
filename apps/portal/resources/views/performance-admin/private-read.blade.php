@extends('performance-admin.private-layout')
@section('title', 'Imagine · Performance · '.$modules[$module]['label'])
@section('content')
<p><a href="{{ route('performance-private.index') }}">← Escolher outra empresa</a></p>
<h1>{{ $modules[$module]['heading'] }}</h1>
<nav class="modules" aria-label="Consultas Performance">
@foreach($modules as $key=>$item)
<a href="{{ route('performance-private.show', ['company'=>$company,'module'=>$key]+($validationErrors===[]?array_filter($filters,static fn($value)=>$value!==''):[])) }}" @if($key===$module) aria-current="page" @endif>{{ $item['label'] }}</a>
@endforeach
</nav>
<section aria-labelledby="filters-title">
<h2 id="filters-title">Filtros da consulta</h2>
@if($validationErrors!==[])
<div class="notice" role="alert"><strong>Revise os filtros.</strong><ul>@foreach($validationErrors as $error)<li>{{ $error }}</li>@endforeach</ul><p>A consulta não foi enviada. Corrija os campos e tente novamente.</p></div>
@endif
<form class="filters" method="get" action="{{ route('performance-private.show',['company'=>$company,'module'=>$module]) }}">
<div class="field"><label for="period">Período solicitado</label><input id="period" name="period" type="month" value="{{ $filters['period'] }}" aria-describedby="filter-help"></div>
<div class="field"><label for="page">Página solicitada</label><input id="page" name="page" type="number" min="1" max="10000" step="1" value="{{ $filters['page'] }}" aria-describedby="filter-help"></div>
<div class="filter-actions"><button type="submit">Consultar</button><a href="{{ route('performance-private.show',['company'=>$company,'module'=>$module]) }}">Limpar filtros</a></div>
</form>
<p id="filter-help" class="muted">O período é opcional. A página deve ser um número inteiro entre 1 e 10000. Esses filtros identificam o que você solicitou; a fonte ainda precisa informar os dados e a quantidade de páginas.</p>
</section>
@if($result!==null)
<section aria-labelledby="source">
<h2 id="source">Estado da consulta</h2>
<dl>
<dt>Empresa</dt><dd>{{ $company }}</dd>
<dt>Consulta</dt><dd>{{ $modules[$module]['label'] }}</dd>
<dt>Fonte</dt><dd>Performance privado · integração candidata</dd>
<dt>Estado</dt><dd>{{ $result['status']==='unavailable'?'Dados indisponíveis':'Dados sintéticos de laboratório' }}</dd>
<dt>Período solicitado</dt><dd>{{ $filters['period']!==''?$filters['period']:'Não informado' }}</dd>
<dt>Página solicitada</dt><dd>{{ $filters['page'] }}</dd>
<dt>Período dos dados</dt><dd>Não informado pela fonte</dd>
<dt>Indicadores oficiais</dt><dd>Indisponíveis — fontes Google e Meta não conectadas</dd>
<dt>Correlação</dt><dd>{{ $correlation }}</dd>
</dl>
@if($result['status']==='unavailable')
<p>A consulta foi autorizada, mas a fonte ainda não disponibilizou informações para esta operação. Esse estado não informa que os resultados sejam zero ou que não existam registros.</p>
@endif
<p>Nenhuma cobrança, publicação ou alteração em campanhas está habilitada.</p>
</section>
@endif
@endsection
