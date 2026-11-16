@extends('portal-admin.layout')

@section('title', 'Acesso administrativo')

@section('content')
    <h1>Portal Administrativo</h1>
    <p>Entre com suas credenciais exclusivas da Imagine. O acesso exige um segundo fator de autenticação.</p>
    @if ($errors->any())
        <div class="error" role="alert">Não foi possível autenticar. Verifique os dados e tente novamente.</div>
    @endif
    <form method="POST" action="{{ route('portal-admin.login.submit') }}" autocomplete="on">
        @csrf
        <label for="email">E-mail</label>
        <input id="email" type="email" name="email" maxlength="190"
            autocomplete="username" required autofocus>
        <label for="password">Senha</label>
        <input id="password" type="password" name="password" maxlength="512"
            autocomplete="current-password" required>
        <button type="submit">Continuar com segurança</button>
    </form>
    <p class="note">Não é possível criar contas ou recuperar credenciais publicamente nesta etapa. Solicite acesso ao responsável autorizado.</p>
@endsection
