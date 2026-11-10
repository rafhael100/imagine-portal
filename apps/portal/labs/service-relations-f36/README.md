# PORTAL-F36 — Paginação limitada de revogações pendentes no laboratório

**09/11/2026 — Somente Laravel/PHP e duas instâncias PostgreSQL 18 temporárias. Não é um worker produtivo.**

## Problema

O F35 grava um recibo de confirmação por evento no witness, mas sua
leitura de pendências ainda rejeita mais de **20 registros não confirmados**.
Essa recusa foi proposital para evitar processamentos silenciosamente
truncados. F36 ensaia páginas **limitadas e repetidas** para avançar
a reconciliação sem declarar sucesso se houver mais trabalho.

## Comportamento desenvolvido

- `F34SyntheticLedgerReader::readBatch(onlyUnacknowledged: true,
  pageMode: true)` mantém as verificações de papel SQL, FORCE RLS e
  limite inicial `LIMIT 21`, mas devolve **somente os primeiros 20**.
  O modo original F34/F35 permanece inalterado: continua negando
  lote maior que 20.
- `F36BoundedPagedAcknowledgementRunner` executa no máximo
  **cinco páginas de 20**, cada uma ordenada de modo estável pelo
  ledger do witness. Após cada evento, F33 reconfirma o evento
  publicado, aplica a revogação na primária, F35 verifica estado
  por identidade SQL do tenant e grava o recibo no witness.
- A página seguinte começa dos primeiros **ainda pendentes**.
  Não usa `OFFSET`, evitando pular os registros que desapareceram
  do conjunto `WHERE receipt IS NULL` após um ACK.
- Se uma publicação, escrita primária, verificação ou gravação de
  recibo falhar, o runner interrompe o lote e expõe status parcial,
  sem tentar pular registros nem informar conclusão.
- Depois das cinco páginas, uma **sexta consulta de inspeção**
  informa se ainda existem pendências. Em caso afirmativo,
  devolve `bounded_backpressure`, `completed=false`, mantendo
  os recibos já gravados e permitindo nova invocação.
- Nenhum cron, scheduler ou daemon é configurado. Cada rodada
  é chamada explicitamente no CI com dados fictícios, sem
  autorização de clientes.

## Testes previstos

`F36PagedAcknowledgementTest.php` contém cinco cenários:

1. modo página não aceita leitura da lista completa sem filtro;
2. 25 eventos em uma empresa atravessam duas páginas sem perdas,
   repetição concluída devolve zero;
3. 20 eventos da empresa A e três da B são conciliados com
   identidades SQL separadas;
4. lock em B bloqueia a segunda página, mantém recibos já
   confirmados para A e permite retomada após liberar a trava;
5. 101 eventos são limitados a 100 na primeira rodada e reportados
   como `bounded_backpressure`; a rodada seguinte processa o
   evento remanescente.

Os dois containers PG18 continuam com credenciais SCRAM efêmeras
e portas randomizadas em loopback. A suíte também preserva os testes
F22–F35 e o ensaio de `pg_dump/pg_restore` da F32.

## Limitações de segurança, disponibilidade e escala

- Mesmo 100 eventos por chamada é só um teto do laboratório,
  não uma capacidade comercial, SLA ou garantia de estabilidade.
  Sem ack/autoria verificados por autoridade independente,
  concorrência de múltiplos workers, leases/locks de consumo,
  retenção/purga de ledger, backoff, dead-letter, controle de
  custos, telemetria ou alerta.
- Uma fonte comprometida pode publicar eventos fictícios,
  e um ACK comprometido pode mentir sobre estado observado;
  a testemunha está no mesmo runner GitHub e pode ser
  restaurada pelo mesmo administrador.
- A ausência de fila persistente em serviço real, emissor
  confiável do Portal, MFA, testes de backup operacional,
  RLS homologado, integridade Ed25519 Portal–Performance e
  assinaturas de eventos ainda impedem qualquer uso produtivo.
- A biblioteca F36 não está registrada em rotas, service
  providers, agendadores, filas, n8n, CRM, Sites, Universidade,
  Performance, VPS ou Cloudflare.
- **PR DRAFT, sem merge/deploy em dev/main, nenhum cliente
  real ou gasto com fornecedor.** GitHub Actions pode consumir
  minutos do plano. P0/D02/D03, SEC-R03/Z4, AppSec/DBA
  e T3/OPEN permanecem abertos.

**Verificação:** somente marcar como aprovado quando CI PHP 8.3/8.4
e demais workflows executarem jobs e retornarem SUCCESS no
commit exato. Falha de plataforma sem job não é teste aprovado
nem reprovado por asserção.
