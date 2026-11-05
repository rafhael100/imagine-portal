# PORTAL-TEC-D03 — Modelo de dados, legado e estratégia de migração (v0.1)

**Data:** 05/11/2026  
**Projeto:** Portal Imagine — PORTAL-F1-00  
**Estado:** **PROPOSTA DOCUMENTAL PARA APROVAÇÃO, NÃO RATIFICADA**. O usuário autorizou prosseguir ao próximo passo do planejamento, mas **não aprovou** o modelo físico, as opções D03-MIG ou qualquer programação.  
**Base:** `rafhael100/imagine-portal`, `main` em `50b683a499e674641df5749051f3d05f7ac3f627` (referência consultada).  
**Branch de trabalho:** `docs/portal-f1-00-2026-11-05`. Somente documentação; `main`, VPS e dados permanecem intactos.

> **Atualização normativa em 05/11/2026:** o responsável respondeu **“sim”** às três deliberações da seção 7: **D03-MIG-B aprovada**, **modelo lógico direcional aprovado** e **migração de dados reais condicionada a inventário, proteção, reconciliação e autorização específica**. Ver [registro formal de aprovação D03](PORTAL-TEC-D03-APROVACAO-D03-MIG-B-MODELO-LOGICO-2026-11-05.md). As expressões “proposta/não ratificada” e perguntas no corpo abaixo registram o estado **anterior** à ratificação; não encerram desenho físico, segurança ou liberação de código.

## 1. Objetivo e decisões que limitam o desenho

Preparar a transição do legado Node.js/SQLite (0.18.2) para a **arquitetura Laravel + PostgreSQL já aprovada em PORTAL-TEC-D01**, sem tratar código legado como decisão normativa. Respeitar **logins humanos independentes do Portal e do CRM**, conforme PORTAL-TEC-D02. O Portal é autoridade de contratos, pedidos e elegibilidade comercial de benefícios; o CRM é autoridade de seus próprios usuários, assinaturas, leads e operações. As APIs técnicas autenticadas entre serviços não geram login único.

**Princípios:** cada cliente pode possuir múltiplas empresas/negócios sob controle de acesso expresso; cada empresa pode contratar vários serviços, cada serviço tem contrato e ciclo próprios; o cliente não perde contratos e histórico ao trocar usuário responsável; sites elegíveis podem ter vínculos distintos com contas CRM próprias, sob autorização formal e versionada. Não inferir relação de tenant por e-mail/CNPJ nem partilhar banco entre Portal e CRM.

## 2. Achados factuais do legado (leitura de código, não auditoria exaustiva)

| Fonte existente | Constatação verificável | Limitação para arquitetura nova |
|---|---|---|
| `server/store.mjs` | `DatabaseSync` de `node:sqlite`; `users`, `sessions`, `products`, `orders`, `maintenance`, `payments`, `messages`, `events`, `audit`, `rate_limits` | `orders.user_id` liga pedido a uma pessoa; não define titularidade explícita de empresa/contrato multiempresa |
| `server/account.mjs` | `account_profiles` por usuário e cadastro fiscal consultado em separado | Perfil de contato não equivale à entidade contratante; preservar distinção pessoa/empresa |
| `server/team.mjs`, `server/task-management.mjs`, `server/manual-tasks.mjs` | Equipe, perfis, tarefas manuais e automáticas, históricos | Modelar escopo interno Imagine, atribuições e permissões sem acesso cruzado a clientes |
| `server/briefing.mjs`, `server/production.mjs` | Materiais e arquivos por pedido; versões, estágios, prévia, ajustes e eventos de produção | Não misturar aceite de versão com publicação verificada ou entrega oficial |
| `server/order-ops.mjs` | Reclamações, eventos de atendimento | Precisam ser ligadas ao recurso/empresa apropriados, não apenas a um pedido do usuário |
| `server/recurring-billing.mjs`, `server/fiscal.mjs` | Tokens, contratos, jobs, operações financeiras, notas e histórico fiscal com provedores antigos | Regras comerciais antigas coexistem no código; não migrar cobrança ativa por suposição ou habilitar gateway automaticamente |
| `server/clients.mjs` | Diretório de clientes baseado em `users` com `role='client'` e pedidos por usuário | Não representa, por si só, estrutura organização–membros–contratos necessária |
| `tests/`, `.github/workflows/test.yml` | Testes e automação Node 22/24 declarados no repositório | Não constitui homologação da nova arquitetura, do modelo multiempresa ou de migração de dados |

