# Onepage: contrato inerte de cotação retomável

DRAFT / NO-GO operacional. Primeira etapa da prioridade Comercial → Portal → Asaas Sandbox → prévia privada Onepage. Este recorte NÃO chega a Asaas ou Sites e não acrescenta Google/Performance.

## Bases e não duplicação

HEADs conferidas em GitHub:
- Comercial #442: `78c74b646cc9cf494464ba5aa85f198be1f0efef`.
- Portal #919: `0bb4d9fabbd5d2b5992eaf5d3fd19d2555469088`, base #915 `6ac1be6a543218c880ebd411b15c8c3a83dacb81`.
- Referências separadas #909 `fce965f76b96647c86ba0985b49ac48a0fc03021` e #917 `83c4e37e06b6b8daefbe8f84153946856eed27b3`.
- Barreira financeira #916 `0e9b6f40361008fdacdbf7280dd1eb6d91bfdddb`, preservada.
- Sites #486 `a4db62ac07566fb6b0bd9a27988c2ad4c0622c56`, demonstração privada, não provisionamento contratado.

Branch exclusiva `test/onepage-resumable-quote-contract-2026-11-16`, sobre #919. Nenhum merge/cherry-pick das referências. Busca de PRs Onepage/retomável e paginação das branches onepage não encontrou contrato atual equivalente. O histórico #828 já contém handoff anônimo com sessão de teste, não autoridade nativa de cotação; não foi reimplementado seu login. Inventário de tarefas Codex externas não disponível nesta sessão. AGENTS lido; .agents/skills relevante ausente.

## Entrega e semântica exata

`OnepageQuoteJourneyPlan` fica em Homologation/Commerce e não é registrado em provider ou rota. Aceita somente seleção Onepage sem canais; valida três enums, empresa proposta e chave. Usa `CompositeQuotePreview` existente, sem preços recebidos do navegador. A empresa passada ao contrato NÃO é autoridade: é somente contexto proposto para revisão.

O mesmo objeto pode ser retomado em memória com a mesma empresa, chave e seleção. A retomada recalcula pelo catálogo e recusa alteração de catálogo/digest em vez de aceitar mudança silenciosa. Empresa/chave/seleção divergentes são recusadas; ordenação das propriedades de seleção é normalizada. Chave igual em empresas diferentes produz scopes diferentes. O fingerprint proposto é derivado de namespace, empresa e hash da chave; não é segredo, token de acesso ou ID operacional.

Não existe store. Repetir prepare cria outro objeto. O fingerprint determinístico NÃO prova deduplicação durável, exclusão concorrente ou recuperação após restart. Para detectar conflitos entre objetos separados, um store futuro deverá impor unicidade e comparar seleção/catálogo/digest atomicamente. O teste demonstra replay do contrato em memória, não INSERT/ON CONFLICT.

Toda resposta explicita PROPOSED_UNVERIFIED, authority_verified=false, persistence_allowed=false, draft_persisted=false, order_created=false, payment_requested=false e site_provisioned=false. Não usa uma fixture positiva como concessão de autoridade. Não expõe dados protegidos: contém apenas a cotação anônima do catálogo.

## Evidência nova

Linux local; PHP 8.4.24; PHPUnit 12.5.43; dependências locais existentes com composer.lock idêntico, sem instalação externa. No diretório apps/portal:

```sh
APP_KEY=base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE= php vendor/bin/phpunit --no-progress --do-not-cache-result tests/Feature/OnepageQuoteJourneyPlanTest.php
```

Execução real: **OK (8 tests, 30 assertions)**, 0.241s. PHP utilizado no executor: `/workspace/scratch/c24b1cbd1d14/p20-tools/bin/php`. A chave é fixture pública de teste, não credencial real. git diff --check passou.

Cobertura: Onepage recalculada/retomada, repetição e normalização, escopos distintos por empresa, retomada com empresa/chave divergente, mudança de seleção sob mesma chave, Ads fora deste recorte, contexto ausente e ausência de bindings/rotas/promoção de verdict sintético.

