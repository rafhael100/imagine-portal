@if($module==='clients'||($filters['context']??'')==='client')
@php
$back=['sort'=>$module==='clients'?($filters['sort']??'name'):($filters['client_sort']??'name'),'q'=>$module==='clients'?($filters['q']??null):($filters['client_q']??null),'status'=>$module==='clients'?($filters['status']??null):($filters['client_status']??null),'page'=>$module==='clients'?($filters['page']??1):($filters['client_page']??1)];
$clientNav=fn($tab)=>$link(in_array($tab,['overview','history'],true)?'clients':$tab,
    in_array($tab,['overview','history'],true)?array_merge($back,['tab'=>$tab]):['context'=>'client','client_q'=>$back['q'],'client_status'=>$back['status'],'client_page'=>$back['page'],'client_sort'=>$back['sort']]);
@endphp
<section class="card detail" aria-label="Contexto do cliente"><strong>{{ $state['name'] }}</strong><p><a class="rowlink" href="{{ $link('clients',$back) }}">← Voltar aos clientes com os filtros anteriores</a></p><nav class="tabs" aria-label="Detalhes do cliente">
@foreach(['overview'=>'Visão geral','campaigns'=>'Campanhas','channels'=>'Canais','agents'=>'IA','finance'=>'Financeiro','approvals'=>'Aprovações','reports'=>'Relatórios','history'=>'Histórico'] as $tabKey=>$tabLabel)
@if($tabKey!=='finance'||$can('finance'))
<a href="{{ $clientNav($tabKey) }}" @if(($module==='clients'?($filters['tab']??'overview'):$module)===$tabKey) aria-current="page" @endif>{{ $tabLabel }}</a>
@endif
@endforeach
</nav></section>
@endif
