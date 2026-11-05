# PORTAL-TEC-D01 — Decisão de stack do backend do Portal Imagine

**Data:** 05/11/2026  
**Projeto:** Portal Imagine  
**Etapa:** PORTAL-F1-00 — preparação do desenvolvimento controlado  
**Estado da decisão:** **APROVADA EXPRESSAMENTE — ALTERNATIVA B**  
**Fonte da aprovação:** após apresentação das alternativas A (evolução Node.js) e B (Laravel + PostgreSQL), responsável respondeu: **“sim aprovado o B”**.  
**Natureza:** aprovação **arquitetural/documental**, não autorização de programação, migração, compra, testes ou implantação.

## 1. Deliberação aprovada

Adotar **Laravel para o backend** e **PostgreSQL como banco de dados** do novo Portal Imagine, no âmbito do desenvolvimento futuro, com:
- aplicação, banco, credenciais, permissões, dados e ciclo de implantação **próprios do Portal**, independentes do CRM Imagine;
- integração futura com CRM e Imagine Sites **por contratos e APIs autenticadas**, com autoridade de estados preservada por serviço, sem consultas diretas ao banco do CRM;
- execução de Portal e CRM em **servidores/ambientes próprios e separados em produção**, conforme fronteiras anteriormente aprovadas;
- ambiente **central de desenvolvimento Leaseweb** ainda apenas candidato/documental, podendo hospedar contêineres de projetos distintos exclusivamente com isolamento efetivo; não significa VPS contratada;
- uso do legado Node.js 0.18.2 como **fonte de inventário/referência**, não como stack final nem autorização de migração automática. Componentes de UI, contratos, fluxos e testes só serão reaproveitados após análise técnica de compatibilidade e segurança.

A padronização Laravel/PostgreSQL com o CRM é tecnológica; **não** autoriza compartilhar banco, sessões, identidade, dados ou privilégios entre produtos.

## 2. Alternativas e limites

- **Alternativa A — evolução do Node.js/SQLite:** não selecionada como arquitetura-alvo do novo Portal.
- **Alternativa B — Laravel + PostgreSQL:** aprovada como **arquitetura-base**.
- A decisão **não** escolhe framework de frontend, desenho físico final, versão do Laravel/PHP, modelo de tenants e RLS, políticas de autenticação, MFA/SSO, provedor financeiro/fiscal, topologia operacional, custos, contratos de integração ou mecanismo de migração de dados.
- Se forem necessários componentes auxiliares distintos, eles exigirão análise de compatibilidade e autorização conforme o escopo, sem descaracterizar a decisão-base.

## 3. Consequências técnicas a especificar antes do código

1. Modelo de organizações, clientes, contratos, usuários, perfis, permissões e isolamento lógico/físico do Portal.
2. **PORTAL-TEC-D02:** diretriz posterior de **logins e sessões independentes** para Portal e CRM, sem SSO entre eles nesta versão, [aprovada expressamente](PORTAL-TEC-D02-LOGINS-INDEPENDENTES-PORTAL-CRM-DECISAO-2026-11-05.md). Ainda especificar identidade, MFA, sessões, recuperação, convites, RBAC e autenticação de serviços sem misturar credenciais humanas.
3. **PORTAL-TEC-D03:** inventário de dados legados e decisão de migração, preservação de comprovantes e contratos históricos, com homologação própria.
4. Contratos de API/eventos entre Portal, Sites e CRM; garantir que captação de leads não dependa do painel Portal.
5. Backups/recuperação, segredos, observabilidade e custo final por ambiente.
6. **PORTAL-TEC-D04:** cálculos de preços/campanhas/contratos com política comercial vigente; cobranças reais não habilitadas.

## 4. Gates e autorizações preservadas

- **PORTAL-F1-00:** pacote em preparação para revisão.
- **PORTAL-TEC-D01:** alternativa B **APROVADA**.
- **PORTAL-TEC-D02/D03/D04 e demais gates F1-00:** **ABERTOS**.
- **P0-DOC01–P0-DOC14 de Sites/Portal/CRM:** não encerrados por esta decisão.
- **P0-DOC03 / RV-CT relevantes, RF-PEND-01, RF-PEND-04:** estados inalterados.
- **AGI-11-RB01:** rollback automático permanece **DESABILITADO**.
- **F1-01/F1-02+ e Codex:** programação e testes **NÃO AUTORIZADOS** por esta aprovação; exigem instrumento específico, branch de código isolada, escopo verificável e revisão.
- Nenhuma alteração de `main`, VPS, Cloudflare, Asaas, Focus, DNS, credenciais ou dados de produção é autorizada.

## 5. Referências

- [PORTAL-F1-00 — pacote de preparação](PORTAL-F1-00-PREPARACAO-DESENVOLVIMENTO-CONTROLADO-v0.1-2026-11-05.md).
- [P0-FECHAMENTO-DOCUMENTAL-01](../imagine-sites/P0-FECHAMENTO-DOCUMENTAL-01.md).
- [P0-DOC03-RV01 — AUT-P01 a AUT-P10](../imagine-sites/P0-DOC03-RV01-CONSOLIDACAO-AUT-P01-P10-2026-11-04.md).
- [P0-ARQ-01 — fronteiras CRM/Portal/Sites no CRM](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/P0-ARQ-01-topologia-fronteiras-plano-fechamento.md).

**Próximo passo documental proposto:** elaborar PORTAL-TEC-D02 (identidades, sessão, MFA e SSO eventual) preservando o escopo da decisão D01.

> **Atualização normativa (05/11/2026):** a alternativa B desta D01 permanece aprovada. A decisão posterior [D02](PORTAL-TEC-D02-LOGINS-INDEPENDENTES-PORTAL-CRM-DECISAO-2026-11-05.md) estabelece logins humanos **independentes** para Portal e CRM. Não interpretar a referência histórica a SSO ou UX como aprovação de login único.
