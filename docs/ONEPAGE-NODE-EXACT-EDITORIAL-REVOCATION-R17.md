# OnePage Node R17 — revogação editorial exata (laboratório, NO-GO)

## Lacuna real após Portal #1091
O revisor do Node legado só conseguia aprovar ou pedir ajuste de uma revisão *enviada*. Uma revisão já aprovada permanecia elegível em `approvedCurrent` sem um recibo imutável de **revogação**. A regra de negócio autorizada exige que o revisor possa revogar a aprovação da versão exata, e qualquer nova revisão dependa de outra confirmação de direitos pelo titular e outra aprovação editorial.

## Recorte implementado
- Migração aditiva **v5** em SQLite no candidato Node, com `briefing_revocation_receipts` append-only e chave única da aprovação revogada. Preserva os recibos v4. Não migra o Portal Laravel/PostgreSQL.
- O endpoint **já existente** `review-briefing` reconhece `status: revoked` apenas para administrador da Imagine e para a geração corrente previamente aprovada. Exige justificativa, grava revogação, estado e auditoria na mesma transação.
- A revogação não depende dos anexos ainda estarem acessíveis ou íntegros. Mesmo adulteração manual de `status` e `version` de volta ao aprovado não elimina o recibo e não revalida a aprovação.
- O comprador pode corrigir uma versão revogada, mas o novo envio exige declaração de direitos e produz novos recibos de confirmação/aprovação. Nenhuma aprovação antiga é reutilizada.
- O gate de `production.mutate()` introduzido na #1091 permanece *fail-closed*, sem alterar permissões, preços, contratação, PSP, nem introduzir publicação real. Não há novo endpoint ou credencial.

## Limites
Esta é uma candidata **Node/SQLite em teste isolado**, dependente de #1089 → #1090 → #1091; não tem autenticação corporativa homologada nem fonte oficial Laravel do contrato Portal #976. Nenhum adaptador para Sites, aceite final operacional, suspensão de publicação já realizada, transmissão OpenAI ou transferência de autoridade. O estágio de produção do Node ainda precisa ser vinculado à geração aprovada do briefing se for convertido em fluxo operacional: aprovação de briefing novo **não** certifica uma release antiga automaticamente.

Os casos sintéticos incluem comprador errado, role indevida, duplicação, append-only, revogação com versão corrente, status forjado, zero eventos novos após bloqueio, novo briefing e nova confirmação. Os checks CI devem ser correlacionados à **SHA final** antes de qualquer afirmação de aprovação. Sites #38 continua NO-GO. Nenhum merge, deploy, alteração de VPS, credencial, Cloudflare, DNS, Asaas ou checkout.
