@extends('performance-admin.private-layout')
@section('title', 'Imagine · Performance · Confirmação de acesso')
@section('content')
<h1>Confirme seu acesso novamente.</h1>
<section aria-labelledby="renewal-title">
<h2 id="renewal-title">Verificação de segurança vencida</h2>
<p>Para continuar as consultas do Performance, confirme novamente sua senha e o código do aplicativo autenticador.</p>
<p>Ao continuar, sua sessão atual será encerrada. Depois de entrar, abra o Performance no Portal e selecione a empresa desejada.</p>
<form method="post" action="{{ route('portal-admin.logout') }}">
@csrf
<button type="submit">Entrar novamente</button>
</form>
</section>
@endsection
