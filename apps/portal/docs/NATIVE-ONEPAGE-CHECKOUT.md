# Pedido nativo Onepage e recebimento persistente no Sites

Base: Portal #940 (`1b394f9826f623dec02beffcd2528b6a17d0c51e`).
Recebedor pareado: [Sites #512](https://github.com/rafhael100/imagine-sites/pull/512),
commit `9b80387fe1c580436b444a6aad2b63f3baafa37d`.

## Comportamento implementado

O núcleo `OnepageOrderStore` cria a cotação, recupera o rascunho, apresenta os
termos publicados, registra o aceite exato e consulta o pedido do próprio
comprador. O armazenamento usa `portal_onepage`, com a identidade, MFA, empresa,
vínculo e revisões da autenticação nativa já existente. Cada operação revalida
essa autoridade no PostgreSQL; o navegador não fornece comprador ou empresa.

O pedido aceito conserva oferta, catálogo, publicação, termos, contrato, seus
digests e o instante UTC do aceite. A empresa possui um UUID interno e uma
referência de integração distintos: `companyReference` é o `tenantRef` enviado
ao Sites. O UUID interno não substitui essa referência.

Os wrappers anteriores passam a consumir o mesmo núcleo SQL por `CoreSchema`.
Os guards dos bancos descartáveis continuam presentes. Termos sintéticos só
existem na projeção explícita do laboratório; o leitor nativo usa uma fonte
publicada separada e não aceita `lab-*`, `synthetic_only` ou política indefinida.

## Publicação e aceite dos termos

O ledger de publicações nasce vazio. `portal_onepage_publisher` pode publicar
ou revogar uma versão; `portal_onepage_runtime` não recebe esse poder nem acesso
direto às tabelas. Ambos começam `NOLOGIN` no contrato SQL.

A revisão congela uma única publicação ativa que corresponda exatamente à
oferta calculada no servidor. Ausência ou ambiguidade impede a revisão.
`payment_policy` deve ser uma string; o bridge financeiro exige ainda a política
versionada completa descrita em [ONEPAGE-ENTRY-PAYMENT-BRIDGE.md](ONEPAGE-ENTRY-PAYMENT-BRIDGE.md).
O aceite precisa corresponder ao ID da cotação e aos digests/revisão apresentados.
Uma alteração de conteúdo, campo extra ou valor com tipo diferente é recusada.

As versões e os pedidos aceitos são imutáveis. A revogação é registrada
separadamente. Locks nas fontes de identidade e publicação impedem que uma
revogação seja ignorada entre a autorização e a gravação correspondente.

## Transporte privado

O provider registra as rotas apenas com `PORTAL_ONEPAGE_ENABLED=true`, a
autenticação nativa habilitada e a configuração privada exata do Portal de
desenvolvimento. O padrão permanece desabilitado. As condições de ambiente e
cookie são conferidas novamente em cada requisição, inclusive em workers vivos.

Prefixo: `/_development/commerce/onepage`.

| Método e caminho | Entrada | Resultado autorizado |
| --- | --- | --- |
| `POST /quotes` | `request_key`, `selection` | Rascunho com cotação do servidor |
| `GET /quotes/{quoteId}` | Sem parâmetros de consulta | Rascunho do comprador atual |
| `GET /quotes/{quoteId}/review` | Sem parâmetros de consulta | Termos, contrato e digests da revisão |
| `POST /quotes/{quoteId}/accept` | `request_key`, `acceptance` | Pedido `pending_payment` |
| `GET /orders/{orderId}` | Sem parâmetros de consulta | Snapshot do pedido aceito próprio |

A seleção aceita exclusivamente `site=onepage`, `channels=[]` e
`tier=minimum|recommended`. O aceite contém `accepted=true`, `quote_id`,
`offer_digest`, `terms_digest`, `contract_ref`, `contract_revision` inteiro e
`contract_digest`. O corpo HTTP não pode adicionar empresa, comprador, preço ou
uma declaração de pagamento.

O transporte usa a referência opaca da sessão de negócio no servidor. Origem
HTTPS exata, CSRF, limite de JSON e cabeçalhos privados vêm do guard existente.
Respostas filtram campos internos e não incluem SQL, credenciais ou referências
de sessão. Negativas de recurso são neutras; falhas do backend não liberam acesso.

`PORTAL_ONEPAGE_CONNECTION_FILE` aponta para um arquivo privado separado,
por padrão `/run/secrets/onepage-runtime.json`. Ele contém exatamente `origin`,
`host`, `port`, `database` e `password`; host, porta e base devem coincidir com a
autenticação nativa. O papel é fixo em `portal_onepage_runtime`. O carregador
recusa permissões abertas, links, tamanho excessivo, campos extras e reutilização
de segredos da identidade. O provider não instala SQL nem concede LOGIN.

## Entrada e passagem para Sites

O binding identifica a **primeira parcela** (`setup-1`, R$330), com o contexto
imutável do pedido e calendário esperado. As parcelas D30/D60 têm outra
referência apesar do mesmo valor. Registrar um binding não registra recebimento.
O verificador existente exige leitura atual do pagamento pelo ID vinculado,
status `RECEIVED` e correspondência dos campos financeiros; relê o vínculo
nativo depois dessa consulta. Sua comparação atual não inclui a `dueDate`
remota: a data esperada é validada pelo ledger, não reconciliada pelo GET.

`PostgresPortalSiteContractSource` relê o pedido autorizado e alimenta o encoder
`CreateProject v1`. A prova pareada usa esse comando no recebedor real do Sites,
que grava projeto, outbox bloqueado e recibo na mesma transação. O mesmo comando
recupera o mesmo `siteRef` depois de reconectar; conflitos não criam outro projeto.

O recibo registra uma solicitação pendente. Não concede autorização financeira,
não valida briefing, não inicia produção e não aprova publicação. A regra de
negócio permanece: primeira parcela confirmada libera briefing; produção depende
da validação do briefing/material; publicação exige aprovação separada do cliente.
Quitar as três parcelas não é uma condição adicional para abrir o briefing.

## Prova e alcance

`NativeOnepageSitesPairPgTest` reutiliza a preparação e o pedido de
`NativeOnepageOrdersPgTest`. Executa nove migrations reais de identidade Portal,
senha e TOTP reais de teste, termos publicados, aceite, ledger esperado da entrada
e onze migrations do Sites em outra base do mesmo PostgreSQL descartável.
O receiver é `PortalProjectIntakeRecorder`, sem implementação substituta.
Os 24 arquivos de origem do Sites têm hashes fixados no manifesto do teste.

O workflow `portal-native-onepage-pg18.yml` verifica PHP8.3/8.4 e a versão exata
do Sites. Localmente, PHP8.4.27/PostgreSQL18.7 aprovou a cadeia pareada com
283 asserções. Os testes HTTP usam um store double explicitamente identificado;
a autoridade, propriedade e persistência são comprovadas na cadeia PG real.
O pagamento PSP nessa execução é simulado e não faz rede.

Este incremento entrega núcleo, transporte privado opcional e compatibilidade
persistente entre os componentes. Ainda não ativa o transporte servidor a
servidor, o worker financeiro, o envio automático ao Sites ou o acompanhamento
desse recibo na interface do cliente. A tela anterior de ensaio não muda de
significado por causa desta PR. Briefing de produção, geração, aprovação,
publicação e entrega precisam consumir suas próprias evidências oficiais.
Merge, instalação de ambiente e cobranças reais não foram executados.
