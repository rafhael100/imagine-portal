<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
<title>Entrar — Imagine Portal</title>
<link rel="stylesheet" href="/_development/auth/ui.css">
<script src="/_development/auth/ui.js" defer></script>
</head>
<body>
<main class="auth-layout" id="auth" data-organization="{{ $organization }}">
<aside class="auth-brand" aria-label="Imagine">
<div class="logo-box"><img class="logo" src="/_development/auth/logo.png" alt="Imagine Comunicação Digital" width="190"></div>
<div><p class="eyebrow">IMAGINE PORTAL</p><h1>Mais perto.<br><span>Mais simples.</span></h1><p>Um espaço para acompanhar sua jornada com a Imagine.</p></div>
<p class="auth-region">TECNOLOGIA QUE APROXIMA</p>
</aside>
<section class="auth-form" aria-labelledby="title">
<p class="eyebrow">BEM-VINDO À IMAGINE</p><h2 id="title">Seu próximo passo.</h2>
<p class="muted" id="description">Entre na sua conta para continuar.</p>
<div id="status" role="status" aria-live="polite" tabindex="-1" class="notice" @if($ready) hidden @endif>
@if(!$ready)Seu acesso está em preparação. A equipe Imagine concluirá o cadastro inicial antes de liberar a entrada.@endif
</div>
<form id="password-form" @if(!$ready) hidden @endif>
<label class="field"><span>E-mail</span><input id="email" type="email" autocomplete="username" maxlength="254" required></label>
<label class="field"><span>Senha</span><input id="password" type="password" autocomplete="current-password" maxlength="1024" required></label>
<button class="btn orange full" type="submit">Continuar <span aria-hidden="true">→</span></button>
</form>
<form id="mfa-form" hidden>
<label class="field"><span>Código do aplicativo autenticador</span><input id="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required aria-describedby="mfa-help"></label>
<p class="fine" id="mfa-help">Digite os seis números exibidos no seu aplicativo.</p>
<button class="btn orange full" type="submit">Confirmar acesso</button>
<button class="btn secondary full" type="button" id="back">Voltar</button>
</form>
<section id="authenticated" hidden aria-labelledby="success-title">
<h3 id="success-title" tabindex="-1">Acesso confirmado.</h3><p class="muted">Sua identidade foi confirmada. As páginas da sua conta serão disponibilizadas após a conclusão da configuração.</p>
<button class="btn secondary full" type="button" id="logout">Sair com segurança</button>
</section>
<noscript><p class="notice">Ative o JavaScript para entrar com segurança.</p></noscript>
<p class="fine footer">Ambiente de desenvolvimento · Imagine Comunicação Digital</p>
</section>
</main>
</body></html>
