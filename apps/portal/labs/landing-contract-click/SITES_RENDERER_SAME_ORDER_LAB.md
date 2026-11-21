# Landing — prévia HTML gerada pelo motor real do Sites, pedido fictício único

21/11/2026 · laboratório isolado · **NO-GO operacional**.

## Por que esta candidata existe

Portal #1047 servia via GET/Chromium o HTML **inerte de uma fixture**, embora contrato clicado, briefing aprovado em fixture PG18 e sessão sintética já estivessem correlacionados por #1041/#1043. Sites #464 demonstrou anteriormente o motor renderizador com outra admissão fictícia; juntar essas evidências verbalmente não comprova a mesma cadeia. Este recorte executa realmente o motor `StaticSiteRenderer` do Sites, pinado à SHA `8172e5cc14e97951e529c843059c214e05c33db5` (#569), para o mesmo pedido sintético aceito na sessão de laboratório do Portal, no mesmo banco descartável.

## Execução e limites

- Reutiliza o clique real de navegador e a releitura dos termos exatos do mesmo pedido (#1041/#1043), além do leitor RLS de HEAD aprovado *por fixture*, sem criar eventos novos de aceite.
- Busca bytes/revisão/digest do briefing do mesmo pedido pelo reader PG18; aceita apenas o objeto JSON de teste com headline e description. Empresa, tipo de produto, marca, CTA, benefícios e autoridade não vêm da fixture nem de IA.
- Invoca os componentes **originais** `StructuredCopyCandidate::assemble('landing_page', ...)` e `StaticSiteRenderer::render()` do repositório Sites em checkout público por SHA exata; mantém `sites.ai-copy.v1` com os cinco campos existentes. Nem rede do modelo nem IA são utilizados.
- Substitui, com CAS de revisão e SHA, o HTML estático anterior na tabela descartável de #1047. O roteador HTTP privado permanece **inalterado**, aplicando aceite, escopo de sessão fictícia, revalidação do briefing, digest de corpo, CSP sandbox e no-store.
- O navegador calcula SHA-256 de **bytes da resposta**, confere o digest do PG18, a propriedade de produto Landing e o título derivado do briefing. O GET de outro comprador da mesma empresa falha. Depois de alterar o briefing na fixture (sem mudar o HTML armazenado), a tentativa de GET falha pela divergência da HEAD.
- Toda operação exige `APP_ENV=testing`, GitHub Actions, servidor loopback e PostgreSQL 18 descartável. Sem novos segredos, checkout, PSP, leads, publicação, aprovação final ou entrega.

## Dependências ainda abertas

A fonte de aceite por login **nativo** do Portal, o writer operacional de contrato e de aprovação/revogação do briefing e a integração autenticada Portal–Sites **não** são demonstrados. A fonte do texto do briefing ainda é inserida pela fixture CI da #1043; os bytes não são um briefing oficial. Também permanecem ausentes aceite final do cliente, autorização de publicação e submissão/leitura independente de lead da versão entregue. **Não é teste completo operacional**. Gates Sites #38 e demais controles permanecem NO-GO.

A evidência de CI e os digests de artefato devem ser extraídos do workflow dedicado depois de rodar para a HEAD exata. Não presumir resultado verde antecipadamente.