# Portal F118 — Reserva de metadados de orçamento e outbox em PostgreSQL 18 descartável

**Escopo:** somente `apps/portal/labs/commercial-intake-f118` e workflow de CI próprio; não é migração, endpoint Laravel, receptor operacional ou entrega ao CRM.

A etapa F117 verificou que os oito campos técnicos do envelope Comercial F78 são compatíveis com um inspector PHP do Portal. A F118 conecta essa inspeção a uma transação **PostgreSQL 18 real**, mas apenas dentro de um contêiner descartável do GitHub Actions, usando identidades e UUIDs fictícios.

## Desenho e testes

- `fixture_pg18.sql`: cria o schema descartável `portal_commercial_ci`, os papéis `portal_commercial_f118_a/b` (`NOLOGIN`, `NOBYPASSRLS`), tabela `metadata_ledger` apenas com seis UUIDs e tabela `pending_outbox` de três campos técnicos; `FORCE ROW LEVEL SECURITY` e políticas fixadas em duas empresas fictícias.
- `PortalF118SyntheticMetadataReservation.php`: recebe somente o envelope de oito campos e a fixture separada de vínculo validada pela F117, recusa outras bases ou conexão já em transação, aplica `SET LOCAL ROLE portal_commercial_f118_a` na transação e executa `WITH created AS (INSERT ... ON CONFLICT DO NOTHING RETURNING), pending AS (INSERT ... SELECT FROM created)`. Um erro na outbox desfaz a criação do ledger.
- Em replay, exige que vínculo/site/form/destino coincidam com o **registro original**, que a outbox correspondente realmente exista e que não haja uma segunda submissão; um destino diferente retorna `DUPLICATE_PINNED_CONTEXT_CONFLICT`.
- `test_pg18_metadata_atomicity.php`: executa gravação inicial, replay idempotente, contexto adulterado, campos inesperados, tentativa de tenant B sob papel A, leitura cruzada negada por RLS, comandos UPDATE/DELETE/TRUNCATE negados e rollback integral por evento inválido.
- `.github/workflows/portal-f118-commercial-metadata-pg-ci.yml`: executa em PHP 8.3 e 8.4, somente runner GitHub-hospedado com PostgreSQL 18 contêiner descartável e porta de conexão local `127.0.0.1`. A rotina exige `PORTAL_F118_PG_LAB=1`, banco exato `portal_commercial_lab`, e recusa schema preexistente. Não há Laravel `migrate`, Composer, dados pessoais ou conexão externa com infraestrutura Imagine.

O script do teste utiliza usuário administrador **exclusivamente do banco isolado em CI** para assumir papel A/B, de modo a provar RLS e permissões. Isso não é autenticação empresarial operacional. Todos os retornos mantêm `crmPersisted=false`, `canAcknowledgeVisitor=false`, `productionAuthorized=false` e proíbem alegar que um contato foi efetivamente entregue.

## Dependências reais ainda abertas

O Portal precisará de origem confiável do vínculo/empresa, serviço autenticado, consentimento com fundamento legal, antiabuso, entrada pública segura, armazenamento de conteúdo protegido, chaves rotativas, limites de dados, outbox operacional, backup/restore e reconciliação. O CRM precisará de API receptora autenticada com idempotência final, transação persistente e recibo verificável pelo Portal; a F118 não consulta nem modifica o CRM.

**Não há** rota pública, worker, dado real, credencial da VPS, DNS, gasto novo, integração de sessão Portal–CRM, merge em `main` ou autorização de produção. O gate F70 do Comercial permanece fechado até homologação e revisão independente.
