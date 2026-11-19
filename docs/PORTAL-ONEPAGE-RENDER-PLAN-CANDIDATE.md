# Plano candidato Onepage: snapshot + conteúdo editorial → renderer Sites

**19/11/2026.** Continuação independente do
[exportador candidato](PORTAL-ONEPAGE-BRIEF-SNAPSHOT-EXPORT-CANDIDATE.md).
Esta peça liga um snapshot técnico explicitamente escolhido a um conjunto
**separado** de textos editoriais e ao SHA/tamanho de um HTML esperado.
Não produz conteúdo por IA, não chama APIs externas, não publica, não
confirma pagamento ou aceite e não modifica o pedido Portal.

## Contrato da composição

`OnepageRenderPlanCandidateEncoder::build` recebe quatro entradas:

1. O par manifesto/corpo retornado pelo exportador candidato de briefing.
2. Os **bytes originais** do JSON editorial Onepage com seis campos:
   `productCode`, `brand`, `headline`, `description`,
   `callToAction`, `benefits`.
3. Seleção explícita `operationId`, `idempotencyKey`, `releaseRef`
   e `renderInputRef`.
4. Expectativa de `sha256` e `sizeBytes` do HTML, obtidos **antes**
   do plano por um renderer confiável do Sites, jamais calculados a partir
   do briefing rico pelo Portal.

O resultado contém `planBytes`, `editorialBytes`, `planSha256`,
`editorialSha256` e as quatro flags
`authorityVerified=false`, `clientAccepted=false`,
`canPublish=false`, `canAcceptRealOrders=false`.

O `planBytes` segue o schema
`sites.brief-render-plan.candidate.v0.1` de nove campos: contexto,
seletores explícitos, referência de snapshot com revisão/hash, descriptor
de JSON editorial e digest/tamanho do artefato esperado. Tamanho/sha
identificam **bytes completos**; preservar LF, CRLF, Unicode e escapes é
obrigatório. Novo arquivo editorial implica novo digest, mesmo se o
objeto JSON fosse semanticamente equivalente.

## Regras e separação de autoridades

- O plano exige que o par de origem tenha SHA do manifesto e do corpo
  coerentes, campos obrigatórios, seleção Onepage e todas as flags falsas.
- O conteúdo editorial é *informado*, não gerado: valida tipos, seis
  campos exatos, UTF-8, ausência de NUL, limites de pontos de código e
  um a oito benefícios. Não aceita conteúdo composto apenas de
  espaços Unicode. Não apara/normaliza texto válido.
- A expectativa do HTML é **declaração não verificada** até o consumidor
  Sites recalcular e comparar o HTML efetivamente renderizado. O
  `briefRevision` não equivale à revisão editorial e o plano não
  reserva autorização corrente nem o identificador futuro do release.
- O Portal continua titular do briefing aprovado, contrato e aceite do
  cliente; o Sites conserva a renderização, proveniência do artefato,
  publicação/rollback e revisões técnicas. Este produtor candidato não
  vincula nenhum desses poderes automaticamente.

O resultado de `sourceState=approved` no manifesto de origem ainda
é apenas o rótulo previsto pelo contrato candidato; não existe leitor
Portal oficial que comprove submissão, aprovação, autoria ou revogação.

## Testes executáveis

`tests/onepage-render-plan-candidate-offline.php` cobre a montagem de
plano, originalidade dos bytes, campos/editorial/URLs ausentes, hashes,
limites e negativas para alterações silenciosas ou flags indevidas.

`tests/onepage-plan-sites-render-parity.php` faz o teste cruzado com
o commit imutável do Sites
`3ac4e9c7d3106b57096b6eb7143a057d0267de35`. Seu fluxo é:

```
JSON de briefing rico (Portal, fonte candidata)
    ↓
Manifesto de snapshot candidato — hash e revisão
    ↓
JSON editorial selecionado independentemente
    ↓
Plano de renderização candidato
    ↓
BriefRenderProvenance::render (Sites real, código pinado)
    ↓
HTML inerte + sidecar privado com flags falsas
```

O teste compara o HTML exato do renderer, seus digests, os quatro arquivos
e o sidecar. Trocar briefing, editorial, revisão ou inventar digest sem
renderizar precisa causar recusa. O GitHub Actions executa a prova em PHP
8.3/8.4, com checkout fixado, sem banco, Composer, Asaas, IA, custo,
dados reais ou escrita em produção.

## Contrato adicional de consistência (19/11/2026)

O compositor de plano recusa, **antes de produzir bytes**, um par de snapshot
cujo manifesto tenha sido regravado e cujo SHA-256 tenha sido recalculado,
mas que viole invariantes do parser real de Sites. A regressão cobre:
revisão `briefRevision != expectedBriefRevision + 1`, limites de
`contractRevision` e `sourceVersion`, além de referências originais
de operação, pedido, contrato e payload fora da gramática fechada.
Oito casos negativos usam hashes recalculados, portanto não dependem apenas
de um teste trivial de SHA inválido.

O compositor também recompõe o par usando o `OnepageBriefSnapshotCandidateEncoder`
local, com os **bytes originais** da fonte rica: um JSON inválido, campos
desconhecidos, material marcado como disponível, serviços vazios ou contato
inválido continuam recusados mesmo após novo SHA-256 do corpo e do manifesto.
Uma alteração legítima de LF para CRLF produz novo compromisso pelo próprio
exportador e é aceita como entrada sintética distinta. A serialização do
manifesto do exportador deve permanecer canônica, sem repacotamento.

Essas recusas previnem o envio de planos que seriam inevitavelmente negados
pelo `BriefSnapshotInput` fixado. **Não constituem atestação de origem,
aprovação do cliente, identidade nem autorização para publicar**. Toda
verificação de posse, idempotência e revogação durável permanece separada.

## Pendências de homologação operacional

Faltam um **leitor Portal autorizado e versionado** para o corpo rico,
prova do comprador/pedido/revisão e da aprovação humana, vínculo da
seleção editorial com essa aprovação, transporte autenticado e confirmação
de recebimento idempotente no Sites. Também é necessária uma decisão
sobre materiais, domínio real, envio de formulários ao CRM, publicação,
rollback e verificação independente do destino.

Esta mudança continua em PR draft, sem merge/deploy, credenciais,
VPS, qualquer ativação dos gates operacionais ou autorização para uso
de um conteúdo real de cliente.
