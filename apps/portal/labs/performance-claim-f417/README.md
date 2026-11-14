# F417 — prova integrada de envelope F415 com ledger F416

Etapa posterior às PRs F413, F415 e F416, ainda somente em
laboratório descartável.

O emissor PHP carrega os componentes reais de **revisão** da F413 e F415
em vez de usar uma assinatura inventada à mão. Com dados fictícios:

1. Calcula Meta + Google = R$980 de ativação, sem incluir verba de mídia;
2. Seleciona Asaas por uma política sintética e produz HMAC de revisão CRM;
3. Emite o envelope com os **dois canais exatos**, organização, pedido,
   PSP, política e vencimento UTC curto, com `can_charge=false`;
4. Confere o HMAC em PHP e recusa uma seleção de canais adulterada;
5. Calcula o hash do envelope e uma identificação dos canais e emite SQL
   limitado por regex para registrar o claim na tabela do PostgreSQL18 F416;
6. O worker de laboratório verifica MAC/hash/tenant/pedido/valor/versão,
   consome exatamente uma vez e prova rejeição de MAC incorreto e replay.

O serviço PostgreSQL é iniciado sem rede, credencial externa, volume
persistente ou dados de usuários, e destruído após a execução do CI.

**Limitação:** o processo ainda não representa o fluxo real de negócio.
A integridade do envelope é verificada em PHP; a demonstração SQL assume
que recebeu dados depois dessa verificação. Não há transação única
entre sessão autenticada, emissão, gravação e validação do PSP, nem
instrumentação externa do CRM. Não expor como endpoint nem usar para
cobranças. Ainda faltam MFA, concessões de cliente, gerenciamento de chaves,
consentimento, migrações compatíveis, reconciliação, backup/restore e
contrato/aceite financeiro.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CHARGE.**
