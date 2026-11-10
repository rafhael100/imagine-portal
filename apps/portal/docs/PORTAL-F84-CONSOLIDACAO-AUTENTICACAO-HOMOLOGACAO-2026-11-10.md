# PORTAL-F84 — consolidação do fluxo de identidade e autorização para revisão (NO-GO)

**Data de referência: 10/11/2026.** Linha-base `dev/fast-track-portal-2026-11-05`. Consolidação com etapas F1–F80 (PR #215) e o desenvolvimento F81–F83, incluindo provas de PostgreSQL, Docker e contratos dos módulos. **Este documento não é aprovação de produção e não substitui revisão AppSec/DBA.**

## Avanços concretos

| Etapa | Implementação | Segurança demonstrada | Limite |
| --- | --- | --- | --- |
| F81 / #216 | Finalização de MFA confronta `organization_revision` de sessão e organização sob lock, mais e-mail verificado | Tenant/revisão ausente ou desatualizado causa rollback do TOTP e negação | CLI/PG18 descartável, sem login de usuário |
| F82 / #219 | Senha Argon2id, limite PG por escopo HMAC, sessão `pending_mfa` de 5 minutos com bearer aleatório e apenas hash persistido; depois TOTP e predicado de permissão | Cross-tenant negado, sem acesso enquanto MFA pendente, replay/limites/revogação | Papel superuser usado apenas em CI para escrever fixtures; requer substituição para runtime |
| F83 / #220 | Função `SECURITY DEFINER` sob owner `NOLOGIN/NOSUPERUSER/NOBYPASSRLS` com SELECT estritamente nas 5 tabelas e policies F83 exclusivas; role de chamada somente EXECUTE | Elimina proprietário superuser da função, sem conceder SELECT à role de conexão | Somente laboratório; auditoria SQL e arquitetura de identidade real pendentes |
| F84 | Proposta de revisão integrada sobre a dev existente | CI completo da SHA será documentado nesta PR | PR DRAFT, não é release |

## Integrações existentes no mesmo tronco

- Backend Laravel e preview HTML offline de sete telas gerais do Portal; contratos com o motor Imagine Sites.
- Prévia demonstrativa de Performance AI e assinatura Ed25519 validada em testes com PostgreSQL sintético; nenhuma permissão de anúncios reais.
- Prévia demonstrativa do módulo SEO; nenhum acesso a propriedades do Google Search Console.
- Runtime Docker PHP8.4/nginx executado no GitHub Actions, com `127.0.0.1:18080` e **somente `/up`** acessível. As demonstrações HTML não são servidas por HTTP.
- SEC-R03 C04: gate de release **bloqueado**, com witness antirollback de restauração externa, AppSec/DBA, IAM e LGPD pendentes.

## Gate real de operação: NÃO APROVADO

A maior lacuna continua ser transformar a prova de identidade em um serviço operacional auditado e protegê-lo corretamente:

1. **Login de usuário real:** convite/cadastro com verificação de e-mail, origem de identidade, MFA provisionado por canal confiável, transporte TLS, sessão/cookie HttpOnly/Secure/SameSite, proteção CSRF, recuperação, expiração e revogação.
2. **Roles e autoridade PostgreSQL:** remover superuser dos caminhos de escrita, provar owner dedicado de todas as funções e policies mínimas sob concorrência, aceitar organização e serviços contratados de fonte verificável, cuidar de PostgreSQL `SECURITY DEFINER` e privacidade.
3. **Rate limiting e custódia:** vincular scope real a origem de IP confiável, bloquear DoS/distribuição e enumeração, gerir pepper e chave TOTP em vault/KMS com rotação, logs e alerta sem segredos.
4. **SEC-R03:** prova de testemunha externa ao domínio de restauração PostgreSQL, auditoria de revogações, retenção e testes de recover/restore.
5. **Homologação operacional:** VPS Leaseweb liberada, rede Docker/TLS, segredos, PostgreSQL com roles, backup e restore, CI/CD com rollback, AppSec/DBA/LGPD, integração comercial Sites/CRM/Performance/SEO com autorização real, teste E2E e decisão formal de lançamento.

## Política desta entrega

Código até F83 foi desenvolvido em branches/PRs separados para revisão, com dados falsos e sem serviços de clientes, contas de anúncios, processamento de pagamento ou DNS. Em F84, o estado de CI da própria HEAD deve ser constatado em Actions; não inferir aprovação com base somente nas PRs filhas. Um teste aprovado **não comprova que o produto está pronto para clientes**.

**NO MERGE / NO DEPLOY / NO LIVE LOGIN / NO NOVOS GASTOS.** A branch `main` e o ambiente da VPS não são modificados por esta proposta.
