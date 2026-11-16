# Portal SEO F139 — assinatura multilíngue v2 consumida uma única vez no PostgreSQL 18

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação de [F138 #893](https://github.com/rafhael100/imagine-portal/pull/893), que já provou assinatura Engine F126 → verificação PHP em laboratório.

## Execução controlada

A nova classe `PortalSeoPgMultilingualEvidenceOnceLab` usa o verificador F138 como fronteira estrita de autenticidade, extrai apenas nonce/expiração já autenticados e tenta inserir o digest SHA-256 em `portal_seo_build_evidence_nonces` sob o helper existente `GoogleSearchConsoleTenantScopedTransaction`.

- Mesmo domínio de digest de nonce F123/v1 para evitar reaproveitar o **mesmo nonce** entre versões do protocolo.
- Tabela existente de PG18 com `ENABLE ROW LEVEL SECURITY` + `FORCE ROW LEVEL SECURITY` e índice único `(tenant_ref,nonce_digest)`.
- Papel mínimo não-superusuário, sem `BYPASSRLS`, com `SELECT` + `INSERT`; leitor `SELECT` somente não consegue consumir.
- `ON CONFLICT DO NOTHING RETURNING` assegura exatamente um aceite sob concorrência, sem UPDATE privilegiado.
- O escopo tenant é transacional, não permanece na sessão.
- Relatório bloqueado por conteúdo ainda pode ser registrado como **diagnóstico** uma vez; isso não concede publicação.
- Laboratório exclusivamente CLI, com `CI=true`, `APP_ENV=testing`, `PORTAL_SEO_F138_LAB=1`, `PORTAL_SEO_F139_PG_CI=1`, banco descartável fixo `portal_gsc_f108_ci`.

## Teste de integração

Workflow `.github/workflows/portal-seo-f139-multilingual-pg18-ci.yml` executa em PostgreSQL 18 e PHP 8.3/8.4. Fixa o commit Engine `38b80fe77bd74ea6e19b8751014038ac3b9f8400`, gera assinaturas verdadeiras sobre HTML temporário PT/EN/ES e aplica as migrations Laravel **apenas ao banco descartável do CI**.

O laboratório verifica: primeiro uso permitido, repetição negada, chaves e assinatura adulteradas negadas, relatório com autoridade forjada negado, conteúdo bloqueado continua bloqueado, papel SQL de leitura negado, isolamento entre dois tenants, escopo transacional e concorrência com **um aceite e uma rejeição**.

## Limitações

As identidades, credenciais, HTML, instância de banco e chaves são inteiramente de CI. O fato de a tabela e o RLS funcionarem em PostgreSQL real **não** significa que o Portal já valide sessão de cliente real, que o emissor seja confiável em produção ou que o banco de staging tenha migrações autorizadas. Nenhuma chamada HTTP, publicação, indexação, cobrança ou exibição para clientes foi implementada.

**Próximo gate:** configuração de staging Leaseweb com papéis reais mínimos, credenciais seguras e revogáveis, autenticação admin/MFA + serviço/tenant verificados, retenção de eventos e auditoria LGPD, backup/restauração, rollback e revisão AppSec/DBA. A liberação editorial depende de fonte IBGE oficial, qualidade territorial e autorização humana.
