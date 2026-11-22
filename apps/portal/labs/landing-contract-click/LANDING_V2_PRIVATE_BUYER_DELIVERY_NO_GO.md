# Landing R29 — recibo e link de entrega privada após leitura independente (laboratório NO-GO)

Base exata: Portal #1060 HEAD `220db16fc42bab0dfb2a85137ed80d39b46b61ff`. Referências não duplicadas: Sites #9 (ACK não prova release), #474 (mTLS privado), Portal #1055 (clique v2), #1057 (transporte mTLS), #1059 (captação fictícia separada) e #1060 (estado do provider após perda de ACK). Sites #580 não é autorizador operacional; #541/#542 são apenas históricas.

## Avanço verificável

Somente depois de o provider **local fictício** registrar APPLIED com um único efeito após ACK 504, o Portal emite um recibo de **entrega sintética**. Antes disso, a operação `issue` deve falhar, mesmo que exista aceite v2 ou ACK alegado. A origem é conferida por outra conexão e role PostgreSQL 18 RLS, consulta HTTP ao estado do provider e segundo GET dos bytes servidos; correlaciona empresa, comprador, pedido, site, release v2, SHA do briefing, SHA HTML, geração e desafio de aceite v2.

O recibo é persistido em tabela descartável com expiração de dez minutos e lido pela role `landing_r29_reader` com `FORCE ROW LEVEL SECURITY`. Um endereço **loopback** `/lab/delivery` só aparece após leitura corrente, e o link `/lab/delivery/html?ref=...` só devolve ao comprador fictício da sessão os mesmos bytes já observados. Conhecer o link não autoriza acesso. Testar anônimo, outro comprador da mesma empresa, outro tenant, POST, referência arbitrária e divergência dos bytes após adulteração do ledger simulado. Revogar a fonte corrente deve invalidar o recibo, sem confiar no estado histórico APPLIED ou reutilizar aceite antigo. Nenhum domínio ou URL de produção é gerado.

A sessão de login, aceite do contrato, aprovação do briefing e vínculo de release permanecem **fixtures**, não writers oficiais. O provider HTTP é simulado e o recibo não é assinado. O HTML continua sem formulário, sem canal de leads, sem publisher e sem destino. A entrega privada fictícia não equivale à publicação, entrega comercial, autenticação nativa ou homologação. Nenhuma mudança de IAM, chaves, gateways, Cloudflare, DNS, VPS, código principal, main, Asaas ou deploy.

## Critérios e separação

A nova prova é somente o **recibo privado ao comprador com verificação independente da release**. Os testes anteriores de clique, geração, mTLS, evento de lead e reconciliação de ACK são pré-requisitos, não serão novamente contados como novas provas. Etapa 15 de lead CRM/Vendas real permanece NO-GO sem destino autorizado, release e formulário previamente aceitos, transporte independente e confirmação real. OnePage e API OpenAI não são parte desta trilha.

Pendente operacional: writers oficiais de autenticação/MFA, contrato e aprovação/revogação do briefing; fonte confiável de autorização para publicação, release no destino autorizado, recibo assinado, domínio/rota privada real, link de entrega ao comprador nativo e leitura independente CRM/Vendas. Gates Sites #38, IN127-R05/R06 permanecem bloqueados; rollback automático não foi autorizado.


## R33 — limite efetivo na leitura HTTP do provider local

A R32 confirma idempotência na emissão concorrente, mas `r29http()` ainda aceitava o corpo completo de HTTP 200 em memória antes de verificar o limite da prévia. Nesta candidata, o estado JSON tem orçamento de **4.096 bytes** e o HTML de **32.768 bytes**, aplicados por callback de streaming do cURL. Exceder qualquer orçamento interrompe o recebimento e falha fechado antes do parse e do recibo. Sem follow redirects, sem URL dinâmica, retries ou tráfego externo.

O ensaio usa exclusivamente servidor adversarial em `127.0.0.1:8791` que devolve HTTP 200 com estado de 12.288 bytes e HTML de 65.536 bytes; ambos são rejeitados. Depois o servidor é encerrado e a prova integrada anterior com provider local normal continua, verificando que o limite não impede a release sintética válida. Não é teste de OpenAI, CRM/VEN, login nativo, dados reais nem publicação.

## R34 — identidade única e canônica do link privado

O receptor da entrega local recusava URLs com parâmetros extras, chave `ref` duplicada ou alias codificado de `ref` de maneira apenas parcial: o PHP transforma `$_GET` antes de fazer a leitura. Agora só aceita a forma exata `/lab/delivery/html?ref=<32 hex minúsculos>`, comparando `QUERY_STRING` integral e `$_GET` sem parâmetros adicionais. Isso impede que duas representações distintas de URL sejam tratadas como um único recibo no registro/auditoria, mantendo sessão do comprador e digest atual obrigatórios.

