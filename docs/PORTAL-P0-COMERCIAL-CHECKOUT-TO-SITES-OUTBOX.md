# P0 — Comercial → Portal (Laravel) → Sites (PostgreSQL outbox) — NO-GO

## Evidência que este laboratório produz

A partir dos **quatro links reais do site Astro compilado**, o navegador passa ao formulário HTTP do Portal Laravel e finaliza quatro pedidos **fictícios** no PostgreSQL 18 do Portal. A base contém um Onepage, uma Landing Page e dois caminhos de Performance (Google/Meta). O briefing é salvo e lido em nova conexão.

Uma segunda fase, no **mesmo runner descartável do GitHub**, lê os quatro pedidos por um **login SQL restrito**, reconhece os dois produtos elegíveis, produz um contrato **artificial e explicitamente marcado para teste**, assina um comando de CreateProject com as classes Portal existentes e passa por verificadores Sites independentes. O Sites PostgreSQL 18 exige login vinculado a tenant, **nonce durável**, assinatura e dois atestados **sintéticos**, commit de admissão e evento outbox na mesma transação.

Resultado esperado: **dois projetos de teste enfileirados com dois eventos outbox**, retry com nonce novo retorna duplicado sem novo evento; replay do nonce antigo é recusado. Dois pedidos Performance não ingressam no Sites. Pedido pendente e tenant assinado divergente são recusados.

## Fontes fixadas

- Comercial Astro: `b0aefbd01f55cbcdb1653a798f05a83b866e3d33`
- Portal checkout Laravel: candidato empilhado sobre PR #680 e #672
- Portal signer CreateProject de laboratório: `bf1b2794de4d6000f120ffa2b1e6dd557f34c35e`
- Sites verificações de assinatura sintética: `21732e9558b730e38a6845adb593d29f309ff6a9`
- Sites PostgreSQL 18/admission/outbox/nonce: `3d20d83d544577a70c091ca1725c084ab6824d2a`

Os SHA são inspecionados no próprio workflow. A fonte do Portal de assinatura e o checkout não constituem uma única release aprovada; a junção ocorre **apenas dentro da runner efêmera**.

## Garantias e limites

- As duas bases PostgreSQL 18 são criadas e descartadas no CI. Nunca são migradas para a VPS.
- Login do Portal de teste `purchase_rehearsal_a` exige `FORCE RLS`; o Sites `sites_f360_b` exige vínculo SQL `tenant-f360-b`. O vínculo entre ambas as organizações é **fictício e fixo** no código de teste.
- "Pagamento aprovado" é um cenário fake, **não comprova preço, contrato, pagamento ou permissão real**. Os atestados de Portal e revogação externa são **falsos dados assinados com chaves de demonstração**; não há witness operacional. O CI não cria cliente real, não publica sites, não despacha outbox para Workers e não chama API real do Sites.
- Um nonce reusado, um pedido ainda pendente, um produto não elegível ou um tenant externo incompatível devem falhar **antes de autorizar qualquer publicação**.
- Não usar este laboratório como sinal para deploy; ficam pendentes: IAM e MFA com escopo real, permission/grant verificável, testemunha anti-rollback, contratos e autorização assinados, vínculo entre bancos, preço/PSP, operação de fila, recuperabilidade e revisões AppSec/DBA.

**NO-GO para produção.** O valor da entrega é evidenciar contratos de interface, persistência, replays e negações end-to-end de duas aplicações com bancos reais, sem abrir acesso público.
