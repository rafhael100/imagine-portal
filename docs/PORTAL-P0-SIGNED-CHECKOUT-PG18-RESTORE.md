# Imagine Portal P0 — prova de restauração PostgreSQL18 com isolamento SQL assinado

## O que foi implementado

Sobre a [PR #748](https://github.com/rafhael100/imagine-portal/pull/748), verificada em **11/11 workflows**, esta candidata acrescenta o ensaio de restore da linha de segurança #742, mantendo a oferta versionada, os pedidos individualizados, o pós-venda e os componentes Asaas desligados.

- Após a jornada Chromium com vários clientes fictícios, transaciona-se um backup `pg_dump -Fc` do banco PostgreSQL18 **descartável** para um segundo banco criado no mesmo contêiner de CI.
- `pg_restore --exit-on-error` falha fechado. Compara-se a quantidade de clientes, pedidos, briefings e eventos antes/depois; exige pelo menos dois clientes distintos, três pedidos persistidos, um briefing e sete eventos (massa sintética efetivamente criada pela suíte Chromium desta candidata).
- Conferem-se `FORCE RLS` nas tabelas clientes, pedidos e eventos, políticas de propriedade assinada e conteúdo imutável da oferta.
- A consulta de regressão é realizada com **conexão SQL real do papel de login limitado** `purchase_rehearsal_a`, não por `SET ROLE` de um administrador; a identidade sem MAC válida deve enxergar zero clientes, pedidos e eventos.
- Uma função de limpeza elimina a base de restauração do CI independentemente do resultado. A base sintética original continua até o fim da rodada para verificar o restart e os screenshots.
- Adiciona-se o teste offline de segurança da configuração Docker Compose/arquivos secretos para o futuro staging privado da Leaseweb. O teste de CI **não inspeciona nem modifica a VPS**.

## Limites e critérios de liberação

Este é um ensaio dentro de um **único contêiner descartável**, não prova recuperação real de desastres, backup remoto, histórico, criptografia offsite, retenção ou RTO/RPO na Leaseweb. Estes testes não manipulam arquivos de clientes reais. Os arquivos `.private` gerados na rodada de CI são destruídos depois de executar os testes e não são artefatos compartilhados.

Antes de produção, é necessária uma nova base de homologação isolada no servidor, restore de backup real e verificação de migração/rollback, chaves em vault, HTTPS e IAM/MFA, PSP Asaas Sandbox, contrato/consentimento vigente e autorização independente de serviços Portal→Sites/CRM.

**DRAFT / NO-GO / sem merge ou deploy automático, sem cobrança ou publicação.**
