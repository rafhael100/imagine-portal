# Landing R27 — destino sintético vinculado, entrega de evento local e leitura independente (NO-GO)

Continuação isolada da Portal #1057, HEAD `77d1be2db2bcc45bc62909050025dca0531fe077`, CI da SHA concluído em 6/6. Sites #541/#542 históricas; #474 é somente transporte mTLS e a #580 não assina autorização. A cadeia v1 → ajuste → v2 → aceite v2 → mTLS e SHA servido continua como pré-requisito da #1057; **não é uma segunda prova**.

## Nova lacuna

Um destino fictício, **explicitamente vinculado por um comando de laboratório** em tabela PG18 com empresa, comprador, pedido, site, release v2 e digest do HTML aceito, só pode receber eventos quando o aceite e a aprovação fictícia **ainda estão correntes**. Antes do vínculo e depois da sua revogação, a rota interna recusa. A ligação tem validade máxima de cinco minutos. Nenhum endpoint do Portal ou do CRM é considerado destino implícito.

Dois processos HTTP independentes em 127.0.0.1:8780/8781 exercitam POST sintético **interno** e recebimento em inbox PG18. A função de recepção utiliza role SQL não proprietária, policy INSERT/SELECT com FORCE RLS; outro processo e outra role consultam o evento real persistido. A chave idempotente é composta por tenant, pedido, site, release e evento: repetição literal não cria segunda linha; payload diferente com a mesma chave é conflito HTTP 409. Tenant, release e digest divergentes são negados. O `/lead` público recebe sempre HTTP 403, mesmo após um vínculo de laboratório.

**Crítico:** esta prova não ativa formulário. O HTML v2 aceito permanece com formulário desligado; ligar um formulário alteraria bytes/SHA e exigiria nova revisão e novo aceite. `/lab/synthetic-send` é ferramenta descartável e não fluxo de visitante; o destino `ci-local-inbox-only` é explicitamente **não operacional**, não é CRM/Vendas, não é VEN autorizada. O vínculo sintético demonstra apenas disciplina técnica, não autorização oficial, contrato, MFA, publicação ou entrega comercial.

O CI de R27 retoma a cadeia de Portal #1057 em PostgreSQL 18, PHP 8.4 e Chromium, sem novos segredos, instala apenas o ambiente efêmero já utilizado e executa negativos antes/depois do vínculo, repetição, conflito e readback. O CI documenta precisamente qual SHA foi lida, e deve ser conferido pela HEAD da PR antes de declarar êxito.

**Não executado:** destino VEN/CRM operacional autorizado e recente, ativação de formulário na release assinada, transporte remoto autenticado, lead real, publicação, troca/reversão operacional de release, reconciliação após perda de ACK IN127-R05 e IAM/contrato/briefing oficiais. Sites #38, IN127-R06 e todas as alçadas reais seguem **NO-GO**. Nenhuma VPS, DNS, credencial, API OpenAI, Asaas, produção ou `main` alterada.
