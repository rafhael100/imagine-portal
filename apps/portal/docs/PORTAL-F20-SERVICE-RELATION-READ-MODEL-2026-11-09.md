# PORTAL-F20 — Projeção ilustrativa de serviços ↔ solicitações/contratos 0..N

**09/11/2026. Etapa Laravel/PHP, somente domínio em memória e interface Blade. Sem banco de clientes.**

## Objetivo

No F17, o resumo de demonstração foi criado com **1 serviço : 1 solicitação : 1 contrato**, uma hipótese válida **apenas para aquelas três fixtures**. Em operação real um serviço pode ter nenhuma, uma ou diversas solicitações e versões/documentos contratuais. Não transportar a cardinalidade F17 para o banco.

F20 prepara essa separação sem abrir novas rotas, leituras SQL, entitlement ou contratos comerciais.

## Entrega

- `DemoServiceRelationProjector`: recebe serviços de acompanhamento, solicitações e contratos **artificiais** existentes, identifica 0-N relações por serviço e produz uma página ordenada por referência.
- Toda a entrada é validada **antes da paginação**: arrays sequenciais limitados a 50 serviços, 200 solicitações e 200 contratos; referências únicas; owner de solicitação/contrato correspondente à organização esperada; nenhuma referência órfã; rótulo de serviço contratual coerente.
- Cursor opaco de laboratório baseado em referência `DEMO-SVC-*`, limite de página 1..20, ordenação lexical estável e rejeição fail-closed de cursores desconhecidos ou referências inválidas. O cursor **não é assinado, autenticado, confiável nem destinado a APIs reais**.
- `DemoServiceRelationPage`: retorno `readonly`, página com totais globais calculados somente após todas as validações, `nextCursor` e razão fechada de bloqueio. Em erro, descarta **todos** os totais/linhas/cursores, sem exposição parcial.
- `DemoServiceRelationPreview`: adaptador local das três listas fictícias já existentes. O painel `portal.demo.services` apresenta uma lista descritiva e reforça que não comprova contrato ativo, assinatura nem acesso.
- PHPUnit com cenários 0, 1 e N, limites, paginação entre serviços em ordem determinística, duplicações, IDs de serviço inexistentes, divergência de rótulo, organização estrangeira mesmo fora da página selecionada, entradas malformadas, ausência de sessões, banco e rotas.

## Garantias que NÃO são fornecidas

- O ID da organização é **fornecido pelo chamador** e não prova tenant nem identidade autenticada. `ServiceFollowUp` não tem organization ID; trata-se de catálogo estático fictício e não modelo persistente.
- A contagem de contratos `DemoContractRecord` não equivale a assinatura, pagamento, direito comercial, plano ativo, faturamento ou conclusão jurídica.
- A contagem de solicitações `ServiceRequestSnapshot` não comprova abertura de ticket real, atendimento ou SLA.
- F20 não determina elegibilidade da Universidade. `grantsUniversityBenefit()` é sempre false.
- Mesmo retornos `demo_prepared` não autorizam exibir dados reais, cobrar, provisionar, emitir tokens, aplicar RLS ou acessar motores externos. Métodos `mayExposeCustomerData()`, `authorizesServiceAccess()` e `authorizesContractOrPayment()` são sempre false.
- O cursor é exclusivamente local, não seguro contra adulteração por clientes. Integração operacional exigirá consultas SQL parametrizadas, autorização tenant-aware em fonte confiável, ordenação estável de snapshots, paginação segura, expiração/assinatura se necessária, ACL, auditoria e avaliações dos efeitos de outbox/cache.
- O backend segue **Laravel/PHP**; Node.js só é usado no CI de componentes legados, ferramentas e laboratórios Cloudflare.

## Próxima etapa e gates

1. Consolidar contratos de leitura de origem confiável e seu contexto de identidade, ownership, tenant e permissões antes de criar endpoint real.
2. Quando houver fonte SQL e autenticação implementadas, testar casos de fonte maliciosa, identidades expiradas, revogação, RLS e concorrência, sob PostgreSQL efêmero e sem clientes.
3. Só depois avaliar catálogos administráveis e contratos comerciais reais para cada serviço independente.
4. **Não** integrar dados do CRM, Sites, Performance ou Universidade via banco compartilhado ou SSO presumido.

**Estado: PR DRAFT, sem merge em DEV/main, VPS, Cloudflare, compras, migrações, credenciais ou dados reais.** F20 é filho de F19 → F18 → F17 → F16 → F15 → F14, e não homologa P0/SEC-R03/D02/D03/RLS.
