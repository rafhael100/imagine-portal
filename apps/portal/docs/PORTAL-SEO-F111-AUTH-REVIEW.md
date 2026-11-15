# Portal SEO F111 — sessão / MFA / empresa / propriedade (PostgreSQL18)

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação direta da
[F110 / PR #847](https://github.com/rafhael100/imagine-portal/pull/847).

## Entrega efetiva

A F111 adiciona três tabelas de revisão, com **FORCE RLS** por tenant:
- portal_seo_review_sessions: somente digest HMAC do identificador opaco
  da sessão, ator, emissão/expiração, carimbo MFA sintético, revogação e
  revisão de conta
- portal_seo_review_memberships: associação ativa ao tenant com permissão
  específica seo.view, carimbo de atualização e revogação
- portal_seo_review_resources: mapeamento de recurso opaco do tenant para
  fingerprint da propriedade do Google Search Console

O código PortalSeoPgIdentityReviewLab combina, **na mesma transação
PostgreSQL escopada**:
1. Registro de sessão com token opaco de 256 bits, digest HMAC de chave
   separada do banco e vínculo ao tenant
2. MFA registrado nos últimos dez minutos, revisão da conta coerente e
   sessão ativa/não revogada
3. Associação ativa ao tenant, permissão seo.view, revalidação de
   frescor e nenhuma revogação
4. Recurso do mesmo tenant vinculado ao fingerprint exato da propriedade
5. Registro GSC recente readonly do mesmo tenant/ator/propriedade,
   inspecionado pelo componente PostgreSQL existente

Toda falha retorna null. Sucesso produz apenas um **parecer sintético
com todas as autorizações de produção falsas**, sem retornar sessão,
identidade, tenant, domínio, segredo, chave ou token.

## Prova em CI

A suíte cria somente entradas sintéticas em PostgreSQL 18 descartável.
Duas empresas recebem tokens distintos e grants da mesma propriedade
fictícia. O papel SQL de consulta é não-superuser e não-BYPASSRLS,
com permissão SELECT apenas no novo ledger.

Os testes verificam tenant/ator/recurso equivocados, sessão ausente,
expirada, revogada e divergência de revisão de conta, MFA ausente
ou antigo, membership e recurso revogados, autorização GSC revogada,
ambiente não-test e ausência de vazamento após COMMIT.

CI: .github/workflows/portal-seo-f111-auth-pg18-ci.yml, PHP 8.3/8.4,
executando as provas cumulativas F108, F109 e F110 antes da F111.

## Limite de segurança e caminho para uso real

**Importante:** não existe nesta entrega um emissor confiável de
identidade, login com senha, verificação real de segundo fator nem
armazenamento de sessões produtivas. Os registros de MFA são
**sintéticos e jamais contam como MFA real**. O código exige
CLI + APP_ENV=testing + CI=true + PORTAL_SEO_F111_PG_CI=1.

A F111 não conecta o PHP a rotas HTTP, não cria usuários reais,
não realiza OAuth Google, não lê dados de clientes e não autoriza
publicar a tela. A variável RLS app.tenant_ref não é mecanismo de
autenticação e pode ser manipulada por SQL arbitrário na mesma
conexão; são necessários proteção contra SQLi, fonte autorizadora
de sessão/empresa e revisão DBA/AppSec.

Para habilitar o serviço futuramente: emissor de sessão Portal real,
MFA, revogação transacional e revisão de conta, membership oficial,
autorização por organização/propriedade, OAuth próprio do Google
readonly com identidade verificada, testes de HTTP/TLS e
homologação segura em VPS com backup, rollback e aceite LGPD.

**Sem mudanças em main, DNS, VPS, CRM, GSC real ou cobrança.**
