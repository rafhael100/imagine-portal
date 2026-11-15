# F430 — revisão mensal PHP/HMAC → PostgreSQL18 com consumo único

## Resultado esperado da prova

A F425 autentica uma revisão mensal **sintética** via HMAC no PHP. A F427
mantém um ledger PostgreSQL18 descartável com `FORCE RLS`, vínculo estrito
de empresa/pedido/mês/versão/hash do relatório/hash do candidato/valor e
transição atômica `issued → consumed`.

Esta etapa faz as duas peças interoperarem, em vez de provar somente HMAC e
SQL de forma independente:

1. PHP F422 gera relatório fictício, F424 gera revisão conservadora de mês
   fechado, F425 emite **MAC real do laboratório**; a rotina verifica o MAC
   antes de preparar o teste SQL.
2. O script gera um SQL transitório com permissão `0600` no runner e um
   conjunto de **identificadores exclusivamente fictícios**.
3. PostgreSQL18 é iniciado em Docker com `--network none`; a base e os
   logins são descartáveis e criados na CI. O script recusa outros bancos.
4. Um issuer com privilégio restrito registra um envelope sintético ainda
   válido; o worker só pode chamar o predicado estreito de consumo.
5. Troca de MAC, empresa, candidato, valor e replay não consomem. O envelope
   legítimo consome exatamente uma vez.
6. A CI repete com PHP 8.3 e 8.4. Não existe chamada de gateway, geração
   de boleto/NFS-e, API de anúncios, cobrança ou deploy.

## Limitações que devem permanecer visíveis

- O banco confere igualdade de MAC e campos com os valores armazenados. A
  **verificação criptográfica do envelope** ocorre no produtor PHP; o
  contrato não possui ainda um fluxo de autorização real com sessão MFA,
  assinatura do cliente ou executor financeiro.
- A F428 usa extratos fictícios. Nenhuma fonte oficial Google/Meta foi
  autenticada; mês parcial F429 é somente proposta contratual.
- A F427 usa funções e roles de **laboratório** e um owner privilegiado
  dentro do contêiner descartável. Isso não deve ser transplantado para
  produção como migração aprovada.
- Integração transacional em PostgreSQL com serviço IAM real, segregação
  AppSec, auditoria, idempotência de PSP, NFS-e, revisão independente e
  restauração/rollback na Leaseweb continuam gates abertos.

**Estado:** DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE. Vinculado ao controle
#802 e às PRs #827, #832, #833, #834.
