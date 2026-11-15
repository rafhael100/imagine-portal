@extends('portal-admin.layout')

@section('title', 'Verificação em duas etapas')

@section('content')
    <h1>Verificação de segurança</h1>
    <p>Informe o código de seis dígitos do aplicativo autenticador configurado previamente para esta conta.</p>
    @if ($errors->any())
        <div class="error" role="alert">Código inválido ou expirado.</div>
    @endif
    <form method="POST" action="{{ route('portal-admin.mfa.submit') }}">
        @csrf
        <label for="code">Código temporário</label>
        <input id="code" type="text" name="code" pattern="[0-9]{6}"
            inputmode="numeric" maxlength="6" minlength="6" required
            autocomplete="one-time-code" autofocus>
        <button type="submit">Verificar identidade</button>
    </form>
    <p class="note">O código é válido por aproximadamente 30 segundos. Cada etapa de tempo pode ser utilizada somente uma vez.</p>
@endsection
