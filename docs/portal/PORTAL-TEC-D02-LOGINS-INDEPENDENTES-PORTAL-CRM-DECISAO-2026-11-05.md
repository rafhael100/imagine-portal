# PORTAL-TEC-D02 — Identidades e logins independentes Portal Imagine × CRM Imagine

**Data:** 05/11/2026
**Projeto:** Portal Imagine — pacote PORTAL-F1-00
**Status:** **DECISÃO FUNCIONAL/ARQUITETURAL APROVADA quanto à independência dos logins**; detalhes de MFA, RBAC, modelagem de contas, recuperação, contas de serviço e segurança continuam **ABERTOS**.
**Manifestação expressa do responsável:** "acho melhor o Portal Imagine e o Imagine CRM terem seus logins de forma independente. Podemos posterior pensar nessa estrutura entreo Portal Imagine e a Universodade Imagine e o IMagine Perfomance Ia".
**Tipo:** documentação de decisão. **Não autoriza programação, testes, contratação, implantação, migração, alteração de credenciais, integrações reais ou merge na main.**

## 1. Decisão normativa: independência Portal × CRM

1. **Portal Imagine:** login, credenciais, sessão, recuperação de acesso, políticas de MFA, permissões, ciclo de usuário e revogação próprios do Portal.
2. **Imagine CRM:** login, credenciais, sessão, recuperação de acesso, políticas de MFA, permissões, ciclo de usuário e revogação próprios do CRM.
3. **Sem SSO compartilhado entre Portal e CRM na primeira versão**; autenticar-se no Portal não autentica automaticamente o usuário no CRM nem o inverso. Não compartilhar cookies, senha, hash de senha, token de usuário, sessão ou banco de identidade.
4. A mesma pessoa poderá usar o mesmo endereço de e-mail nos dois produtos, mas isso **não** faz as contas equivalentes nem comprova vinculação/autorização.
5. Não criar vínculos de contas, associação entre empresas, elevação de permissão, provisionamento de acesso humano ou migração de credenciais por semelhança de e-mail, CNPJ ou nome.
6. Credenciais de contas de serviço e autenticação **servidor a servidor** (Portal ↔ CRM e demais serviços) são uma questão distinta de **login de pessoas**. Podem ser planejadas para cumprir os contratos já aprovados, com escopos mínimos, autorização versionada, auditoria e nenhuma permissão de login implícita.
7. Portal e CRM permanecem aplicações, bases de dados, autoridades de estado e ambientes de produção separados. Falha/indisponibilidade de um painel não deve tornar o outro indisponível nem impedir ingresso durável dos leads.
8. Um cliente contratado no Portal pode posteriormente contratar ou usufruir benefício CRM Básico conforme elegibilidade já aprovada, mas o vínculo contratual/integração de sistemas **não altera** o requisito de credenciais e login independentes; início de conta/convite/acesso humano requer fluxo específico autorizado.

## 2. Universidade Imagine e Imagine Performance AI — apenas estudo futuro

- O responsável manifestou **preferência por avaliar posteriormente** integração de identidade/experiência de login **entre Portal Imagine, Universidade Imagine e Imagine Performance AI**.
- **Nenhum SSO, conta compartilhada ou login único foi aprovado** para esses produtos nesta decisão. Não construir dependência técnica pressuposta.
- Mantêm-se, sem revisão implícita, as decisões anteriores de **acesso comercial/experiência pelo Portal** à Universidade e Performance AI e as regras próprias de elegibilidade, contratos, permissões e isolamento de cada produto.
- A futura proposta deverá comparar: acesso via Portal sem compartilhar autenticação; identidade federada com fluxos explicitamente consentidos; ou identidade central — avaliando custo, segurança, disponibilidade, titularidade, desligamento, LGPD, sessões, MFA e contingência.
- A Universidade permanece benefício exclusivo de clientes Imagine elegíveis ativos, conforme aprovações prévias; a discussão de login não permite disponibilizá-la ao público indiscriminadamente.
- Performance AI mantém produto independente, contrato e cobrança próprios, integração CRM opcional; integração futura de login não altera essas premissas.

