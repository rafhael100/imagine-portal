# PORTAL-F5 — HTTP real em loopback: CSRF, cookie e sessão

**Data:** 08/11/2026. **Escopo:** continuação autônoma autorizada do Imagine Portal, testando o transporte HTTP **fora da suíte PHPUnit**. Nenhuma rota de clientes, login operacional, banco de produção ou porta externa será aberta.

## 1. Por que executar HTTP real

Os testes HTTP de Laravel dentro do PHPUnit sob `APP_ENV=testing` não são prova da verificação de CSRF contra navegador. F5 executa o kernel Laravel **com `APP_ENV=local`**, sessões no driver `file` e servidor PHP embutido ligado **somente em `127.0.0.1` do runner do GitHub Actions**, acessado por um cliente Python padrão (`urllib`) com cookies entre requisições.

O ponto de entrada `labs/http-transport/router.php` é exclusivo do laboratório e fica **fora de `public/`**. Em cada request, recusa com 404 salvo se todos os requisitos estiverem presentes: PHP `cli-server`, `GITHUB_ACTIONS=true`, flag `PORTAL_HTTP_LOOPBACK_LAB=1`, `APP_ENV=local`, `SERVER_ADDR=REMOTE_ADDR=127.0.0.1`, host explícito em 127.0.0.1 com portas 18945/18946 e prefixo de recurso `/_loopback/transport/`. O `routes/web.php` operacional **não** importa esta implementação. Nenhuma nova rota de clientes ou rota de login real é exposta.

Os handlers de laboratório aceitam a prova **literal e publicamente fictícia** `lab-only`, sem base de usuários ou autenticação, somente para verificar rotação de sessão e CSRF. Nunca usar essa prova como credencial ou proteção em produção.

## 2. Critérios de aceite de HTTP local

- GET `/_loopback/transport/csrf` inicializa a sessão e retorna token, sem caching.
- Cookie de sessão com `HttpOnly`, `SameSite=Strict`, `Path=/` e sem `Domain` compartilhado.
- POST de login fictício sem token CSRF ou com token errado recebe **HTTP 419**, sem criar sessão marcada como autenticada.
- POST com token válido e cookie associado avança, chama `session()->regenerate()`, altera ID de sessão, e GET seguinte lê estado em **arquivo persistido** entre requisições HTTP.
- GET de rotas `/_lab/portal/login`, `/portal` e recurso desconhecido não deve expor handler de cliente.
- Logout sem CSRF válido retorna 419 e preserva sessão; logout com token válido invalida a sessão.
- Token CSRF antigo não pode recriar a sessão após logout.
- Dois modos distintos: porta 18945 com cookie **Secure=false apenas para verificação HTTP funcional sem TLS**; porta 18946 com `SESSION_SECURE_COOKIE=true` comprova **atributo Secure na resposta Set-Cookie**, mas **não** testa entrega/recepção HTTPS.
- Nenhuma chamada externa ou serviço com dados reais: os scripts usam apenas a origem `http://127.0.0.1`, sem Composer adicional além do lock existente e sem PostgreSQL nessa etapa.

## 3. Segurança e limites da evidência

Este harness prova somente comportamento de transporte em um servidor HTTP local, **não segurança da autenticação F2**. A senha/MFA sintéticos de F2 não participam do HTTP loopback; a aplicação de clientes real continua sem sessão persistida oficial nem endpoints. Em produção seria obrigatório TLS real, cookie Secure/HttpOnly/SameSite comprovado sob HTTPS e proxy correto, CSRF com frontend real, testes de cookies entre processos, Origin/Referer/Sec-Fetch-Site, rate limit, sessão distribuída, MFA com segredo criptografado, recuperação, auditoria, autorização multiempresa por PostgreSQL e revisão por segurança.

A verificação da flag Secure no HTTP **não deve ser comunicada como prova de HTTPS**.

## 4. Inventário preliminar de pontos de escrita (escopo Laravel)

Inspeção da árvore remota da branch deste lote:
- `apps/portal/app/` contém `Domain/`, `Presentation/`, `Providers/`; **não há diretórios versionados** `app/Jobs`, `app/Listeners`, `app/Console` no escopo consultado.
- `apps/portal/routes/` contém `console.php` e `web.php` (o arquivo web operacional permanece fechado salvo os testes CLI da etapa F1).
- `apps/portal/labs/` contém `client-auth/` e `http-transport/`. O SQL de criação/escrita/revogação de solicitações de F1–F4 é apenas o laboratório.

**Isto NÃO é inventário completo do repositório legado, tarefas n8n, Cloudflare, integrações externas ou de todos os possíveis escritores administrativos.** Não homologar `SEC-R03/T3` ou revogação global por ausência de diretório de jobs na aplicação em estágio inicial. A futura checklist operacional deverá cobrir SQL direto, CLI, workers, webhooks, scheduler, suporte, backups, outbox, ações humanas e múltiplos bancos, incluindo privilégios de owner, antes de merge de rota pública.

## 5. Governança

F5 é branch filha de F4 (PR #84), PR em rascunho, sem merge. Nenhum dado de cliente, VPS, Cloudflare, autenticação pública, pagamento, conta real, migração operacional, custo adicional ou ambiente compartilhado. Gates P0, D02-SEC, D03/RLS, G-ID/G-BB, SEC-R03/T3, compliance e release **ABERTOS**.

**Regra:** somente registrar PASS dos testes HTTP depois de ler o log do workflow `portal-f5-http-loopback.yml`, com SHA exato. Caso falhe, corrigir somente esta branch e executar novamente.
