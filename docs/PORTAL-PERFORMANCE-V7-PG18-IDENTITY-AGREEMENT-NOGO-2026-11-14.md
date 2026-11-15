# Imagine Portal — Performance V7, identidade SQL real-shaped e acordo de laboratório

**14/11/2026 — laboratório exclusivamente descartável; NO-GO de produção.**

## Problema mitigado

A PR [#826](https://github.com/rafhael100/imagine-portal/pull/826) prova somente com *fakes em memória* que uma sessão empresarial com MFA e `services.manage` deve preceder a revisão comercial V7. Já existe no Portal, contudo, uma base PostgreSQL18 independente (F66/F76/F113) com sessões de bearer **armazenado como SHA256**, identidade, organização, vínculo, grants explícitos e checagens de revisão e revogação.

Esta entrega introduz uma prova **no banco**, sem depender de um objeto de sessão construído pelo chamador e sem ligar novas rotas.

## Componentes

- `labs/performance-v7-pg18/portal_v7_agreement_preflight.sql`: função `SECURITY DEFINER` com `search_path` fixo, executável **somente pelo login SQL de laboratório `portal_v7_lab_reader`**. Compara sessão real de tabela PG18 F66/F113 (finalidade `business`, hash do bearer, validade até 2 horas, MFA confirmado há menos de 10 minutos) e chama **o predicado F76 original** para exigir `services.manage` e revisões atuais de identidade, organização e vínculo. Verifica ainda tabela independente do acordo fictício com empresa, contrato, mês, fingerprint estável do plano/termos, revisão explícita e status aceito.
- A tabela de acordos é **FORCE RLS**, sem grants a clientes, com política somente para um proprietário `NOLOGIN` interno. Esse proprietário também recebe apenas leitura das colunas estritamente necessárias da sessão; não se disponibilizam dados de identidade ou consentimento em respostas.
- `PortalV7SqlAgreementAuthorityLab`: adaptador PHP com `APP_ENV=testing`, execução CLI local, banco nomeado `portal_identity_lab` e identidade do login SQL verificada por `session_user`. Recebe o bearer opaco **somente de fixture**, transforma em SHA256 e retorna um booleano.
- `PortalV7SqlGatedSignedPlanReviewLab`: gera internamente revisão V7 assinada (sem expô-la) e computa o **mesmo fingerprint comercial estável** usado pelo Performance. Só devolve a revisão se o banco autorizar o conjunto sessão+MFA+permissão+empresa+acordo+período+termos+versão. Não cria aceite, faz débito nem muda o banco.
- CI em PostgreSQL18 real com as migrações F66, F76 e F113 originais. Testa aprovações e adversários: sessão desconhecida, grant `services.view` insuficiente, organização trocada, vínculo/identidade revisados ou revogados, expiração e MFA antigo, acordo revogado/reaceito, plano/termos alterados, execução sem SQL role, falso tenant GUC, ausência de grants diretos para as tabelas e `APP_ENV=production`. Executa ainda as regressões F826 e F419/V7.

## Limitações explícitas

Apesar de usar **as tabelas reais de desenho do Portal**, os usuários, senhas de PostgreSQL, sessões e acordos testados são **fictícios**; os registros de aceitação foram inseridos por administrador CI, não por consentimento expresso do cliente. A função F76 é um predicado isolado sem autenticação HTTP, e os hashes de sessão são **equivalentes a credenciais** — não devem ser expostos em rota pública, logs ou S2S. Nenhum código foi incluído em `routes/` ou no container real Laravel. Os campos `portal_iam_authenticated`, `real_customer_acceptance_proven`, `billable`, `can_charge` e `ads_write_enabled` continuam falsos.

**Próximo GO obrigatório:** associar sessão emitida por um login Portal operacional sob aprovação AppSec, um aceite explícito autenticado com termos/versão e trilha de auditoria sem PII desnecessária, separar o canal interno S2S, vincular consentimento à ação no mesmo domínio transacional e comprovar revogação/limites. Além disso, LGPD/SEG/DBA/Financeiro, provedor de Ads, PSP sandbox, rollback e backups.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.**
