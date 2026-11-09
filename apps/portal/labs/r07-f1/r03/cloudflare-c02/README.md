# SEC-R03-C02 — Cloudflare Durable Objects e R2: candidato local

**09/11/2026 — Código preparado e não implantado.** A Direção aprovou testes Cloudflare dentro dos limites gratuitos, sem publicação, despesas nem alteração dos serviços atuais.

## Escopo entregue

- src/protocol.mjs: protocolo Z4 **puramente sintético**, com geração, sequência CAS, estados DENY/READY/OPEN, revogação, invalidação de sessões, hash de backup e recibo de restauração. As flags NON_OPERATIONAL são fixtures forjáveis e jamais comprovam autorização humana ou identidade.
- src/worker.mjs: adaptador para Durable Objects com SQLite. A classe SyntheticFence inicializa um registro bloqueado (DENY), oferece apenas diagnóstico inofensivo via RPC; fetch público do Worker e do objeto sempre responde 404. **Não há RPC que permita avançar estado.**
- wrangler.jsonc: classe declarativa com storage sqlite, workers_dev=false, preview_urls=false, routes=[], crons=[]; não vincula domínio, conta ou bucket.
- src/r2-evidence.mjs: gera em memória nomes e conteúdo de objetos fictícios com SHA-256 e encadeamento referencial. **Não escreve no R2 nem cria bucket ou política de retenção.**
- test/protocol.test.mjs: 15 testes de segurança sintéticos em Node 22/24 com biblioteca padrão.
- .github/workflows/portal-sec-r03-c02-cloudflare-local.yml: lint e testes Node, sem instalar Wrangler ou executar Cloudflare.

## Importante sobre a prova

Esta entrega NÃO executou Durable Objects nem R2 na conta Cloudflare. O CI verifica a sintaxe do adaptador e a máquina de estados isolada, não o runtime workerd. O objeto Cloudflare somente inicializa DENY; transições sintéticas existem fora dele e **não podem ser expostas como RPC público ou endpoint operacional**.

É mantida a falha conhecida X03/X22: uma cópia antiga da própria Z4 pode novamente autorizar o ator fictício A já revogado na cópia atual. A PoC testa esse risco deliberadamente e registra seu resultado. Mesmo Durable Objects com SQLite e transações fortes possuem PITR; a aplicação precisa de uma âncora monotônica independente de restauração, segregação de administração e proteção contra split-brain.

R2 com objetos hash-encadeados não é imutabilidade por si só. Bucket Locks são administráveis e seus custos precisam de limites; R2 não substitui a autoridade de revogação.

## Próximas ações para usar conta Cloudflare

1. Inspecionar a conta e o orçamento existente, conferir a franquia Workers Free e eventual ativação/billing R2. **Não pressupor custo zero sem consultar a conta.**
2. Executar teste workerd/Miniflare apenas em runtime local, com bindings locais e dependências fixadas; nenhum uso de remote bindings.
3. Validar a configuração tanto para workers.dev quanto para preview URLs; publicar ou criar namespace exige liberação separada se isso criar superfície acessível/alterar recursos.
4. Para R2, preparar bucket privado com dados 100% sintéticos, limites de operações/armazenamento e sem acesso público r2.dev; ainda não criado.
5. Para teste em nuvem, usar uma conexão autorizada/ambiente operacional próprio de menor privilégio. **Não enviar tokens ou credenciais no chat.**
6. Produzir prova de CAS, revogação, rollback da própria Z4 e falhas de rede em ambiente realmente separado; registrar SHA, custos e risco residual.
7. Não abrir Portal para clientes sem proteção contra restore host inteiro, concorrência de todos os writers, reautenticação, auditoria e validação SEC/DBA.

## Fontes oficiais atuais

- Durable Objects e classes com SQLite: https://developers.cloudflare.com/durable-objects/reference/durable-objects-migrations/
- Storage SQLite transacional e PITR: https://developers.cloudflare.com/durable-objects/api/sqlite-storage-api/
- Configuração workers.dev e preview URLs: https://developers.cloudflare.com/workers/wrangler/configuration/
- Preços e franquia R2: https://developers.cloudflare.com/r2/pricing/
- Regras de Bucket Locks R2: https://developers.cloudflare.com/r2/buckets/bucket-locks/
- Desenvolvimento local e aviso de sandbox: https://developers.cloudflare.com/workers/local-development/

## Gates permanecem abertos

Sem merge, main, DEV, VPS, domínio, deploy, Cloudflare API, namespace ou bucket provisionado, dados reais, cobrança, CRM, Sites ou Performance AI. Nenhuma homologação SEC-R03-X01..X24. P0, identidade, RLS, T3, segurança, AppSec/DBA e decisão OPEN seguem bloqueados.