Novo ensaio negativo em Chromium: comprador válido recebe HTTP 403 para parâmetro extra, chave repetida e nome de chave percent-encoded; o link original continua verificado pelo SHA e protegido por sessão. Os testes preexistentes R29–R33 são pré-requisitos, não novos marcos. Sem autenticação nativa, writer oficial, CRM/VEN, publicação, rede externa ou mudança de gates.


## R35 — envelope canônico de estado antes do recibo

O estado HTTP do provider R28 é uma evidência **fictícia e limitada ao CI**, não uma autorização de publicação. Antes desta alteração, o leitor decodificava JSON aceitando membros repetidos, campos extras e representações diferentes que podiam produzir o mesmo objeto PHP. A R35 exige os oito membros originais na ordem do contrato (requestRef, tenantRef, orderRef, releaseRef, artifactDigest, deadlineEpoch, state, sideEffects), com tipos estritos, sem campos de autoridade adicionados, e identidade byte a byte após a codificação canônica do produtor R28. **Não muda** o contrato, o provider, os SHA esperados, o prazo original, o leitor independente PostgreSQL, o isolamento de compradores nem os gates.

O teste novo `probe-state-canonical`, sem rede, banco ou credenciais, aceita um envelope fictício canônico e recusa seis alternativas: campo extra de autoridade, chave state duplicada, espaço não canônico, reordenação, sideEffects como string e deadlineEpoch como string. A sequência HTTP/PG18 já existente é pré-requisito e não conta como teste novo. O procedimento não confere autorização operacional, login/MFA nativo, writer de contrato/briefing, destino CRM/Vendas, entrega pública ou publicação. Sites #38 e IN127-R05/R06 continuam NO-GO.


## R36 — Content-Type do estado e do HTML privados (CI-only)

O consumidor de release local R29 já exigia HTTP 200, limite de corpo, JSON R35 canônico, bytes e SHA de HTML exatos, mas ignorava o `Content-Type` recebido. Uma origem que servisse os mesmos bytes como `text/plain` podia produzir um recibo mesmo sem comprovar que o HTML seria apresentado como HTML ao comprador. A R36 exige o tipo exato emitido pelo provider R28: `application/json` para estado e `text/html; charset=utf-8` para HTML. Respostas com tipo ausente, diferente ou duplicado falham fechadas; o callback de streaming existente permanece limitado a 4.096/32.768 bytes.

O fixture negativo responde 200 em loopback com corpos pequenos, sem formulário: primeiro com `text/plain` nas duas rotas, depois com `Content-Type` esperado e outro `Content-Type` contraditório na mesma resposta. O ensaio exige quatro recusas (estado/HTML em cada variante) antes de emitir recibo. A composição R28/R29 com tipos normais é executada somente como regressão herdada, não como nova prova. Nenhuma nova fonte de autoridade: contrato e briefing oficiais, autenticação nativa, CRM/VEN, worker OnePage, publicação, URL pública e VPS continuam fora do escopo. Sites #38 e IN127-R05/R06 permanecem NO-GO.

## R37 — Cabeçalhos que alteram a apresentação privada (CI-only)

O leitor local R29 valida HTTP 200, corpo limitado, mídia R36 e SHA, mas um origin poderia devolver os mesmos bytes e MIME com `Content-Disposition: attachment` (download em vez de prévia visível) ou `Refresh` (navegação do navegador). Ambos invalidam a afirmação de que a origem foi observada como página HTML. R37 recusa **qualquer** uma dessas declarações na resposta final de estado ou HTML, mesmo quando os bytes são inofensivos. Não altera o contrato JSON, o renderizador, o recibo, a validade nem a autorização.

A fixture `landing_v2_presentation_header_provider_lab.php` só funciona em PHP CLI-server, loopback e GitHub Actions com APP_ENV=testing. O teste introduz quatro negativas novas (dois cabeçalhos × dois endpoints) com status 200 e tipos corretos; o restante da cadeia é pré-requisito, não teste novo. O provider R28 original não é alterado; links operacionais e origem externa permanecem inexistentes. Nenhuma sessão corporativa, aprovação oficial, API, CRM/Vendas ou publicação é demonstrada. Sites #38 e gates IN127-R05/R06 seguem NO-GO.


## R38 — estado corrente após observar o HTML do provider (CI-only)

O R37 prova que os cabeçalhos não alteram a apresentação, mas a antiga sequência de leitura do recibo consultava o provider uma única vez **antes** do GET dos bytes HTML. Se o estado mudasse de `APPLIED` para `PENDING` nesse intervalo, um estado antigo ainda poderia fundamentar a entrega, apesar de a revisão e o SHA estarem corretos.

