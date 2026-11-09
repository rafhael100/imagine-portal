# PORTAL-F26 — Ensaio de composição: autoridade Portal F1 + prova F24 + leitura SQL

**09/11/2026 — LABORATÓRIO ISOLADO. Nenhuma autenticação de clientes ou permissão real foi criada.**

## Contexto e motivação

O Portal já possui `PortalAuthorityBoundary` e contratos
`PortalSessionAuthority`, `PortalMembershipAuthority` e
`PortalResourceAuthority` na árvore principal F1. Em paralelo, AUTH-PERF
tem ramos próprios de experiências de identidade e token Ed25519.
Os PRs F20–F25 testaram leitura e revogação fictícia em PDO/PostgreSQL
com HMAC temporário **sem** usar diretamente os contratos do núcleo F1.

O F26 aproxima essas linhas **sem mesclar projetos, duplicar o emissor de
identidade ou ativar um login**: testa a composição dos contratos de
autorização existentes com o laboratório F24/F25.

## Alterações

- `app/Labs/F26/F26AuthorityReadRehearsal.php`: componente de ensaio
  que requer ao mesmo tempo:
  1. `F24LabSessionProof` HMAC de fixture com audiência, emissor,
     prazo, MFA e empresa sintéticos válidos;
  2. referência da sessão DEMO coincidente com o campo assinado;
  3. usuário do `PortalSessionAuthority` do F1 igual ao subject
     assinado de F24, snapshot temporalmente válido;
  4. `PortalAuthorityBoundary::inspect` do F1 apenas para o
     escopo explícito `Permission::ViewServices`; membership ativo,
     grant e resource owner devem coincidir;
  5. `PortalBoundaryVerdict::canAuthorizeHttp()` ainda **false**;
  6. leitura final via `F24SyntheticAuthorizedReader`, que usa o
     PostgreSQL com identidade SQL, RLS, estado corrente de sessão/
     membership, revisões e lock F25 na transação.

- `F24LabSessionProof`: dois acessores *só de exemplo* para comparar
  o identificador da sessão e o ator assinados com a fonte F1.
- `DemoServiceRelationPage`: nova razão fechada
  `untrusted_lab_authority`, sempre sem linhas ou contagens.
- PHPUnit de integração no mesmo PostgreSQL 18 descartável: prova
  positiva **somente sintética**; autoridades padrão negam; actor
  diferente, sessão inativa, MFA ausente, revisão F1 defasada,
  membership suspenso/revogado, permissão incorreta, dono do recurso
  alheio, cookie CRM, token inválido, divergência de tenant SQL,
  falha de provider, revogação SQL entre duas páginas.
- Workflow de CI F22/F24/F25 é estendido para as novas classes/testes
  em PHP 8.3 e PHP 8.4. Banco e credenciais existem somente no runner.

## Restrições de confiança — essenciais

**O único retorno positivo do F26 permite uma prévia de dados
inventados, não leitura de cliente real.**

- As fontes F1 continuam sem emissor de identidade, sessão Laravel
  real, MFA real e vínculo de empresa persistido. Um provider sintético
  pode deliberadamente mentir e gerar `syntheticChecksPass=true`.
  `PortalBoundaryVerdict` é **sempre incapaz de autorizar HTTP**.
- O verificador HMAC é de laboratório; não é o emissor Ed25519 do
  ramo AUTH-PERF, não substitui esse módulo e não pode ser colocado
  em produção como token de cliente.
- A ligação do user/subject é comparada na simulação; a consulta da
  fonte F1 é feita em mais de um momento e **não é atômica** com o
  snapshot SQL. A proteção F25 coordena apenas escritores que obedecem
  ao protocolo de advisory locks do laboratório.
- Não se provou integridade das políticas completas de RLS, controle
  de superuser/banco, readers/writers de outros serviços, revogação
  linearizável global ou restauração de backups. SEC-R03/Z4 e todos
  os gates P0/OPEN permanecem abertos.
- F22 sem prova e F24 sem fonte F1 continuam expostos **apenas como
  classes de teste não registradas**, sem rotas, middleware ou
  providers operacionais. Isso é uma limitação deliberada da PoC,
  não uma arquitetura de autorização para clientes.

## Próximos gates técnicos

1. Inventariar cada leitor/escritor do Portal, CRM, Sites, Performance,
   Universidade e n8n que precise consultar sessões ou revogações.
2. Definir emissor confiável único do Portal e contrato transacional com
   membership, proprietário de recurso, permissão e revogação.
3. Validar opções de IAM/MFA e segregação Portal–CRM (sem SSO presumido).
4. Projetar witness monotônico fora do domínio de backup para impedir
   revogação revertida após restore.
5. Executar testes reais de RLS, banco, concorrência e revogação
   adversarial, com aprovação independente AppSec/DBA.

**Estado:** PR DRAFT e sem merge, deploy, Cloudflare, VPS, dados de
clientes, segredos persistidos ou novos fornecedores pagos. CI pode
consumir minutos conforme o plano GitHub. Laravel/PHP permanece backend
principal; Node.js apenas para ferramentas/legado.
