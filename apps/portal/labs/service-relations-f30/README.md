# PORTAL-F30 — verificação negativa da view sintética e permissões de schemas

**09/11/2026. Somente PHP/Laravel + PostgreSQL 18 temporário.**

## Lacuna encontrada

O F23 verificava se `f21.service_summary` estava marcada
`security_invoker=true`; F29 verificava políticas RLS e grants de
tabelas. Isso **não verificava o conteúdo de `SELECT` da view**.
Um administrador de laboratório podia substituir a view por outra
com as mesmas colunas/tipos, ainda mantendo `security_invoker=true`,
e mudar os números de solicitações/contratos sem que os controles
de catálogo anteriores detectassem.

Também não eram comparadas as ACLs e o proprietário dos schemas
`f21` e `f24`: grants `CREATE`, grants a `PUBLIC`, remoção
de `USAGE` e troca de proprietário precisavam ser detectados
antes de qualquer consulta assinada de fixture.

## Implementação

- `App\Labs\F30\F30SyntheticViewSchemaInspector` consulta
  `pg_get_viewdef('f21.service_summary'::regclass, false)` pelo
  PDO da própria sessão fictícia de laboratório, dentro da
  transação `REPEATABLE READ, READ ONLY`, antes do SELECT.
- Exige que a definição SQL resultante tenha hash SHA-256
  **exatamente igual ao texto deparser PostgreSQL 18** produzido
  pela fixture F21 no CI. Valor fixado:
  `cc952e214081dac8db6ccae625b6714843f98ca85c96fe27d7abbdf5c315fe69`.
  O mesmo valor foi medido independentemente nos dois jobs
  PostgreSQL 18 do CI inicial F30.
- Confere que a view é `relkind='v'` e pertence ao administrador
  `postgres` do container. Também exige ownership `postgres`
  para ambos os schemas `f21` e `f24`.
- Usa `pg_namespace.nspacl` e `aclexplode` para aceitar
  **exatamente quatro grants USAGE**: um por schema para
  `f21_org_a` e `f21_org_b`; nenhum CREATE, PUBLIC ou outro role.
- Em qualquer anomalia bloqueia
  `DemoServiceRelationPage::blocked('unsafe_service_view_schema')`,
  sem linhas, totais, cursor, concessão de serviço ou acesso real.
- O adaptador F22 aplica essa checagem no caminho de prova
  assinada sintética F24. Os antigos testes F22 sem prova seguem
  *somente como código de laboratório*, sem rotas ou autenticação.

## Provas adversariais

`tests/Integration/F30ViewSchemaIntegrityTest.php` executa oito testes
em PostgreSQL 18 descartável com senhas temporárias:

1. Baseline das empresas A/B;
2. Substituição da view por SELECT de contagens zeradas, mantendo
   `security_invoker=true`;
3. Substituição da view por SELECT filtrado para uma empresa;
4. Concessão CREATE ao papel de leitura no schema F21;
5. Concessão USAGE a PUBLIC no schema F24;
6. Retirada de USAGE do papel B no schema F24;
7. Troca do owner do schema F21 para a role de leitura;
8. Troca do owner da view para a outra role de leitura.

Cada alteração é feita **exclusivamente pelo administrador do
PostgreSQL efêmero**, com restauração em `finally`; o teste
exige resposta bloqueada e confirma que a fixture volta a
funcionar. Jobs PHP 8.3 e PHP 8.4 compartilham o mesmo
código mas usam containers independentes por job.

## Limitações fundamentais

- Hash da saída do `pg_get_viewdef` **não é uma assinatura
  criptográfica de uma autoridade independente**. Código e
  PostgreSQL estão sob o mesmo controle de laboratório. Um
  administrador que altera ambos pode fornecer referência
  maliciosa; restauração conjunta pode recuperar hash antigo.
- `pg_get_viewdef` depende da versão do servidor e da
  representação do deparser. Uma atualização do PostgreSQL,
  mesmo sem alterar a semântica da view, pode exigir novo
  resultado de referência revisado. O mecanismo foi feito
  **para fixture PG18 específica**, não como auditoria
  genérica de views ou migrações reais.
- A verificação não detecta todos os riscos de funções
  SECURITY DEFINER, schemas externos, extinções, roles,
  caches, escrita entre serviços, regras de LGPD,
  revogação distribuída, IAM, MFA, RLS real ou auditoria.
- Não concede acesso HTTP: nenhum controller, middleware,
  service provider, rota, API, sistema de pagamentos,
  CRM, Sites, Universidade, Performance, VPS ou Cloudflare
  foi vinculado.
- A porta do banco é apenas loopback do runner, e as senhas
  são geradas de novo pelo CI. Os minutos do GitHub Actions
  podem seguir a cobrança do plano; não se contrata outro
  fornecedor ou servidor.
- F30 não elimina os gates P0, D02-SEC, D03-RLS, T3/OPEN,
  SEC-R03/Z4 anti-rollback nem a aprovação AppSec/DBA.

**Status:** PR em rascunho, filho de F29, sem merge em
`main`/`dev` e sem deploy. Nenhuma homologação operacional.