**Limite de evidência:** nenhum inventário de bancos reais, volume de usuários, contratos vigentes, comprovantes ou uploads foi realizado nesta etapa; não afirmar que o Portal legado está vazio ou que tudo pode ser apagado. As referências históricas de R$ 990, cobrança D0/D30/D60, R$ 79,90 etc. **não** devem ser transpostas automaticamente para novos contratos. Vigora a política comercial posterior de Pix à vista 5%, cartão até 3×, Pix Automático 5%, cartão recorrente sem desconto Pix, com valores finais por produto/campanha sujeitos a revisão.

## 3. Modelo lógico proposto (NÃO representa migrations SQL aprovadas)

| Domínio lógico | Entidades/capacidades propostas | Responsabilidade e integridade |
|---|---|---|
| **Identidade Portal** | `portal_users`, `portal_sessions`, `auth_factors`, `recovery_events` | Acesso somente ao Portal; credenciais, MFA e recuperação próprios. Definição precisa depende D02-SEC |
| **Empresas e acesso** | `customer_organizations`, `organization_memberships`, `organization_roles`, `membership_audit` | Pessoa pode participar de mais de uma organização, por convite/aceite e permissões explícitas; contexto ativo não se deduz de e-mail/CNPJ |
| **Contratantes e cadastro fiscal** | `billing_parties` (PF/PJ conforme produto e instrumento), `organization_billing_links`, `party_contacts`, `party_documents` | Titular financeiro/contratual não é necessariamente o usuário de login nem idêntico à organização operacional; CPF/CNPJ e documentos protegidos |
| **Produtos e condições** | `catalog_products`, `product_versions`, `price_books`, `campaigns`, `offer_snapshots` | Valores e escopos administráveis, contratos antigos preservados; cálculo fechado e versionado no aceite, sem reprecificar retroativamente |
| **Contratação** | `quotes`, `orders`, `order_items`, `contracts`, `contract_versions`, `legal_acceptances` | Organização contratante, responsável autorizado, escopo, versão documental e evidências de aceite; pedido não equivale a pagamento |
| **Serviços e benefícios** | `service_instances`, `service_entitlements`, `benefit_grants`, `benefit_transitions` | Instância por serviço/projeto e contrato; benefício CRM Básico por elegibilidade efetiva e conta exata, sem conceder sessão CRM |
| **Sites/produção** | `site_projects`, `briefing_versions`, `private_files`, `preview_versions`, `approval_evidence`, `publication_proofs`, `delivery_records` | Manter separados aceito, publicado/verificado, entregue, operacional e recuperado; aceites imutáveis por versão |
| **Integrações CRM** | `site_crm_links`, `authorization_revisions`, `integration_commands`, `integration_receipts`, `integration_audit` | IDs externos e escopos de autorização versionados; **não** copiar leads ou credenciais CRM nem consultar seu banco diretamente |
| **Financeiro e fiscal** | `billing_schedules`, `charges`, `payment_transactions`, `financial_reconciliation`, `fiscal_documents` | PSP e emissor são fontes dos fatos respectivos; cartão sensível e segredos fora do modelo; assinaturas CRM pagas ficam no CRM |
| **Relacionamento** | `support_tickets`, `ticket_messages`, `work_items`, `assignment_history` | Chamados por organização/serviço, controle de acesso e equipe interna; mensagem não executa comando automático |
| **Auditoria/integração** | `audit_events`, `outbox_events`, `inbox_deduplication`, `service_incidents` | Idempotência, correlação, trilha minimizada e autenticação de serviços; não transformar n8n em fonte de verdade |

