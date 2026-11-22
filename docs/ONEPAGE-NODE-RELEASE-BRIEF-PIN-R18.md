# OnePage Node R18 — release vinculada à aprovação exata (somente laboratório)

**Cadeia:** Portal #1092 (HEAD `2299941136343bb1ec14468e46d8c67198533b7c`) → R18. A revisão de autorização da #976 continua padrão-negado no Laravel e **não** é substituída por esta implementação Node/SQLite.

## Defeito demonstrável

A #1091 verifica a aprovação *atual* antes de cada transição e a #1092 permite revogar a aprovação. Contudo, após o comprador aprovar uma release v1 e o revisor revogar o briefing v1, um novo briefing v2 poderia ser confirmado e aprovado, permitindo que `publish` voltasse a passar para a release **antiga** — pois a checagem não distinguia a geração editorial usada na apresentação da release.

## Correção de recorte mínimo

- Migração SQLite v6 **aditiva** com `production_release_brief_pins`, chave única `(order_id,release)`, recibo editorial, versão de submissão e SHA-256; triggers impedem UPDATE/DELETE. Releases legadas sem prova continuam sem autorização.
- Ao registrar a prévia, a mesma transação guarda a referência da aprovação corrente após a revalidação independente feita em `approvedCurrent`.
- Nas transições `approve`, `request-changes` e `publish`, a referência/versão/digest da release deve continuar exatamente igual à aprovação corrente; divergência retorna 409 e não gera evento/auditoria de produção.
- Teste HTTP usando **um pedido e um comprador fictícios**: v1 confirmada → aprovada → release apresentada → aceite do comprador → revogação → v2 confirmada/aprovada → tentativa de publicar v1 recusada. Releitura SQL verifica persistência append-only, SHA e ausência de evento `publish`.

## Limites e bloqueios

Esta correção **não mede nem garante os bytes HTML servidos** na prévia externa: a URL de produção Node ainda é fornecida pelo operador, sem transporte autenticado/manifesto da release. Não atesta sessão corporativa real, artefato Sites, aceite final oficial, publicação, rollback, pagamento ou autoridade do Portal Laravel. Para fechar a lacuna material é necessário um reader operacional homologado para o contrato Portal #976 com briefing original imutável, prova corrente e revogação; e o Sites deve testemunhar o SHA do HTML efetivamente servido na própria prévia privada. Sem tais fontes, permanecer fechado.

A Landing Page não participa nem herda esta evidência. Nenhuma chamada OpenAI/Replit, credencial, VPS, checkout, Asaas, publicação, merge ou deploy. **Sites #38 NO-GO.**

Resultados CI: registrar apenas após confirmação da SHA final, sem extrapolar evidências.
