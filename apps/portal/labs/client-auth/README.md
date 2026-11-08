# PORTAL-AUTH-LAB-F1 — laboratório funcional Laravel + PostgreSQL 18/RLS

**08/11/2026 — Escopo autorizado:** desenvolvimento autônomo controlado do Imagine Portal. Este lote usa **exclusivamente dados, credenciais e containers descartáveis no GitHub Actions**. Não é um sistema de clientes, autenticação homologada, ambiente público ou autorização para produção.

## Entregas concretas

- Schema lab fora das migrations automáticas: usuários do Portal, empresas, vínculos e serviços; chaves (org_id,id); papéis SQL, RLS em serviços sintéticos e RLS para leitura/inserção de solicitações, com FK composta (org_id, service_id).
- Login de teste com verificação de hash de senha, cookie/sessão Laravel de processo de teste e revisão de sessão consultada a cada request.
- GET das empresas ativas do usuário; GET dos serviços por empresa com checagem do vínculo e FORCE RLS em transação; Blade do painel alimentado por registros **sintéticos** autorizados, escaped por padrão.
- POST logout invalida sessão, além de revogação por alteração da revisão no banco. Role SQL de runtime com leitura dos registros de identidade/vínculos/serviços e somente SELECT/INSERT nas solicitações, sob políticas RLS; sem UPDATE/DELETE ou escrita de memberships.
- Testes negativos: usuário sem sessão, credencial errada, CRM não aceito como Portal, tentativa A→B, vínculo/organização suspensos, sessão revogada, SQL read-only, falta de contexto RLS, vazamento por pooling e HTML injetado, tentativa de INSERT sem ator, FK de serviço de outra empresa e POST de solicitação após revogação.
- Workflow `.github/workflows/portal-client-lab-postgres.yml` com PG18 descartável. Recurso **não é deploy**, migrations reais, nova VPS ou banco do CRM.

## Guardrails contra exposição inadvertida

`apps/portal/routes/web.php` só inclui rotas de laboratório se **simultaneamente**:
- ambiente Laravel `testing`;
- `PHP_SAPI === 'cli'` (PHPUnit, não um navegador servido por php-fpm/cli-server);
- `PORTAL_CLIENT_LAB_ENABLED=1`.

As rotas exclusivas de teste usam o prefixo `/_lab/portal` e jamais serão carregadas no Portal operacional pela configuração entregue. O role SQL sintético é criado apenas quando `APP_ENV=testing`, `DB_DATABASE=portal_lab` e flag lab estão presentes. Dados e senhas de laboratório são públicos e descartáveis; **não reutilizar essas credenciais em VPS/produção**.

## Como executar a prova em CI

1. Runner GitHub inicia serviço PostgreSQL 18 separado e descartável, banco `portal_lab`.
2. Instala Composer apenas pelo lock existente; gera chave de app descartável.
3. Executa bootstrap limitado que cria `portal_lab_runtime` e concede somente os direitos mínimos.
4. Roda PHPUnit com `labs/client-auth/phpunit-lab.xml`, onde o schema é criado e apagado apenas para cada teste.
5. Runner destrói o serviço PostgreSQL ao término. Sem dados pessoais ou terceiros. Registrar CI real antes de afirmar aprovação.

## Limites de segurança que impedem produção

- **MFA ainda ausente.** Login é demonstrativo; não liberar para clientes.
- **CSRF real não comprovado:** Laravel desativa verificação CSRF em testes Feature. Produção exige teste HTTP real com cookie e token, rate limits e antiabuso.
- **Sessão Array é efêmera:** não representa persistência segura e revogação distribuída. Cookies Secure/HttpOnly/SameSite, sessão guardada em store oficial, reset/recuperação e autenticação de dispositivos exigem nova implementação.
- **Escopo RLS é parcial:** `lab_portal_services` tem policy SELECT e `lab_portal_requests` tem policies SELECT/INSERT; não são testes de segurança de todas as entidades do Portal. Os outros dados são acessados por role de consulta restrita, controlados em aplicação. Contexto GUC pode ser forjado caso Z2 seja comprometida; não há prova T3/comprometimento do app.
- **Concorrência e anti-rollback não testados:** corte/revogação no momento de commit, jobs/outbox, transações simultâneas, pools externos e restauração anterior à revogação ainda bloqueados.
- **Sem identidades reais:** cadastro, verificação, CNPJ, convite, claims, conectores CRM/Performance e Universidade ficam fora de escopo.
- Não utilizar `LabPortalGateway` ou seus handlers como middleware de produção ou como fonte autorizativa definitiva; reusar as interfaces abstratas originais de Session/Membership/Resource Authority em implementação própria, após controles e testes.

**Status dos gates:** D02-SEC, D03/RLS, P0, G-ID/G-BB, SEC-R03/T3, recuperação e release **ABERTOS**. Todos os resultados positivos neste lote referem-se apenas ao laboratório.

## Próximas fatias autônomas sugeridas

A) Inspecionar/implementar infraestrutura confiável de sessão, cookies, bloqueios de login, MFA e reset em outro recorte de testes com bancos descartáveis.  
B) Integrar políticas físicas de tenant com todas as tabelas sensíveis, escrituras e concorrência.  
C) Produzir primeiro dashboard Laravel com identidade/sessão de teste, sem dados reais; já iniciado nesta PoC.  
D) Contratos de serviços, solicitações e integração segmentada por produto; só com autenticador/tenant efetivos.  
E) Backups, revogações, outbox e restore robustos antes da publicação final.

A Direção autorizou avançar no desenvolvimento sem microaprovações; **produção, credenciais, dados de clientes e despesas continuam sujeitos à avaliação técnica específica e decisão de risco.**