**Convenção:** nomes entre crases são **candidatos lógicos**, não autorização de criar tabelas, migrations, colunas nem relações definitivas.

### 3.1. Relações e invariantes obrigatórios para especificação

1. `PortalUser N:N CustomerOrganization` via membership explícita e revogável. Um e-mail global no Portal não implica vínculo automático com outra empresa nem usuário CRM.
2. `CustomerOrganization 1:N ServiceInstance`; cada instância aponta para **um contrato/versão aplicável**, oferta aceita, titular financeiro e histórico próprio. Uma empresa pode contratar diferentes tipos de serviços.
3. `Contract / Order / Payment` são entidades distintas; preço aceito é snapshot, parcelamento por cartão não equivale a três cobranças Pix programadas.
4. `SiteProject` pertence à organização/serviço autorizado. Um cliente que contrate outro site deve escolher, conforme regras vigentes, vínculo a conta CRM existente ou nova conta; não presumir mesmo CRM para todos os sites.
5. `SiteCrmLink` registra identidade estável do site, CRM destino, versão da autorização, marcos de ativação/revogação e quem autorizou. Troca por chamado tem trilha e **não** migra automaticamente histórico de leads ou backlog para novo CRM.
6. Portal pode apresentar status autorizado de vínculo/benefício, mas **não** é banco de armazenamento de leads. APIs principal e contingência de recepção durável permanecem separadas do painel.
7. Eventos e evidências de aceite de versão são append-only ou equivalentes auditáveis; nova alteração material exige nova versão e decisão, não alteração silenciosa da prova antiga.
8. Perfis internos Imagine e membros de clientes não compartilham escopo por conveniência; acesso excepcional deve ter mandato, prazo, justificativa e registro.
9. Toda entidade de titularidade privada precisa de chave de escopo validada na aplicação e protegida por integridade transacional. Projetar chaves únicas e FKs capazes de evitar referência entre organizações indevidas; avaliar RLS PostgreSQL em conjunto com autorização de aplicação, sem presumir que RLS isolada basta.
10. Não criar dependências de sessão entre Portal e CRM, nem de disponibilidade do Portal para gravação durável de leads.
11. Links externos a Performance AI/Universidade serão contratos/identificadores de serviço próprios, sem introduzir SSO agora; regras de elegibilidade/contrato desses produtos são independentes.

### 3.2. Exemplo de jornada alvo, ilustrativa

Uma pessoa tem sua conta individual no **Portal** e é autorizada por duas organizações: **Empresa A** e **Empresa B**. Em cada contexto, vê apenas contratos, projetos, solicitações e pagamentos autorizados daquela empresa. A Empresa A possui **uma Onepage e uma Landing Page**; elas podem ter vínculos distintos com organizações CRM autorizadas. A Empresa B possui um serviço independente, sem acesso aos dados da A. Ao clicar em **Abrir CRM**, o usuário entra no **login próprio do CRM**, sem usar a sessão do Portal. Essa jornada é **exemplo de modelagem, não demonstração implementada**.

## 4. Opções de migração a deliberar

### D03-MIG-A — Conversão incremental do código legado (não recomendada como alvo principal)

Migrar progressivamente a aplicação Node/SQLite para o novo desenho, mantendo compatibilidades temporárias. Risco: transportar dependências estruturais `orders.user_id`, estados legados, regras comerciais substituídas e acoplamento financeiro para Laravel/PostgreSQL. Só tem sentido se houver exigências demonstradas de continuidade operacional, dados e custo/risco favoráveis.

### D03-MIG-B — **Recomendada: backend Laravel/PostgreSQL novo e reaproveitamento seletivo**

