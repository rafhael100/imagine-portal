<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
<title>Minha Onepage — Imagine Portal</title>
<link rel="stylesheet" href="/_development/commerce/onepage/client.css">
<script src="/_development/commerce/onepage/client.js" defer></script>
</head>
<body>
<div class="shell" id="onepage-client">
<header class="header">
  <div class="logo-background"><img src="/_development/auth/logo.png" width="156" alt="Imagine Comunicação Digital"></div>
  <div><span class="eyebrow">PORTAL DO CLIENTE</span><h1>Minha Onepage</h1></div>
  <a class="header-link" href="/_development/auth/login">Minha conta</a>
</header>
<main class="content">
  <div class="intro">
    <p class="eyebrow">PEDIDO E ACOMPANHAMENTO</p>
    <h2>Seu projeto começa aqui.</h2>
    <p>Revise as condições e acompanhe o registro da solicitação com a Imagine.</p>
  </div>
  <aside class="warning" aria-label="Ambiente de homologação">
    <strong>Ambiente privado de homologação.</strong> Esta tela não gera cobranças,
    não confirma pagamentos, não libera briefing e não publica sites.
  </aside>
  <p id="notice" role="status" aria-live="polite" tabindex="-1" class="notice" hidden></p>
  <section id="loading" class="panel" aria-live="polite">
    <h3>Conferindo sua sessão…</h3>
    <p class="muted">Seus dados serão carregados apenas após a validação do acesso.</p>
  </section>
  <section id="login-required" class="panel" hidden aria-labelledby="login-title">
    <p class="step">ACESSO</p>
    <h3 id="login-title">Entre para continuar.</h3>
    <p>É necessário concluir o login e a confirmação de segurança para acessar seus pedidos.</p>
    <a class="btn primary" href="/_development/auth/login">Entrar na minha conta</a>
  </section>
  <section id="selection" class="panel" hidden aria-labelledby="selection-title">
    <p class="step">01 / SELEÇÃO</p>
    <h3 id="selection-title">Solicitar uma Onepage</h3>
    <p class="muted">Os valores vêm do catálogo da Imagine, não do navegador.</p>
    <label for="tier">Simulação de pacote</label>
    <select id="tier">
      <option value="recommended">Recomendado</option>
      <option value="minimum">Mínimo</option>
    </select>
    <button id="start" class="btn primary" type="button">Ver minha cotação</button>
  </section>
  <section id="draft" class="panel" hidden aria-labelledby="draft-title">
    <p class="step">02 / COTAÇÃO</p>
    <h3 id="draft-title">Resumo do serviço</h3>
    <dl class="summary">
      <div><dt>Implantação</dt><dd id="setup-total">—</dd></div>
      <div><dt>Mensalidade indicada</dt><dd id="monthly">—</dd></div>
      <div><dt>Divisão ilustrativa da implantação</dt><dd id="parts">—</dd></div>
    </dl>
    <p class="fine">A divisão exibida é apenas informativa. Nenhuma parcela ou vencimento é emitido por esta tela.</p>
    <div class="actions">
      <button id="review-button" class="btn primary" type="button">Conferir contrato e condições</button>
      <button id="restart" class="btn secondary" type="button">Nova cotação</button>
    </div>
  </section>
  <section id="review" class="panel" hidden aria-labelledby="review-title">
    <p class="step">03 / REVISÃO</p>
    <h3 id="review-title">Revise antes de aceitar</h3>
    <p>Leia o contrato e as condições completas da versão apresentada pela Imagine.</p>
    <h4>Contrato</h4>
    <pre id="contract-text" class="contract"></pre>
    <h4>Condições estruturadas</h4>
    <pre id="terms-text" class="contract"></pre>
    <label class="check"><input id="accept-check" type="checkbox">
      <span>Li e aceito exatamente a versão do contrato e das condições exibida acima.</span>
    </label>
    <div class="actions">
      <button id="accept-button" class="btn primary" type="button" disabled>Registrar meu aceite</button>
      <button id="back" class="btn secondary" type="button">Voltar à cotação</button>
    </div>
    <p class="fine">O registro de aceite não representa confirmação de pagamento nem autorização de produção.</p>
  </section>
  <section id="order" class="panel" hidden aria-labelledby="order-title">
    <p class="step">04 / SOLICITAÇÃO</p>
    <h3 id="order-title">Pedido registrado</h3>
    <p>Seu aceite foi registrado. O pedido está aguardando as etapas financeiras e operacionais.</p>
    <dl class="summary">
      <div><dt>Pedido</dt><dd id="order-id" class="mono">—</dd></div>
      <div><dt>Data do aceite</dt><dd id="accepted-at">—</dd></div>
      <div><dt>Situação financeira</dt><dd>Aguardando confirmação oficial</dd></div>
      <div><dt>Projeto no Sites</dt><dd>Sem recibo operacional disponível</dd></div>
      <div><dt>Briefing e publicação</dt><dd>Ainda não liberados</dd></div>
    </dl>
    <p class="fine">Quando a primeira parcela for oficialmente confirmada, o fluxo de briefing poderá ser liberado. A produção e a publicação exigem validações separadas.</p>
  </section>
  <noscript><p class="notice">Ative o JavaScript para consultar o pedido com segurança.</p></noscript>
</main>
<footer class="footer">Imagine Comunicação Digital · Ambiente restrito de desenvolvimento</footer>
</div>
</body>
</html>