## 3. Separar integração técnica de login de pessoa — casos obrigatórios

| Caso | Comportamento obrigatório em planejamento |
|---|---|
| Cliente entra no Portal | Apenas sessão do Portal, com permissões do Portal; não gera sessão CRM |
| Cliente entra no CRM | Apenas sessão do CRM; não gera sessão Portal |
| Site concedido com cortesia CRM Básico | Avaliação de elegibilidade/conta de destino pelos sistemas oficiais, sem compartilhar senha nem login de usuário |
| Site com formulário integrado ao CRM | Duas APIs validam autorização do destino/contingência no backend; nenhuma autenticação Portal substitui autorização de captação |
| Cliente quer alterar CRM vinculado | Chamado à Imagine, alteração controlada sem migração automática; conta CRM só com acesso por suas próprias credenciais |
| Portal exibe estado de integração/benefício | Consumir somente APIs e dados autorizados; não ler banco CRM nem impersonar o cliente |
| Desativação de acesso em um produto | Não apagar automaticamente conta e histórico do outro; aplicar ciclo contratual próprio, com transições e eventos autorizados |

## 4. Pendências técnicas ainda não fechadas

- **D02-SEC01:** cadastro/verificação, autenticação e identidade local do Portal; MFA, sessões, dispositivos confiáveis, recuperação, proteção a abuso e auditoria.
- **D02-SEC02:** modelo de empresa/tenant, usuários, perfis, convites, delegações, titularidade e regras de segregação Portal.
- **D02-SEC03:** identidade de serviço Portal×CRM, autorização e consentimento operacional, mecanismos de correlação sem login compartilhado, revogação e falhas.
- **D02-SEC04:** transição e cadastros legados; preservar usuários e contratos somente após levantamento/proposta de migração e aprovação.
- **D02-SEC05:** fronteiras futuras de acesso à Universidade/Performance AI, **sem aprovação de SSO**.
- **D02-SEC06:** UI dos acessos independentes, orientações e links sem tokens reutilizados entre produtos.

Os requisitos acima são itens de especificação, não implementação concluída.

## 5. Gates e escopo de autorização

- **PORTAL-TEC-D01:** B (Laravel + PostgreSQL) aprovada e mantida.
- **PORTAL-TEC-D02:** **DIRETRIZ DE LOGIN INDEPENDENTE APROVADA**. Seu fechamento técnico integral está **PENDENTE** dos itens D02-SEC01 a D02-SEC06.
- **PORTAL-TEC-D03/D04:** continuam abertos.
- **P0-DOC01–DOC14**, P0-DOC03/RV-CT, RF-PEND-01, RF-PEND-04 e demais bloqueios cruzados continuam nos seus estados anteriores.
- **AGI-11-RB01:** rollback automático continua desabilitado.
- **F1-01 e Codex:** sem autorização de programação/testes nessa decisão.
- **main/VPS/ambientes reais:** sem alterações.

## 6. Referências

- [PORTAL-F1-00 — preparação](PORTAL-F1-00-PREPARACAO-DESENVOLVIMENTO-CONTROLADO-v0.1-2026-11-05.md)
- [PORTAL-TEC-D01 — Laravel + PostgreSQL](PORTAL-TEC-D01-APROVACAO-ALTERNATIVA-B-LARAVEL-POSTGRESQL-2026-11-05.md)
- [P0 — bloqueios Sites/Portal/CRM](../imagine-sites/P0-FECHAMENTO-DOCUMENTAL-01.md)
- [P0-ARQ-01 no CRM — fronteiras de serviço](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/P0-ARQ-01-topologia-fronteiras-plano-fechamento.md)

**Próxima frente documental sugerida:** PORTAL-TEC-D03 — levantamento do legado, dados reais/contratos, migração/compatibilidade e estratégia de persistência no PostgreSQL, respeitando login independente.
