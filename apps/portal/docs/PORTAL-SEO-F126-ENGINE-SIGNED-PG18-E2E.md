# Portal SEO F126 — assinatura real do Engine → verificação PHP → consumo único PostgreSQL 18

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação de [F125 #865](https://github.com/rafhael100/imagine-portal/pull/865) e [F123 #862](https://github.com/rafhael100/imagine-portal/pull/862).

## Fluxo integrado

A F125 testava a assinatura emitida diretamente pelo SEO Engine F121, mas não sua passagem pelo ledger PostgreSQL. A F123 comprovava RLS e uso único de envelopes gerados por um utilitário no repositório Portal. A F126 junta esses dois fluxos, usando o próprio emissor Engine e o banco real.

O workflow CI `.github/workflows/portal-seo-f126-engine-signed-pg18-ci.yml` fixa o commit exato do [Engine F121 #177](https://github.com/rafhael100/imagine-seo-engine/pull/177), `3c75b8e7b64f4173a17f4daab45cea1b8bdc5554`, e:

1. Cria um HTML sintético local em runner descartável.
2. Executa auditoria F114/F117 **dentro do Engine**, que gera o relatório e o envelope Ed25519 com chave privada temporária.
3. Entrega envelope e chave pública em arquivos separados, com permissões privadas, ao PHP.
4. O PHP verifica assinatura, commit, público-alvo, empresa, execução, prazo e contrato F118.
5. O PostgreSQL 18 recebe apenas digest de nonce, chave pública identificada por SHA-256 e campos de auditoria sintéticos, com FORCE RLS e conexão sem bypass.
6. O primeiro uso é aceito; replay, assinatura adulterada, chave errada, vencimento e empresa divergente são recusados. Dois processos concorrentes sobre o mesmo relatório geram **um único aceite**.
7. A auditoria de um build com prévia vazada ou idioma incorreto continua marcada como **REVIEW_BLOCKED**, mesmo após validação da assinatura.
8. Uma empresa não lê os hashes de outra; contexto RLS é exclusivo da transação; produção recusa o laboratório.

Matriz: PHP 8.3 e 8.4, Node.js 22, PostgreSQL 18 com migrações reais.

## Limitações

**Autenticidade do conteúdo assinado não equivale à autorização de exibição.** A chave pública da CI é o ponto de confiança **do teste**, não uma chave cadastral real. Emissor, cliente, tenant, MFA, data de validade e bancos são controlados pelo runner. Ainda faltam serviço real de assinatura com chave privada protegida, catálogo e revogação de chaves, confiança do Portal configurada no servidor, identidade real de empresa, autorização, política de retenção, logs e monitoramento.

O fluxo não implementa endpoints HTTP, login, publicação, OAuth, acesso à VPS, Google ou alteração em `main`. A captura ao vivo IBGE também permanece separada e não aprovada.

**Não usar resultados da F126 para clientes ou produção antes de AppSec/DBA/LGPD, homologação VPS, backups e rollback.**
