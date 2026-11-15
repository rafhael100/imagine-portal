# Imagine Portal SEO RC47 — emissor confiável, revogação serializada e auditoria PostgreSQL

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Integração para revisão independente de todo o histórico SEO do Portal contra `main`.

## Escopo incorporado

Preserva as implementações anteriores do Portal SEO (contratos de GSC/OAuth, governança por tenant, relatórios F118–F120), inclui o pacote de assinatura e banco [RC45 #864](https://github.com/rafhael100/imagine-portal/pull/864) e avança com:

- [F125 #865](https://github.com/rafhael100/imagine-portal/pull/865): assinatura produzida pela função real do [SEO Engine F121 #177](https://github.com/rafhael100/imagine-seo-engine/pull/177), com auditoria de HTML e verificação no PHP.
- [F126 #868](https://github.com/rafhael100/imagine-portal/pull/868): fluxo único HTML local → Engine assinador → verificador Ed25519 Portal → inserção de nonce no PostgreSQL 18 FORCE RLS, incluindo duas execuções concorrentes.
- [F127 #870](https://github.com/rafhael100/imagine-portal/pull/870): cadastro de **chaves públicas previamente confiáveis**, separado do envelope, com validade e estado de revogação por tenant/emissor. O Portal não aceita uma chave fornecida por um cliente ou pelo envelope.
- [F128 #871](https://github.com/rafhael100/imagine-portal/pull/871): bloqueio cooperativo `pg_advisory_xact_lock` para serializar revisão de chave/consumo de nonce com revogação simultânea; não concede UPDATE/DELETE de chaves ao verificador.
- [F129 #872](https://github.com/rafhael100/imagine-portal/pull/872): tabela de eventos com FORCE RLS; **revogação e auditoria obrigatória na mesma transação**. INSERT de auditoria negado reverte completamente a alteração da chave.

Há também a [RC46 paralela #867](https://github.com/rafhael100/imagine-portal/pull/867) que integra outro recorte da F126. A RC47 parte do caminho F126 #868 e contém as defesas F127–F129; uma revisão humana deve reconciliar PRs paralelas antes de escolher branch de merge. **Não mesclar duas RCs automaticamente.**

## Testes esperados na SHA exata da RC47

1. Laravel PHP 8.3 e 8.4, Node 22/24.
2. Interoperabilidade Engine RC45 F114/F117 → PHP Portal F118 (4 cenários).
3. Assinaturas Ed25519 emitidas em CI e verificadas pelo Portal, incluindo falhas de adulteração/tenant/tempo/chave.
4. PostgreSQL18 com migrações reais, role sem bypass, isolamento por empresa, replay, corrida entre dois consumidores e retenção conservadora.
5. Testes F127 de chaves públicas cadastradas, revogadas e de outra empresa.
6. Testes F128/F129 de corrida de revogação e auditoria obrigatória, inclusive **rollback de UPDATE quando INSERT de auditoria não tem permissão**.
7. Identificar checks ignorados/sem trigger e não classificá-los como aprovados.

## Lacunas operacionais não resolvidas

- **Identidade real:** falta sessão Portal/MFA, RBAC por tenant, serviço ativo e permissão expressa para visualização das auditorias de cada empresa.
- **Chaves e emissor:** falta infraestrutura de emissão real com chave privada protegida por KMS/HSM, distribuição segura do trust anchor, rotação/revogação sob administrador autenticado, escopo da autoridade e catálogo de versões de chave.
- **DBA/Auditoria:** a trava F128 é cooperativa; SQL privilegiado fora da rotina pode alterar chaves. É necessária proteção de acesso administrativo, auditoria independente e retenção aprovada dos eventos.
- **Fonte IBGE:** o teste HTTPS ao vivo do IBGE ainda não foi comprovado por causa de timeout no runner; assinatura do relatório não autentica o dado territorial.
- **Google GSC:** o código de laboratório não substitui OAuth readonly ativo, grants por propriedade e empresa, consentimento, revogação, segregação de tokens e observabilidade.
- **Estágio real:** inexistência de prova de deploy em VPS staging com TLS, backup/restauração, isolamento, CDN, testes de rollback, revisão SEO editorial, LGPD/AppSec e aceite final.

## Estados seguros

Nenhuma API pública, cliente real, Google, VPS, DNS, checkout de `main` ou cobrança é alterado por esta RC. Todas as chaves e identidades dos testes são sintéticas e temporárias. Mesmo um diagnóstico técnico assinado com zero falhas mantém a aprovação de publicação **FALSE**.

**ReleaseStatus: HOLD_REAL_TENANT_IDENTITY_KMS_KEY_GOVERNANCE_STAGING_AND_APPROVAL.**