A R38 mantém o contrato de oito campos do R28/R35 e os mesmos endpoints fixos: lê o estado, verifica os bytes HTML e relê o **mesmo estado atual** via uma segunda solicitação GET. Exige identidade byte a byte entre as duas respostas canônicas; timeout e orçamento permanecem os de R33. Nenhum retry, repetição de publicação, novo ACK, credencial ou configuração de provider foi introduzido. A mitigação reduz a janela entre as duas observações, mas **não constitui bloqueio transacional de estado**, nem autorização operacional.

O fixture adversarial roda exclusivamente como PHP CLI-server na porta loopback 8791 em VM descartável de CI, usando a role de leitura RLS existente. Mantém todos os identificadores, digest, HTML e deadline verdadeiros do mesmo pedido fictício: retorna `APPLIED` na primeira leitura e `PENDING` na segunda. A matriz confirma os dois estados e o SHA do HTML com chamadas separadas; então exerce `r29source()` e a tentativa de emissão, exigindo recusa, duas leituras e nenhuma nova linha na contagem PG18. A cadeia normal R28/R29 já existente deve permanecer verde na mesma SHA, como regressão, não como marco adicional.

A aprovação e o aceite são fixtures, o recibo continua sintético e não assinado, o formulário continua desligado e a URL é apenas local. Sem fonte oficial de contrato/briefing, identidade nativa, writer de autorização, destino CRM/Vendas ou publicação. **Sites #38 e IN127-R05/R06 continuam NO-GO.**


## R39 — recusar codificação de representação não autorizada (CI-only)

Lacuna concreta após R38: `r29http()` exigia HTTP 200, MIME e SHA dos bytes recebidos, mas não rejeitava `Content-Encoding`. Um provider pode declarar `gzip` ou `br` e transmitir corpo sem essa codificação. O leitor PHP poderia hashear o corpo bruto e tratá-lo como HTML visível, enquanto o navegador tentaria descompactá-lo, de modo que os bytes vistos pelo comprador deixariam de corresponder à evidência. **A R39 rejeita qualquer `Content-Encoding` explícito** nas duas respostas fixas (estado e HTML), sem modificar o contrato JSON R28 nem a política de autorização.

Fixture isolada `landing_v2_content_encoding_provider_lab.php`: somente loopback/CI, HTTP 200, MIME correto, dois modos `gzip`/`br`, corpo propositalmente sem compressão e sem dados reais. A prova usa `curl --raw` para confirmar a divergência declarada e então executa o consumidor original `probe-content-encoding`, exigindo recusa nas duas URLs, sem despachar publicação. São quatro casos negativos novos; as regressões R28–R38 permanecem pré-requisitos e não contam como novas provas.

**Limitações:** não demonstra navegador real renderizando esse conteúdo adversarial, não autentica comprador real e não substitui fonte oficial do briefing, identidade corporativa, writer de autorização, destino VEN/CRM, transporte assinado nem consistência transacional de estado. A correção é restrita ao cliente HTTP sintético. OnePage independente. Sem OpenAI, Asaas, VPS, deploy, DNS, merge, publicação ou dados reais. **Sites #38 e IN127-R05/R06 permanecem NO-GO.**


## R40 — exigir estado fresco e recusar cabeçalhos cacheáveis (CI-only)

Depois de R39, o leitor `r29http()` ainda aceitava respostas HTTP 200 para estado APPLIED e HTML v2 com `Cache-Control: public, max-age=600`, ou com declarações contraditórias `no-store` e `public`. As duas consultas de R38 poderiam, portanto, obter um estado intermediário cacheado, sem comprovar frescor. O cliente agora envia `Cache-Control: no-cache, no-store` e `Pragma: no-cache` e exige exatamente **um** `Cache-Control: no-store` na resposta, que é o contrato existente do provider local R28. Isso não altera a forma JSON, o SHA esperado nem cria autoridade para o recibo.

O novo provider fictício `landing_v2_cacheable_provider_lab.php` atende somente em loopback e em GitHub Actions. A prova separa as respostas de estado/HTML em dois modos adversariais: uma diretiva pública e duas diretivas incompatíveis. Para cada endpoint, confirma HTTP 200, corpo presente e o cabeçalho adversarial, depois exige que o consumidor original negue ambos. São quatro negativas novas, sem publicação, envio de lead, credenciais, acesso operacional ou chamada OpenAI. A regressão normal usa os cabeçalhos `no-store` do R28 e não conta como nova evidência.

**Limite:** `no-store` é uma defesa de política de cache, não atestado criptográfico nem garantia absoluta contra um intermediário hostil. O CI usa apenas loopback HTTP sintético e não demonstra HTTPS operacional, identidade nativa do comprador, contrato ou briefing oficial, transporte assinado ou publicação. Os gates Sites #38 e IN127-R05/R06 continuam NO-GO. OnePage permanece independente.
