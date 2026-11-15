# Portal SEO F114 — revogação atômica de grant + candidato OAuth + auditoria

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação da
[PR F113 #851](https://github.com/rafhael100/imagine-portal/pull/851).

## Problema resolvido em laboratório

Revogar o grant registrado da propriedade GSC sem invalidar também um
candidato de token cifrado pode deixar o banco em um estado inconsistente.
A F114 cria uma operação única em PostgreSQL18, com a mesma transação
tenant-RLS e rollback em falha:

1. Revogar o registro exato da propriedade/ator/tenant, com compare-and-swap
2. Invalidar (revoked_at) qualquer candidato de token cifrado correspondente;
   ausência de candidato é aceita e não impede a revogação do grant
3. Registrar o evento obrigatório property_grant_revoked sem token, IP,
   URL ou payload no evento de auditoria
4. Confirmar a transação **somente se as três operações forem consistentes**;
   se faltar privilégio de UPDATE no candidato ou INSERT na auditoria,
   reverter as alterações de ambos

A classe PortalSeoPgGrantTokenRevocationLab não revoga tokens no Google,
não cria permissões, não autentica usuários e não retorna dados de clientes.
A aplicação real ainda precisa de sessão e MFA verificáveis, aprovação
administrativa e comunicação segura com a API Google, quando aplicável.

## Prova PostgreSQL18 em CI

A matriz PHP 8.3/8.4 executa as suítes anteriores F108–F113 e a F114
sobre as migrações Laravel reais, no mesmo contêiner temporário.

Os testes da F114 criam apenas propriedades e tokens *sintéticos*;
os candidatos são persistidos cifrados (XChaCha20-Poly1305) pelo
componente existente sob RLS.

Casos: revogação atômica, replay, outra empresa intocada, outro ator
bloqueado, domain versus URL-prefix, papel sem INSERT na auditoria,
papel sem UPDATE de token, grant sem candidato, nenhum dado visível
sem tenant GUC, ambiente não-testing negado.

## Gates produtivos ainda pendentes

- **Google OAuth real e revogação remota não são demonstrados.**
- Garantir que todo consumidor de candidatos confira o grant atual,
  e que todo caminho operacional de revogação utilize a operação
  transacional autorizada ou invalide tokens de forma equivalente.
- Revisão AppSec/DBA/LGPD, papéis SQL, RLS contra SQL injection,
  controle de origem confiável de ator/tenant, MFA, auditoria,
  retenção e política de dados.
- Homologação VPS com certificados, backups, rollback, concorrência,
  limites operacionais e aprovação explícita de publicação.

Workflow:
.github/workflows/portal-seo-f114-grant-token-audit-pg18-ci.yml

Nenhum merge, deploy, migração de produção, token Google real,
acesso a VPS ou alteração de DNS está autorizado por esta PR.
