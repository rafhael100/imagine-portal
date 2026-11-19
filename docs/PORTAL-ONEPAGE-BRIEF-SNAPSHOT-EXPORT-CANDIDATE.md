# Exportador candidato do briefing rico Onepage — Portal → Sites

**19/11/2026.** Nova peça pura de engenharia, ligada à proposta Sites v0.1.
Ela materializa os dois JSONs que o Sites já sabe validar e armazenar. Não
transforma o formulário legado em exportador autorizado, não ativa rota, não
faz requisição externa, não publica o site e não libera aceitação/entrega.

## Contexto e motivação

A integração de projeto do Portal com o Sites já dispõe de um recibo nativo.
O Sites também dispõe de ingestão versionada de briefing, leitura exata,
renderização, registro de artefato e proveniência persistida, todos ainda
candidatos/demonstrações. O Portal D0, por sua vez, só indica
`briefing_available`; esse marcador não fornece o **corpo rico e a revisão
imutável**.

`OnepageBriefSnapshotCandidateEncoder` é um produtor local que recebe um
`SiteContractSnapshot`, uma seleção explícita de projeto/revisão e os bytes
do JSON do briefing Onepage. Ele não busca dados na base, não deduz autorização,
não gera o conteúdo editorial de seis campos e não altera as fontes existentes
de pedido, contrato, pagamento, MFA ou SiteBriefDraft SAMPLE.

## Interface

```php
$pair = OnepageBriefSnapshotCandidateEncoder::build(
    $contract,
    [
        'siteRef' => '11111111-1111-4111-8111-111111111111',
        'snapshotRef' => '22222222-2222-4222-8222-222222222222',
        'operationId' => 'op-brief-synthetic-001',
        'idempotencyKey' => 'idem-brief-synthetic-001',
        'sourceVersion' => 12,
        'expectedBriefRevision' => 0,
        'payloadRef' => 'brief-body-synthetic-001',
    ],
    $rawSourceJson,
);
```

Os valores do exemplo são fictícios. O resultado possui exatamente
`manifestBytes`, `sourceBytes`, `manifestSha256`, `payloadSha256`
e quatro booleanos **false**: `authorityVerified`, `clientAccepted`,
`canPublish`, `canAcceptRealOrders`. O corpo original permanece intocado,
inclusive ordem de chaves, escaping, CRLF ou LF final. O manifesto é gerado
uma única vez com ordem fixa, JSON UTF-8 e LF final; os hashes são SHA-256
dos arquivos completos, não uma equivalência lógica de objetos.

O manifesto segue `sites.brief-snapshot.candidate.v0.1`, com 17 campos,
`payload` {payloadRef,mediaType,sizeBytes,sha256}, sem materiais.
`sourceState=approved` é o rótulo exigido pelo contrato candidato,
**não é evidência de aprovação**. Esse valor **não deve ser enviado para um
Sites real** até que o Portal tenha um registro de aprovação verificável,
vinculado ao mesmo pedido, comprador autenticado, conteúdo e versão, com
política de revogação e transporte autenticado.

A API aceita somente Onepage, onze textos e três flags booleanas, com
indisponibilidade de logo/manual. Exige UTF-8, regras de completude, limites
UTF-16 antes do trim lógico ECMAScript e URLs HTTP(S) no subconjunto
conservador já fixado pelo Sites. Campos extras, chaves duplicadas,
normas de JSON inválidas, caminhos no lugar de referências ou modificações
silenciosas do conteúdo são recusados. `sourceVersion` (contador agregado
do Portal), `briefRevision` (sequência imutável Sites) e `editorialRevision`
não são intercambiáveis.

## Testes e compatibilidade

`tests/onepage-brief-export-candidate-offline.php` cobre casos positivos e
negativos de UTF-16, Unicode, contato, URLs, referências, duplicatas,
revisões e bytes exatos, sem banco ou rede.

`tests/onepage-brief-sites-candidate-parity.php` usa somente os arquivos
originais da proposta Sites e invoca o `BriefSnapshotInput::fromJson` do
próprio Sites. A integração é fixada no commit de referência
`3ac4e9c7d3106b57096b6eb7143a057d0267de35`, não em um branch mutável.
O teste verifica que o parser aceita os dois JSONs, conserva hashes/
referências, distingue o contador agregado da revisão e não reescreve bytes
quando a próxima revisão é gerada.

A matriz GitHub Actions separada roda PHP 8.3 e 8.4, sem Composer, banco,
segredos, dependências de produção, URLs reais ou upload/deploy. O
resultado será registrado na PR depois do CI; o código por si só não é
evidência de aprovação operacional.

## Dependências ainda não implementadas

Para transformar este produtor em integração real, o Portal deverá ter
um leitor de **briefing rico aprovado e imutável**, com a titularidade,
o hash original, o `sourceVersion`, a revisão e um registro durável
de autorização/revogação. Será preciso confirmar o `siteRef` a partir do
recibo nativo Sites e a elegibilidade comercial corrente, e transmitir o
snapshot por canal privado autenticado com idempotência, reconciliação
de resposta perdida e recebimento separado do corpus de materiais.

Também permanece necessária a seleção editorial humana/IA aprovada dos seis
campos que alimentam o renderer, e não só o corpo rico do cliente.

Esta entrega **não** constitui produtor oficial, aprovação de briefing,
pagamento recebido, aceite do cliente, operação em VPS, publicação/rollback,
Asaas, CRM, n8n, ou habilitação de nenhum dos gates comerciais. Deve permanecer
em PR draft até revisão independente e homologação específica.
