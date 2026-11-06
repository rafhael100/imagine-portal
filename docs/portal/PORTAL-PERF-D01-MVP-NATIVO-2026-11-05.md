# PORTAL-PERF-D01 — Imagine Performance AI nativo no Portal (prioridade de MVP)

**Data:** 05/11/2026  
**Origem:** decisão expressa do responsável: "preciso que seja tudo ja no portal, esse formato manual nao dar certo vamos focar no desenvolvimento do que ja falamos".  
**Situação:** **DIRETRIZ DE PRIORIDADE FUNCIONAL APROVADA**, com programação progressiva **somente nos limites já autorizados** para o fast-track Portal; integração/cobrança/contas reais requerem homologação e aprovação específicas.  
**Registro de referência no produto Performance:** [PERF-PROD-D01](https://github.com/rafhael100/imagine-performance-ai/blob/main/docs/performance-ai/PERF-PROD-D01-PORTAL-FIRST-MVP-2026-11-05.md).

## 1. O que não faremos

Não utilizar a prestação de serviço de tráfego **contratada e atendida manualmente fora do Portal** como rota de lançamento dos dois interessados. A Issue #4 do Performance foi encerrada como proposta superada. **As aprovações humanas e a equipe operacional permanecem** como parte dos fluxos internos digitais e auditáveis da plataforma, não como substituto do Portal.

Não migrar Node.js/SQLite ou os dados de teste do protótipo antigo para a nova aplicação como fonte de verdade. Aproveitar apenas requisitos, fluxos e critérios úteis, após análise; usar Laravel + PostgreSQL para o novo Portal, já aprovados em PORTAL-TEC-D01.

## 2. Caminho do cliente: tudo dentro do Portal

`Visitante` → `Catálogo Imagine Performance AI` → `Selecionar Canal Único Google/Meta ou Multicanal` → `Conta/organização do Portal` → `Preço aprovado e proposta versionada` → `Aceite contratual expresso` → `Pagamento via PSP homologado` → `Assinatura/serviço elegível` → `Onboarding (objetivo, mídia, contas e consentimentos)` → `Acompanhamento de campanhas/indicadores` → `Aprovações, chamados, cobranças e cancelamento`.

Operação Ampliada poderá seguir contratação/qualificação especial, mas sempre com jornada e registros do Portal; não ativar automaticamente se as capacidades e valores não estiverem homologados.

O cliente **não precisará usar o CRM Imagine** para contratar ou acompanhar Performance. Login CRM continua independente do Portal (PORTAL-TEC-D02). **Não inferir SSO Portal/Performance**: elaborar contrato seguro de backend/serviço com autenticação de sistema, tenant/empresa/usuário e revisão de permissões; login humano federado só se aprovado posteriormente.

## 3. Responsabilidades e dados

**Portal (Laravel/PostgreSQL próprio):** identidade de usuário e empresa, produto/SKU, capacidades comerciais, preço determinístico versionado, pedido, contratos, aceites, assinatura, status de pagamento, faturas, permissões e experiência de cliente.

**Performance AI (serviço separado):** conta e campanhas de anúncios sob gestão, grupos/conjuntos, cotas operacionais, tarefas, dados de integração autorizados, relatórios, acompanhamento operacional, custos/consumos e políticas de execução/agentes.

**Contrato candidato Portal ⇄ Performance:** vínculo explícito `portal_organization_id` ⇄ `performance_tenant_id`; `contract_version`, `order_id`, `service_id`, estados idempotentes de provisão/suspensão, `correlation_id`, ações autorizadas pelo backend e dados mínimos por usuário/tenant. Sem banco compartilhado, sem senha/token de usuário do CRM e sem agentes que criem despesas sem aprovação.

**Mídia Google/Meta:** verba paga diretamente ao provedor pelo titular da conta. Cliente pode solicitar avisos de recarga via Portal após requisitos/integrações homologados, mas **não prometer Pix/boleto automático por API** se não existir ou não for viável.

## 4. Escopo para o primeiro MVP a desenvolver

### P-01 — Fundação e organização
Aproveitar a fundação Laravel em `dev/fast-track-portal-2026-11-05` e a tarefa **F1-01-B-A já aprovada** para classes de domínio/organização e testes fictícios; não deslocar nem editar o PR #11 em andamento. Identidade real, migrations, RBAC persistente e banco dependem dos gates atuais.

### P-02 — Vitrine e contratação simulada do Performance
Preparar interface interna e contratos de domínio para catálogo `Canal Único Google`, `Canal Único Meta`, `Multicanal`, `Operação Ampliada` com dados artificiais, preços `N/D`/configuráveis, canais, cotas e add-ons; estado `not_for_sale` até tabela/contratos aprovados. Ofertas de Sites e CRM não alteram seus preços. UI e checkout simulados somente após autorização de escopo de código específica.

### P-03 — Pedido, contrato, aceite e pagamento
Modelo determinístico, versão do contrato, pedido idempotente, aceite explícito e estados de pagamento simulados. **Não ligar Asaas/PSP, gerar cobranças, NFS-e ou provisionar recursos verdadeiros** antes de homologar taxas, tributação, credenciais, segurança e contratos.

### P-04 — Habilitação e onboarding via integração mockada
Contrato Portal → Performance com mocks locais: provisionamento de `tenant`, marca, canal, conta de anúncio, autorização e briefing; impedir acesso a tenant alheio e ativação não paga; logs e tratamento de repetição/reconciliação.

### P-05 — Área do cliente Performance no Portal
Mostrar status do contrato, onboarding, campanhas, indicadores simulados identificados, aprovações e chamados. Não afirmar dados ou recursos Google/Meta/CRM em tempo real enquanto conexões reais não estiverem homologadas. Suporte e relatórios reais exigem capacidades verificadas.

### P-06 — Homologação de produção
Revisar fluxos end-to-end, RBAC/MFA, contratos, preço e fiscal/PSP, confiabilidade, consentimentos, APIs Google/Meta, logs, backup, incidentes e go-live por decisão expressa. **Não há data prometida ou autorização de clientes reais.**

## 5. Regra de desenvolvimento paralelo

Dois repositórios independentes: `rafhael100/imagine-portal` e `rafhael100/imagine-performance-ai`. PRs separados para cada um. Priorizar **interfaces tipadas e mocks**, não acoplamento direto de bancos/cookies/sessões.

**Portal:** branch `dev/fast-track-portal-2026-11-05`; PR #11 de fundação draft; tarefa F1-01-B-A (org/membership) já autorizada em `docs/portal/PORTAL-F1-01-B-A-TAREFA-CODEX-APROVADA-2026-11-05.md` na branch documental, com limites estritos. **Este documento NÃO altera o escopo autorizado do Codex B-A.**

**Performance:** branch `dev/fast-track-performance-2026-11-05`; F1-01 define stack, PAR-02 mapeia segurança, PAR-03 prepara testes; nenhuma escolha de stack definitiva por inferência.

**P-02+ ainda demandam aprovação específica de programação**, mas podem ser decompostas em issues e critérios agora. Nenhum código em `main`, deploy, gastos, credenciais ou teste com cliente real.

## 6. Critério de pronto para os dois primeiros clientes

Nenhum cliente pode ser chamado de contratado operacionalmente antes de: preço/contrato aprovados e aceitos pelo titular, autenticação/empresa seguras, PSP real e emissão fiscal homologados, onboarding real autorizável, Performance integrado efetivamente, pagamento do anunciante à mídia separado, suporte e governança validados, testes E2E aprovados e autorização de produção.

**Próxima ação objetiva:** encaminhar ao Codex a F1-01-B-A já autorizada no Portal e a F1-01/PAR-02/PAR-03 do Performance, enquanto preparamos o PR seguinte de catálogo/checkout **simulado**, que requer gate específico.
