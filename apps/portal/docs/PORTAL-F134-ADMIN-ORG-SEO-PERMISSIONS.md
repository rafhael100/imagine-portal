# Portal F134 — controle real de permissão administrativa por organização

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação da [F133 #878](https://github.com/rafhael100/imagine-portal/pull/878).

## Objetivo

A F132/F133 validou o login administrativo com senha, TOTP e provisionamento seguro. Isso **não concede automaticamente acesso às empresas atendidas**. A F134 introduz uma autorização independente por organização para uma única operação de leitura técnica, `seo.view`, sem conexão a serviços contratados, conta Google ou dados reais de clientes.

## Implementação

- `portal_admin_org_grants`: tabela PostgreSQL com vínculo ao administrador, referência opaca da organização, permissão fixa `seo.view`, estado ativo/revogado e expiração. O banco recusa estados inválidos, e nenhuma concessão é criada automaticamente.
- `PortalAdminOrganizationSeoGate`: usa **exclusivamente** o ID real do usuário autenticado no guard `portal-admin`, a prova recente de MFA na sessão, o estado ativo da conta e a concessão exata no banco.
- A organização na rota é apenas um **alvo de busca**. O navegador não pode escolher o usuário, a permissão, o estado do grant ou a origem do vínculo. Concessões inativas, expiradas, pertencentes a outro administrador ou inexistentes resultam em **404**, sem confirmação de existência da empresa.
- A rota `/portal-admin/seo-review/{organization}` é registrada **somente quando a autenticação administrativa está habilitada**. Exige também o middleware de MFA.
- A tela contém apenas a mensagem de prontidão técnica e os avisos de que ainda não existem dados liberados. Não mostra identificadores da empresa, nomes, relatórios SEO, GSC, Analytics, leads ou clientes.

## CI real

Na matriz PostgreSQL 18 PHP 8.3/8.4:

1. Administrador sem MFA não acessa a rota, mesmo com uma concessão ativa.
2. Administrador autenticado e com grant ativo pode visualizar somente a página interna sem dados.
3. Concessão expirada, revogada, inexistente ou manipulada por parâmetros na URL resulta em 404.
4. Revogar o vínculo no banco derruba o acesso imediatamente, sem precisar encerrar a sessão.
5. Uma segunda conta administrativa, mesmo com MFA válido, **não herda a concessão da primeira**.
6. Desativar o administrador encerra sua elegibilidade de acesso.
7. As rotas de cadastro público, CRM, OAuth e dados de clientes permanecem fechadas.

## O que não está comprovado

O identificador de organização e o grant são registros **criados em CI**, não uma prova de que a empresa existe no cadastro contratual da Imagine, está com serviço ativo, autorizou um administrador ou é proprietária de uma conta GSC. A tabela ainda não possui FORCE RLS operacional com papel não-bypass e não valida a origem dos grants por administrador real.

Antes da liberação de quaisquer dados: implementar emissor confiável de organização e vínculo contratual, concessão e revogação auditadas, RLS/DBA, token de sessão com MFA e papéis separados, consentimento/OAuth Google, auditoria AppSec/LGPD e staging VPS.

**A F134 não habilita visualização de informações reais de clientes.**

**ReleaseStatus: HOLD_VERIFIED_ORGANIZATION_SERVICE_ENTITLEMENT_RLS_AND_STAGING.**
