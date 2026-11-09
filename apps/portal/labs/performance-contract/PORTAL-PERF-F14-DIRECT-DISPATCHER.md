# PORTAL-PERF-F14 — Despachante de outbox de laboratório diretamente por HTTP

**Referência:** 09/11/2026, issue #110, branch filha de F13 / PR #109.

## Prova do produto em desenvolvimento (somente CI)
O Portal produz um evento real **dentro da suíte sintética** com `LabPerformanceRevocationPublisher`, que valida sessão MFA e registra a alteração de serviço e o evento na mesma transação. O despachante `dispatch-loopback-outbox.php` agora **lê a própria outbox PostgreSQL do Portal**, não um arquivo que o teste exportou. Ele confere os campos, empresa e revisão da autoridade fictícia; assina o JSON com protocolo F12 e envia POST exclusivamente a `http://127.0.0.1:18784/_synthetic/perf/revocation`.

Seu reconhecimento `202 revocation_applied_synthetic_only` ou o código explícito `409 wire_event_replay_denied` permitem marcar o evento como `acked` com outro papel SQL de **mínimo privilégio de laboratório**. O role de leitura tem RLS restringindo ao serviço de teste B. O role ACK só consegue atualizar `status` e `attempts` da outbox fictícia. Nenhuma credencial de usuário, mídia ou pagamentos participa.

Quatro modos exclusivos do teste permitem provocar: transporte indisponível sem envio, confirmação perdida após aplicação, retransmissão com nonce novo e entrega normal. A falta de ACK preserva `pending` para tentar novamente; duplicatas usam identidade do evento e não repetem revogação local.

## Importante
A autenticação HMAC é **chave efêmera compartilhada pelo CI** e o ACK HTTP não é autenticação operacional. Não há TLS/mTLS, identidade de workload, chaves de produção, controle real de contratos, autorização comercial, tolerância a falha física ou efeitos remotos. O receptor Performance F1-15 mantém tudo em outro banco temporário no mesmo runner GitHub. O cenário de revogação apenas é monotônico; o mecanismo real de autorizações e reativações precisa de design específico.

Sem qualquer rota Laravel em produção, API Google/Meta, VPS, dados reais, cobrança, custos novos, merge ou deploy. P0, HT-R01 e SEC-R03/T3 continuam abertos. PR em rascunho e gates inalterados.
