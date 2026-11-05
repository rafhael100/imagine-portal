# PORTAL-TEC-D03 — Ratificação da alternativa D03-MIG-B e do modelo lógico direcional

**Data:** 05/11/2026  
**Status:** **APROVADA EXPRESSAMENTE COMO DIRETRIZ DOCUMENTAL/ARQUITETURAL**.  
**Origem:** o responsável respondeu **“sim”** à pergunta expressa para aprovar **D03-MIG-B**, o modelo lógico direcional apresentado em PORTAL-TEC-D03 v0.1 e a condição de autorização específica para migrar dados reais.
**Escopo:** novo Portal Imagine com backend Laravel + PostgreSQL, logins independentes do CRM e preservação dos contratos/histórico.

> **ESCLARECIMENTO POSTERIOR — 05/11/2026:** o responsável confirmou que o Portal anterior era **somente um protótipo em desenvolvimento**, com dados exclusivamente de teste. **Reaproveitar sobretudo a estrutura de negócio e as ideias de fluxo; não planejar migração automática do banco antigo nem reaproveitamento obrigatório de código.** Ver [D03-R02](PORTAL-TEC-D03-R02-LEGADO-PROTOTIPO-FLUXOS-NEGOCIO-DADOS-TESTE-2026-11-05.md). A condição de inventário mínimo antes de eventual descarte irreversível é precaução, não afirmação de dados reais.

## 1. Decisão ratificada

1. **D03-MIG-B APROVADA:** criar futuramente novo backend Laravel + PostgreSQL, reaproveitando **seletivamente** regras de negócio ainda válidas, componentes compatíveis da interface e testes relevantes do legado Node.js/SQLite. Não portar automaticamente o schema ou as regras financeiras históricas.
2. **Modelo lógico direcional APROVADO:** distinguir usuários e sessões próprios do Portal, organizações e vínculos autorizados de membros, contratantes, catálogo/ofertas, pedidos/contratos/aceites, serviços e projetos, vínculos autorizados a contas CRM, cobrança e documentos fiscais próprios do Portal, suporte e auditoria. O desenho físico, nomes de tabelas, índices, RLS, políticas MFA/RBAC, integração e versões de framework **não** estão homologados pela aprovação direcional.
3. **Preservação obrigatória antes de eventual migração:** caso existam usuários, contratos, faturas, arquivos, pagamentos ou outras informações reais no legado, inventariar previamente as origens e volumes, classificar riscos/privacidade, mapear entidades e IDs, definir backups/restauração, reconciliar e obter **autorização de migração separada**. A decisão não atesta existência ou inexistência de dados de produção.
4. **Separação Portal × CRM preservada:** bancos, credenciais, sessões humanas e autoridades de serviço independentes. A integração por APIs autenticadas e contas de serviço não equivale a SSO, nem permite fusão automática por e-mail/CNPJ.
5. **Autoridade comercial preservada:** ofertas e preços aceitos devem ser versionados e não reprecificados unilateralmente; as regras recentes de Pix e cartão prevalecem sobre contratos-modelo e código histórico, salvo condições legítimas de contratos antigos em vigor.

## 2. Exclusões expressas

A presente decisão **não autoriza programação, testes, execução do Codex, criação da branch de código, migração real, contato a provedores, cobrança, ativação de credenciais, emissão fiscal, modificação de dados, infraestrutura, publicação, merge na main ou deploy**.

**Aprovações anteriores mantidas:** D01-B (Laravel/PostgreSQL) e D02 (logins independentes Portal/CRM). D02-SEC, D04, P0-DOC01–P0-DOC14, contratos técnicos de integração, segurança e F1-01 permanecem abertos. AGI-11-RB01 continua desabilitado.

## 3. Artefatos de referência

- [D03 — proposta base v0.1](PORTAL-TEC-D03-MODELO-DADOS-LEGADO-MIGRACAO-PROPOSTA-v0.1-2026-11-05.md)
- [D01 — escolha Laravel + PostgreSQL](PORTAL-TEC-D01-APROVACAO-ALTERNATIVA-B-LARAVEL-POSTGRESQL-2026-11-05.md)
- [D02 — logins independentes](PORTAL-TEC-D02-LOGINS-INDEPENDENTES-PORTAL-CRM-DECISAO-2026-11-05.md)
- [PORTAL-F1-00 — pacote de preparação](PORTAL-F1-00-PREPARACAO-DESENVOLVIMENTO-CONTROLADO-v0.1-2026-11-05.md)

**Próxima frente:** preparar **PORTAL-TEC-D04** — recorte comercial/financeiro inicial e respectivas interfaces, preservando decisões comerciais vigentes e sem iniciar cobrança/programação.
