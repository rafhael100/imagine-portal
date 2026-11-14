# Portal P0 — reconciliação independente de liquidação Asaas (F1-390)

## Objetivo

Esta entrega cria o **adaptador de leitura da cobrança oficial** do Asaas e o associa ao contrato versionado do Portal antes de o emissor Ed25519 F1-387 poder emitir o atestado de instalação Site. Preserva a escolha comercial de Asaas como gateway pretendido; **não registra conta, cobra, autentica cliente, cria assinatura, recebe webhook ou habilita produção**.

Fontes oficiais consultadas em 14/11/2026:
- GET da cobrança completa: https://docs.asaas.com/reference/recuperar-uma-unica-cobranca
- Diferença de status da cobrança e webhooks: https://docs.asaas.com/docs/webhook-para-cobrancas
- Autenticação/entrega at least once dos webhooks: https://docs.asaas.com/docs/receba-eventos-do-asaas-no-seu-endpoint-de-webhook
- Pix Automático e autorização ACTIVE: https://docs.asaas.com/docs/pix-automatico
- Assinaturas com cartão de crédito e vencimento futuro: https://docs.asaas.com/reference/criar-assinatura-com-cartao-de-credito

## Contratos de código

1. `AsaasTrustedPaymentBindingSource`: interface da futura base oficial de contratos e pagamentos do Portal. Só esta fonte confiável fornece tenant, pedido, contrato/revisão, produto, valor aprovado **em centavos**, ID da cobrança Asaas, ID do cliente Asaas e `externalReference` imutável. Exige unicidade SQL do vínculo por cobrança, pedido e revisão, bloqueando troca posterior.
2. `AsaasReadOnlyPaymentClient`: **somente** consulta `GET /v3/payments/{id}` em `https://api-sandbox.asaas.com` ou, com opt-in explícito de leitura, `https://api.asaas.com`. Origem/ID não vêm do usuário. TLS e hostname verificados, sem redirects, proxies, cookies, POST, logs de payload ou segredo; respostas truncadas/erros são NEGADAS. Token será recebido do vault/gestor de segredos de quem operar a aplicação e não existe no repositório.
3. `AsaasVerifiedSettlementSource` implementa a interface `PortalCurrentSettlementSource` já usada pelo emissor Ed25519. Depois de nova consulta PSP, exige pagamento `RECEIVED` com ID, cliente, referência externa, modalidade e valor em centavos idênticos ao pedido. Rejeita `CONFIRMED`, pendente, valor divergente, estorno, chargeback, assinatura/ciclo mensal, ID de outra empresa e falhas de conexão. **Não** considera `CONFIRMED` liquidação final para liberar produção.
4. A evidência `PortalSettlementEvidence` contém identificador do pagamento e hash SHA256 canônico dos campos validados. **O hash é integridade local, não uma assinatura do Asaas**, não substitui a verificação PSP pelo Sites.
5. O emissor existente `PortalEd25519EntitlementIssuer` continua exigindo **três fontes**: contrato ativo, prova de liquidação e revogação vigente. Mesmo essa assinatura é insuficiente para publicar um Site: o Sites exige sua própria testemunha PSP e revogação independentes.

### O que os testes provam

O `tests/f390-asaas-official-settlement.php` usa um reader e um vínculo em memória, contas, IDs, importâncias e chaves fictícias. É proibido executar fora de GitHub CI. Testa aprovação fictícia, recusa de 22 manipulações, desconto/valor em centavos, retorno estrangeiro, falha da fonte, indisponibilidade da consulta, ambientes e assinatura Ed25519. **Não realiza chamada real ao Asaas ou checkout.**

## Próximas implementações P0 para operação

- Repositório de pagamento/contrato com `tenant` e integridade imutável: vínculo à oferta versionada/valor aprovado, registros de consulta e auditoria, lock transacional, idempotência e esquema SQL versionado; migração/restore aprovados por DBA.
- Endpoint público de webhook autenticado via header `asaas-access-token`, token independente da API key. Inserção durável deduplicada pelo `id` do evento antes do HTTP 200, fila de processamento e reconciliador GET **independente** em falhas/reembolso/chargeback.
- Configuração sandbox da conta Asaas pelo operador, teste de Pix/Cartão e habilitação de Pix Automático. Para recorrência, o primeiro vencimento D+90 deve ser calculado a partir do aceite e **da oferta versionada**, sem iniciar assinatura imediata nem produzir cobrança por repetição de timeout.
- Distinção entre **liquidação de implantação** e **ciclos futuros de mensalidade**; cada ciclo tem conciliação própria. Mensalidade futura nunca habilita a implantação. Também validar condições contratuais e eventuais descontos na oferta original, não fixar percentuais no adapter.
- Prova independente de revogação e confirmação financeira pelo Sites via credenciais próprias, autorização S2S, TLS válido e chaves armazenadas fora de código.
- Testes reais no Asaas Sandbox com a conta **elegível** e após isso homologação privada da VPS com backup/rollback, análise AppSec/DBA/LGPD, emissão fiscal e autorização específica de produção.

## Segurança e limite de entrega

Por padrão, `AsaasVerifiedSettlementSource` é construído com fontes que retornam `null`; os arquivos **não** são registrados em ServiceProvider, controller, middleware, rota ou Laravel container operacional. Não existem chaves reais, API de produção, criação de cobrança ou efeito financeiro nesta revisão. Toda saída do CI é `DRAFT / NO-GO / NO MERGE / NO DEPLOY / NO CHARGE`.

Esta entrega é a primeira etapa de integração financeira verificável, **não a homologação do PSP**.
