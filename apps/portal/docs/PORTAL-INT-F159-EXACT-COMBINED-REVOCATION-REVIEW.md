# PORTAL-INT-F159 — Candidata conjunta com isolamento e concorrência de revogação

Esta revisão reúne, em um **único commit Git de dois pais**, o teste de isolamento de organizações F157 e o teste de transações simultâneas F158. Nenhuma migração de produção ou permissão foi adicionada nesta integração.

## Proveniência reproduzível

- Ancestral comum: F156 `f87f9e97df4a44b4838fcf59e52474a6d8ebe247`;
- Parent F157 (revogação independente entre duas empresas): `82272f132bf4fe93e107575acd9e81979c21bb30`;
- Parent F158 (concorrência de grants/status): `aaa69e8bcf162f440af5d325d7ba958c68ae26a4`;
- Commit combinado: `5688ae69a01c70bad956f3beeb48e8f2ee8fb631`;
- Árvore Git combinada exata: `7a58422ff36cd2ac28195714b4acf967a905ab5e`.

A comparação de todas as entradas Git encontrou **três novos arquivos F158 exclusivos**, nenhum conflito contra F157. O workflow F159 reproduz a árvore exata com `git merge-tree`, verifica os dois pais e recusa qualquer mudança subsequente em runtime ou migrações: só são permitidos o workflow de proveniência F159 e este documento.

## Evidências da integração combinada

O workflow `portal-int-f159-combined-ci.yml` valida no mesmo SHA de revisão:

1. **Duas empresas**: identidade sintética comum; grant de A altera somente revisão de associação A, suspensão de A altera somente revisão da organização A, mudança no membro B não afeta as revisões de A.
2. **Duas transações simultâneas**: conexão A prende a linha da associação durante inserção de grant; B não consegue alterá-la até commit. Suspensão de organização que sofre rollback não deixa revisão espúria; o próximo commit eleva revisão uma única vez.
3. **PostgreSQL18 + PHP8.3/8.4**: quatro combinações de testes F157/F158 sobre a árvore única, sem políticas RLS ALLOW, com triggers F154/F156 e esquema reversível após limpeza de dados artificiais.
4. **Node24 + Laravel PHP8.4**: testes gerais, dependências fixas, autoridade `DenyingPortalAuthorities` ainda inalterada, exatamente uma rota de healthcheck.

Resultados anteriores das PRs F157 e F158 não substituem resultados da árvore F159. A candidata só é considerada tecnicamente validada quando sua própria CI ficar integralmente verde.

## Gate de segurança

O teste de transação usa contas administrativas de um banco descartável e **não** simula credenciais de cliente nem sua role em staging. A futura fonte Portal real deve demonstrar confiabilidade de emissor, MFA, revogação transacional, isolamento entre tenants, RLS mínimo, logs imutáveis e análises de segurança/LGPD independentes. Esta PR segue **NO-GO** e em draft: não faz merge em `dev`/`main`, não executa migrations na VPS e não habilita qualquer acesso operacional.