Criar futuramente a aplicação-alvo com arquitetura limpa e entidades aprovadas; preservar do legado **requisitos ainda válidos**, interface quando compatível, testes de comportamento reaproveitáveis e evidências históricas. A existência de dados reais, quando comprovada, aciona **migração controlada por mapeamento, validação, reconciliação, reversibilidade e aceite**, sem sobrescrever o legado. Não portar automaticamente tabelas/credenciais/financeiro do SQLite.

**Recomendação submetida à aprovação:** **D03-MIG-B**, mantendo explicitamente a obrigação de investigar o legado e preservar dados reais/documentos/contratos válidos. A aprovação de **D01-B Laravel/PostgreSQL não significa aprovação automática de D03-MIG-B**.

## 5. Plano documental de migração, condicionado à existência de dados

| Fase | Atividade prevista | Evidência de saída exigida |
|---|---|---|
| **M0 — inventário sem escrita** | Confirmar se existem instâncias em uso e onde; inventariar bancos, clientes, pedidos, pagamentos, arquivos, contratos, recibos, índices e datas, sem acessar dados pessoais desnecessários | Inventário autenticado de origem/versões/volumes/responsável; se sem dados, evidência fundamentada |
| **M1 — classificação** | Distinguir dados fictícios, legado contratual real, informação sensível, credenciais, tokens, logs e backups; identificar obrigações legais e de retenção | Matriz de tratamento, acesso e não migração |
| **M2 — mapeamento** | Mapear entidades/identificadores SQLite → novas entidades; resolver `user_id` x organização contratante; definir chaves externas CRM sem fusão automática | Dicionário fonte-destino e tratamentos de exceção |
| **M3 — prova de migração** | Em ambiente isolado e **somente após autorização**, executar simulações sobre dados fictícios ou conjuntos devidamente autorizados e protegidos | Relatório de reconciliação por contagem, totais, versões, anexos, hashes, acessos e falhas |
| **M4 — corte/aceite** | Planejar janela de transição, exportação, verificação, cópia de segurança independente, restauração, monitoramento e responsável humano | Plano de transição e aceite específico, sem acionamento automático |
| **M5 — legado preservado** | Somente após comprovação jurídica/técnica, decidir tempo de guarda, restrições de acesso e eventual desativação | Evidência de cumprimento de retenção e autorização de descarte |

Sem afirmar que existe produção ativa, nem que não existe. **Nenhuma fase M3–M5 está autorizada agora.**

## 6. Segurança, armazenamento e qualidade — critérios a documentar antes da implementação

- **Isolamento:** toda consulta de dados de cliente filtrada e validada pelo contexto da organização e autorização do ator; negar por padrão; testar tentativa de acesso cruzado na fase de desenvolvimento autorizada.
- **Integridade:** chaves, unicidade condicionada a escopo, transações, integridade de valores, estados e versionamento otimista; índices e RLS precisam de desenho técnico específico.
- **Documentos/arquivos:** armazenamento privado com referência à organização/serviço, autorização de upload/download, antivírus/limites quando aplicável; não versionar arquivos de clientes no GitHub.
- **Credenciais e segredos:** nenhuma senha CRM no Portal; nenhum número completo de cartão/CVV no banco; tokens de PSP, se houver no futuro, com guarda autorizada e proteção específica; segredos fora do código.
- **Tempo e dinheiro:** timestampts com fuso explícito e apresentação `America/Sao_Paulo` quando aplicável; valores monetários com precisão fixa, sem float para cálculo financeiro; regras vigentes por versão/competência.
- **Auditoria:** separar fatos oficiais de comandos, tentativas e webhooks; idempotência, evidências, retenções minimizadas e contestações.
- **Backup:** PostgreSQL Portal, objetos/documentos do Portal e sites publicados têm políticas distintas do CRM; não restaurar dados expirados de fila de lead por efeito lateral.
- **Segurança do domínio fiscal:** PF/PJ conforme contratação efetivamente autorizada e campos necessários; não impor CNPJ obrigatório a todos os usuários por inferência de uma regra do CRM.
- **Critérios de teste futuros:** matrizes de permissão e empresa, duplicidade/replay, migração com e sem dados, conservação de aceites e financeiro, isolamento de provedores e contratos; **nenhum teste foi executado nesta etapa**.