Única seleção executada no Comercial fixado: `{site:onepage,channels:[],tier:minimum}`. Resultado: implantação 99000 centavos, parcelas ilustrativas [33000,33000,33000], mensalidade 7990/D+90, ativação/mídia/gestão zero. Comparada ao recálculo Portal no teste. Nenhum preço/política novo.

Não se repetiram os 5 testes/241 assertions de #919, as 48 escolhas ou a concorrência F353. Não rodaram PostgreSQL, migrations, fixtures SQL, grants, Chromium, chamadas Asaas/Sites ou testes de exploração. Nenhum banco foi preparado: os gates de configuração Laravel e identidade SQL/cluster/login em loopback não foram exercitados, porque não se iniciou conexão nem escrita de banco.

## Investigação da autoridade nativa e desenho pendente

Referência lida diretamente do SHA #917:
- [NativePerformanceAuthority](https://github.com/rafhael100/imagine-portal/blob/83c4e37e06b6b8daefbe8f84153946856eed27b3/apps/portal/app/Domain/PerformanceAdministration/NativePerformanceAuthority.php): guard portal-admin, usuário ativo atualizado, MFA ligada ao ID e com janela de 900s, epoch atual, conexão performance_authority, serviço e concessão Performance específicos, papel SQL/RLS, auditoria e digest da concessão/contrato exatos. A fonte privada ainda exige synthetic_persisted.
- [AuthorizedPerformanceRead](https://github.com/rafhael100/imagine-portal/blob/83c4e37e06b6b8daefbe8f84153946856eed27b3/apps/portal/app/Domain/PerformanceAdministration/AuthorizedPerformanceRead.php): revalida lease antes/depois da leitura e no registro do resultado.
- PerformanceAuthorityBridge permite dashboard.read, clients.read, reports.read sob performance.admin.*, nunca criar/retomar cotação Onepage. PrivateDemonstrationPolicy não autoriza operações externas.

A base #919 mantém PortalAuthorityBoundary/PortalBoundaryVerdict não operacionais; canAuthorizeHttp() é sempre false. O SyntheticDraftWriter composto exige postgres do laboratório. Não há adapter nativo de cotação nem writer mínimo compatível que possa ser simplesmente ligado sem mudar autoridade/permissões. Não se portou dashboard.read, services.view ou sessão administrativa como autorização de cliente.

Vínculo proposto para decisão separada (nenhum destes bindings foi implementado):
1. Definir o principal: cliente da empresa ou operador autorizado a cotar em seu nome. Uma cotação pré-contratual não deve presumir o contrato Performance ativo exigido pela referência administrativa.
2. Autoridade Portal carrega identidade/sessão/MFA/epoch e vínculo empresarial atual de fontes nativas. Empresa enviada pelo navegador é só seleção. Operações específicas de criar/retomar cotação precisam de semântica e concessões explícitas; nomes candidatos quote.create/quote.read são apenas desenho, não permissões novas no código.
3. Emitir contexto interno vinculado a principal, empresa, operação, revisões da sessão/vínculo/concessão, revogação e validade. Revalidá-lo antes da leitura/escrita e antes de devolver o resultado. Falta/revogação/divergência falha fechada. Não usar o hash de idempotência como capability.
4. Store segregado usa empresa RESOLVIDA pela autoridade e chave de requisição; unicidade transacional e snapshot de seleção/catalog_version/quote_digest. Conflito não altera preço nem duplica rascunho; regra de expiração/reprecificação requer decisão própria. Leitura só depois de revalidar mesma empresa e autorização.
5. Runtime nunca recebe postgres/admin; writer/reader mínimo e RLS precisam de contrato revisado. Nenhum novo grant/migration operacional está autorizado por este patch. Antes de preparar futuro laboratório, validar configuração efetiva Laravel, loopback literal, cluster dedicado, banco e login sintéticos e abortar antes de fixtures em qualquer divergência.

Decisão precisa: autorizar um adapter **laboratorial** de autoridade nativa de cotação com principal e operações explícitos, e definir se haverá papel/store mínimo dedicado. Enquanto essa decisão não existir, a entrega correta é este contrato inerte; não uma jornada operacional simulada. Pedido, aceite, liquidação total (#916), PSP e provisionamento Sites continuam fora do recorte.
