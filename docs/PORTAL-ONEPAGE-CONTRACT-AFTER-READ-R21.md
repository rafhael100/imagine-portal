# OnePage R21 — revalidar contrato depois da segunda leitura privada (NO-GO)

## Defeito reproduzido em laboratório

A candidata [Portal #1095](https://github.com/rafhael100/imagine-portal/pull/1095) (HEAD `e8ada95122f5808837da9be3ec322a7e1c732ec2`) validava o contrato duas vezes: antes da primeira e antes da segunda leitura do briefing. Se o contrato fosse revogado durante a segunda leitura, a testemunha de aprovação ainda poderia devolver `true`, e a função retornaria o snapshot candidato apesar da revogação.

Regressão adicionada primeiro, numa branch exclusiva filha da #1095: [CI inicial R21](https://github.com/rafhael100/imagine-portal/actions/runs/45098730074), matrizes PHP 8.3/8.4 **FAIL**, com `contract revoked during second private read must deny candidate incorrectly passed`. Essa falha demonstra somente um defeito da candidata offline, não acesso real a briefing privado.

## Correção restrita

- Depois da segunda leitura do briefing **e** da checagem final da aprovação, consultar o contrato autorizado pela terceira vez e exigir igualdade com o contrato original, produto OnePage e mesmo pedido.
- Não efetuar terceira leitura dos bytes privados; preservar os dois pontos de verificação de aprovação da R20, além da primeira.
- Uma revogação durante a segunda leitura de conteúdo e mudança de tenant durante a testemunha final agora devem ser negadas. A fixture conta **3 leituras do contrato, 2 do briefing e 3 da testemunha** no caminho positivo e nos dois novos cenários.
- Não alterar o encoder #969, o contrato Portal #976, a troca Portal #1003/Sites #552, as flags de autoridade nem a seleção explícita da versão.

## Limites e dependência real

Um terceiro lookup não fornece atomicidade. Somente uma fonte **oficial** Laravel/PostgreSQL poderá fornecer revisão e bytes imutáveis, confirmação da versão exata e direitos pelo comprador responsável e testemunha independente de aprovação/revogação do revisor. O futuro transporte S2S exigirá um gate transacional/CAS próprio. Nenhuma fixture passa a ser autoridade operacional.

Sem API OpenAI: a rota/identidade/modelo/egress do worker OnePage e a credencial já provisionada exclusivamente a esse escopo ainda não foram demonstrados. Sem consulta a chave do Faísca. Landing Page e VEN permanecem independentes. Sites #541/#542 são precedentes históricos; #573/#574 e #577–#579 já possuem ensaios próprios não repetidos. Sem pagamentos, dados reais, infraestrutura, VPS, Cloudflare, DNS, merge, publicação ou deploy.

**DRAFT / NO-GO / Sites #38 bloqueado.** CI e SHA final devem ser atestadas antes de declarar a correção aprovada.
