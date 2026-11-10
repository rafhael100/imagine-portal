# PORTAL-F15 — Fila de solicitações fictícias e isolamento por empresa

Estado: **somente prévia sintética**, sem integração operacional. Data: 09/11/2026.

## Finalidade

Ampliar o módulo PORTAL-F14 (#112), sem depender da separação técnica do Imagine Sites.
Exibir no futuro, dentro do Portal, solicitações referentes a serviços independentes
(Onepage, Landing Page, Performance) por empresa, com filtros por estado e paginação.

## O que foi desenvolvido

- `RequestInboxPreview`: projeção em memória, sem chamadas a banco, rede ou filas.
- Checagem integral dos dados fictícios por organização e rejeição **atômica**
  de fontes com organizações misturadas — não filtrar depois de obter um lote amplo.
- Rejeição de entradas não tipadas, listas não sequenciais, IDs duplicados,
  paginação inválida e mais de 1000 snapshots de laboratório.
- Ordenação estável por referência opaca, **sem simular cronologia ou SLA**;
  filtro opcional por estado, limite de página 1–50, offset 0–10000.
- Resultado imutável sem registros em casos rejeitados; `mayExposeCustomerData()`
  retorna invariavelmente falso.
- `DemoRequestInbox`: dados de demonstração da empresa fictícia Aurora,
  sem nomes, documentos, cobrança, contas ou registros de clientes reais.
- Visualização em Blade usando o componente de tabela com leitura acessível,
  sem formulários, botões, links externos ou rotas novas.
- Testes para tenant diferente, duplicatas, página vazia, paginação,
  tipagem, XSS indireto pela renderização e falta de permissão operacional.

## Limitações de segurança importantes

**Não confundir este filtro de memória com proteção multiempresa real.** O
`OrganizationId` é fornecido pelo chamador sem comprovação, portanto não
autentica usuário, tenant ou autorização de leitura. Usar este resultado
diretamente num endpoint de cliente seria inseguro.

Antes de qualquer atendimento real, os requisitos incluem:
1. Resolver identidade e membership exclusivamente em backend confiável
   do Portal (não a partir de parâmetros HTTP controláveis).
2. Consultar somente a empresa autorizada, com tenant verificado e PostgreSQL
   RLS validado contra manipulação de GUC/contexto e acessos cruzados.
3. Verificar titularidade da solicitação, serviço contratado e eventual vínculo
   com outros produtos por contrato; não compartilhar sessões com o CRM.
4. Enforce transações, CAS/versão, idempotência persistente, audit trail,
   retenção LGPD, revogação, rate limiting, limites de anexos e backup/restore.
5. Homologar interfaces, direitos de acesso, testes adversariais e operação.

Não foram adicionados controllers, endpoints, autenticação, migrations,
SQL, cron jobs, webhooks, API Sites/CRM/Performance, publicação ou escrita.
`main` e a VPS não devem ser modificadas por este PR.
Não autoriza merge automático, produção, despesas ou dados pessoais.

## Evidências a coletar

- CI Laravel PHP 8.3 e 8.4 na HEAD exata do PR.
- CI Node.js 22 e 24 para garantir compatibilidade com protótipo legado.
- Revisão das 5 categorias principais: escopo/diff, XSS, isolamento simulado,
  nenhuma rota nova, status do PR pai #112.
