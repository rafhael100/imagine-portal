# Imagine Portal RC48 — autenticação administrativa e segurança SEO integradas

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Esta é uma release candidate **para revisão técnica e homologação**, não uma liberação pública.

## O que agora existe em código e foi testado separadamente

A RC48 inclui o histórico da [SEO RC47 #873](https://github.com/rafhael100/imagine-portal/pull/873), mais:

- [SEO F130 #875](https://github.com/rafhael100/imagine-portal/pull/875): gatilhos PG18 impedem exclusão/alteração de chaves cadastradas, reativação de chave, UPDATE/DELETE de eventos e revogação sem evento correspondente validado no COMMIT.
- [SEO F131 #876](https://github.com/rafhael100/imagine-portal/pull/876): protótipo SQL `SECURITY DEFINER` **somente em banco descartável**, com owner NOLOGIN/NOBYPASSRLS e papel operador sem DML direto de chaves/auditoria; função guarda a transação completa.
- [Portal F132 #877](https://github.com/rafhael100/imagine-portal/pull/877): primeira autenticação administrativa Laravel 13 em duas etapas — senha com hash, TOTP cifrado, rate limiting, sessão renovada, prevenção de código reutilizado, conta ativa e logout.
- [Portal F133 #878](https://github.com/rafhael100/imagine-portal/pull/878): comando Artisan de provisionamento **controlado em staging ou CI**, sem cadastro público, senha digitada em terminal seguro, arquivo TOTP de matrícula 0600 em diretório 0700, conta inativa por padrão.
- [Portal F134 #879](https://github.com/rafhael100/imagine-portal/pull/879): vínculo por administrador+organização+permissão `seo.view`, prazo de validade e revogação. A página protegida só existe com login/MFA e grant ativo, mas **não exibe dados de clientes**.

## Estados separados: técnico versus operacional

| Recorte | Situação na RC48 |
|---|---|
| HTML Engine → relatório Ed25519 → Portal PHP → PG18 nonce único | Comprovado em CI sintética |
| Chaves públicas cadastradas por tenant, revogação concorrente e auditoria obrigatória | Comprovado em PostgreSQL18 sintético |
| Autenticação administrativa com senha e TOTP e provisionamento CLI | Código funcional e testes PG18; **flag padrão desligada** |
| Acesso `seo.view` por conta e organização | Condicionado a grant de teste; **sem serviço contratado verificado** |
| Login público/cliente e visualização de métricas reais | **Não implementados/não autorizados** |
| Emissor de sessão cliente real, SSO/MFA e isolamento tenant operacional | **Pendente** |
| KMS, chaves reais do Engine, OAuth GSC e dados Analytics/CRM | **Pendente** |
| Captura IBGE HTTPS comprovada, edição territorial, publicação municipal | **Pendente** após timeout externo |
| Implantação Leaseweb/VPS, TLS, sessão persistente, backup/rollback | **Não executada nesta RC** |

## Matriz de liberação exigida na SHA exata desta PR

1. Laravel PHP 8.3/8.4, Node 22/24, testes de rotas fechadas por padrão.
2. PostgreSQL18 PHP 8.3/8.4: autenticação F132, provisionamento F133 e escopo por organização F134.
3. PostgreSQL18 PHP 8.3/8.4: chaves confiáveis, assinatura da origem Engine, replay e concorrência F123–F129.
4. PostgreSQL18 PHP 8.3/8.4: gatilhos F130 e função de revogação de menor privilégio F131.
5. Revisão independente do diff histórico contra `main` e reconciliação das PRs paralelas de Portal/SEO/Universidade.
6. Ativação exclusiva em staging com domínio HTTPS, `APP_KEY` persistente, `SESSION_DRIVER=database` ou Redis, `SESSION_SECURE_COOKIE=true`, logs e RBAC por empresa.
7. Cadastro auditável de administrador real, recuperação segura de conta/MFA, concessão de organização com vínculo contratual comprovado, origem de emissor e KMS, permissão `seo.view` com RLS não-bypass.
8. Revisão DBA/AppSec/LGPD, backup/restauração, migração e rollback, autorização explícita de deploy.

## Segurança e confiabilidade da entrega

As contas, empresas, chaves, dados de GSC, arquivos HTML e bancos usados na CI são sintéticos. Não usar nenhum resultado de CI como prova de propriedade Google, prestação local, contratos ativos, clientes, tráfego, leads ou faturamento. Nenhuma página municipal ou painel de cliente foi habilitado.

**Não mesclar automaticamente** a RC48 nem as RCs anteriores. A base principal do Portal continua inalterada nesta rodada; a comparação completa e conflito com PRs paralelas podem requerer resolução antes da promoção.

**ReleaseStatus: HOLD_PRODUCTION_IDENTITY_TENANT_ENTITLEMENTS_KMS_SECURITY_STAGING.**