## 7. Perguntas de decisão e limites exatos

**Para aprovação do responsável nesta D03:**
1. Aprovar a opção **D03-MIG-B**: novo backend Laravel/PostgreSQL com reaproveitamento seletivo, sem portar automaticamente a base Node/SQLite?
2. Aprovar **somente como modelo lógico direcional** as separações entre usuário, organização, entidade contratante, serviços, contratos, site, vínculos CRM, financeiro e auditoria, condicionando nomes físicos/RLS/migração ao detalhamento posterior?
3. Ratificar que a migração operacional de qualquer dado legado real só ocorrerá após inventário, mapeamento, plano de proteção/recuperação e autorização específica?

**Ainda não aprovados por esta proposta:** implementação, modelo físico SQL, política MFA/RBAC, versões de Laravel/PHP, frontend, PSP, faturamento real, acesso a dados, cronograma de migração, custos, hospedagem, backup contratado, publicação, CI operacional, deploy e SSO com qualquer produto.

## 8. Gates e vínculos

- **D01:** alternativa B Laravel/PostgreSQL **APROVADA**.
- **D02:** logins independentes Portal × CRM **APROVADOS**; detalhamento IAM/MFA/RBAC aberto.
- **D03:** **PROPOSTA PRESENTE, NÃO RATIFICADA**; D03-MIG-B recomendada.
- **D04 e F1-01:** pendentes; nenhuma autorização de programação ou testes.
- **P0-DOC01 a P0-DOC14 do Imagine Sites**, P0-DOC03/RV-CT, RF-PEND-01, RF-PEND-04 e bloqueios CRM seguem abertos; não confundir recorte do Portal com fechamento de integração.
- **AGI-11-RB01** permanece desabilitado.
- **`main` e VPS:** sem mudanças pela elaboração deste instrumento.

## 9. Referências

- [PORTAL-F1-00](PORTAL-F1-00-PREPARACAO-DESENVOLVIMENTO-CONTROLADO-v0.1-2026-11-05.md)
- [PORTAL-TEC-D01](PORTAL-TEC-D01-APROVACAO-ALTERNATIVA-B-LARAVEL-POSTGRESQL-2026-11-05.md)
- [PORTAL-TEC-D02](PORTAL-TEC-D02-LOGINS-INDEPENDENTES-PORTAL-CRM-DECISAO-2026-11-05.md)
- [Fechamento P0 Sites/Portal/CRM](../imagine-sites/P0-FECHAMENTO-DOCUMENTAL-01.md)
- [AUT-P01–P10 e contratos técnicos RV-CT](../imagine-sites/P0-DOC03-RV01-CONSOLIDACAO-AUT-P01-P10-2026-11-04.md)
- [Política comercial vigente no CRM](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-POLITICA-VIGENTE-PIX-05-CARTAO-3X-2026-11-03.md)
- Código legado na `main`: `server/store.mjs`, `server/account.mjs`, `server/clients.mjs`, `server/team.mjs`, `server/briefing.mjs`, `server/production.mjs`, `server/order-ops.mjs`, `server/fiscal.mjs`, `server/recurring-billing.mjs`, `tests/`.

**Situação atualizada em 05/11/2026:** D03-MIG-B e modelo lógico direcional **aprovados documentalmente** com migração real sujeita a autorização específica; **não iniciar o Codex antes de aprovação expressa do recorte F1-01**. Detalhes físicos e gates P0 continuam abertos.
