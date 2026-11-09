# PORTAL-F6 — MFA + sessão PostgreSQL + RLS em HTTP de loopback

**Data:** 08/11/2026. **Natureza:** laboratório com autenticação de dados fictícios. A Direção autorizou prosseguir autonomamente na implementação e nos testes, sem publicar clientes, cobrar ou criar serviços externos.

## Entregas

O F6 combina as provas parciais F2 (senha, MFA TOTP, bearer com digest PostgreSQL), F3 (idempotência por organização), F4 (bloqueio cooperativo na escrita/revogação) e F5 (HTTP, cookies, CSRF), dentro de um servidor PHP temporário **ligado somente em 127.0.0.1:18947** no GitHub Actions. O roteador `labs/http-pg/router.php` fica fora de `public/` e não é referenciado por `routes/web.php`, `bootstrap/app.php` ou outros pontos de entrada do produto.

**Guardas simultâneas:** PHP `cli-server`, GitHub Actions, `APP_ENV=local`, `PORTAL_HTTP_PG_LAB=1`, `DB_DATABASE=portal_lab`, usuário SQL `portal_lab_runtime`, porta 18947, `HTTP_HOST=127.0.0.1:18947`, origem TCP local `127.0.0.1` e prefixo `/_loopback/pg/`. As rotas F1–F4 originais permanecem ativas **somente no ambiente CLI testing** e não são instaladas no servidor HTTP.

O kernel Laravel executa o middleware real `web` com `APP_ENV=local` para avaliar CSRF. Para mutações, o roteador exige `Origin` exato de loopback e rejeita `Sec-Fetch-Site: cross-site` quando informado. Essa checagem não substitui o token CSRF; é defesa em profundidade para esta PoC. Sessões Laravel são arquivos **criptografados** e o cookie host-only tem `HttpOnly`, `SameSite=Strict` e `Path=/`. `Secure=false` é usado exclusivamente por ser HTTP local sem TLS — **não é configuração admissível para produção**.

O servidor PHP do F6 usa apenas a role **`portal_lab_runtime`**, não a conta administradora. A criação das roles e os registros inventados ocorre **antes** de iniciar o servidor, em comandos PHP CLI com `APP_ENV=testing` e conta superusuária do **único banco descartável** `portal_lab`. O usuário runtime não pode alterar vinculações, contas ou criar esquema; operações são verificadas por RLS nas tabelas de serviços e solicitações.

## Cenários de aceitação

1. Sem sessão, a lista de empresas retorna 401.
2. POST sem CSRF ou com CSRF errado retorna **419**, mesmo que a senha e o TOTP estejam corretos.
3. Origin externo com CSRF correto retorna **403**; senha errada retorna 401.
4. Senha fictícia correta + TOTP calculado geram digest de sessão PostgreSQL (bearer não aparece na resposta), cookie e ID de sessão renovados.
5. A sessão persiste entre requisições HTTP separadas e revela somente `org-a` e `service-a`, nunca `org-b` ou `service-b`.
6. Pedido sintético autorizado em A: primeira inclusão **201**, replay com a mesma chave **200**, conflito de conteúdo com a mesma chave **409**, tentativa na empresa B **404**. Os headers de ator do navegador não alteram a sessão verificada.
7. Revogação de vínculo executada **offline no próprio runner** por conexão administrativa laboratorial: a sessão ainda existe, mas a empresa é ocultada e novas leituras/escritas são negadas.
8. Aumento da revisão de usuário na fonte PG: a sessão com token antigo passa a receber **401**.
9. Logout exige CSRF válido, marca a sessão revogada no DB, invalida o cookie; token CSRF antigo recebe 419.
10. `/_lab/portal/login`, `/portal` e rotas desconhecidas retornam **404** no HTTP de loopback.

A suíte F1–F4 de PHPUnit PostgreSQL será executada novamente em container descartável, antes do seed novo. Todos os passos são condicionais a evidências no SHA final. Não há nenhuma alteração de contrato real nem de VPS.

## Limitações de segurança não resolvidas

**F6 não é autenticação de cliente pronta.** O segundo fator utiliza segredo artificial público e não dispõe de cadastro, criptografia operacional de segredos, recuperação, notificações nem antifraude. Os testes usam HTTP sem HTTPS real, em loopback; não comprovam TLS, proxy confiável, cookies Secure ao longo de transporte TLS, múltiplos processos/dispositivos, logout global, controle de privacidade nem disponibilidade distribuída. O banco é temporário, sem restore. A role runtime ainda possui permissões de teste que exigirão divisão funcional. O lock advisory F4 continua condicional à participação de todos os caminhos de escrita/revogação. A origem real de identidade/vínculos do Portal e o isolamento contra Z2 comprometido não estão homologados.

Nenhum serviço real Imagine Sites/CRM/Performance/Universidade é integrado; sem cobranças, e-mails, push, fornecedores, despesas, fusão de branches, permissões operacionais ou dados pessoais.

**Gates ainda abertos:** D02-SEC, D03/RLS, P0, SEC-R03/T3, credenciais/cadastro, privacidade, performance e release. Próximo foco: testes adversariais de workers/outbox e restauração antiga após revogação, seguidos de arquitetura real de autenticação e banco.
