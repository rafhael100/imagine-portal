# Landing — aceite final de prévia HTML no mesmo pedido (laboratório isolado)

21/11/2026. Somente candidato de teste, **NO-GO operacional**.

## Lacuna comprovada

A PR Portal #1050 já provava HTML do renderizador real Sites (SHA fixada em `8172e5cc14e97951e529c843059c214e05c33db5`) servido por HTTP a sessão fictícia do mesmo pedido e com briefing aprovado **somente por fixture**. Não havia o clique separado de aceite **final da prévia** vinculado à revisão e ao SHA exatos do HTML servido. Aceite contratual da compra não é aceite final do artefato.

## Ensaio novo

Depois dos pré-requisitos históricos, o navegador lê os bytes reais via GET privado e confere SHA-256; uma tela separada exibe revisão do briefing, geração do artefato e hash, com checkbox e clique explícito. Persistimos apresentação de uso único por sessão, comprador, pedido, empresa, site, contrato, briefing, geração e SHA; PHP separado relê do PostgreSQL 18. O aceite não concede autorização de publicação.

Provas negativas: anônimo, outro comprador da mesma empresa, outro tenant, checkbox ausente, apresentação consumida, token de outra sessão, apresentação anterior após alteração do HTML, restauração de bytes idênticos em nova geração e revogação da aprovação fictícia do briefing. A geração monotônica impede que restaurar os mesmos bytes reviva um aceite antigo.

A prévia foi produzida pela engine real em snapshot público pinado; um ajuste posterior dos bytes é **simulado no banco descartável**, não uma nova release publicada. Não há contrato ou autenticação oficiais, assinatura jurídica, revisor operacional, publicação, lead, PSP, IA, DNS ou VPS. Gates Sites #38 e demais limites permanecem NO-GO.

## Critério para a próxima integração

Precisam existir, de fontes oficiais transacionais e autorizadas do Portal, sessão nativa do comprador, aceite contratual exato e confirmação/aprovação/revogação dos bytes do briefing do mesmo pedido. Em seguida conectar tais fontes ao leitor #1019/correlator Sites #580, sem tratar este writer de laboratório como autorizado. Aceite final, publicação e entrega continuam decisões distintas.

O workflow dedicado valida a SHA da HEAD da PR e do checkout do renderizador, cria apenas PostgreSQL 18 descartável e Chromium no GitHub Actions e imprime somente metadados e hashes fictícios, nunca cookies ou dados de compradores reais.
