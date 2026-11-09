# Portal Comercial F6 — Laboratório integrado PHP → PostgreSQL → Astro

## Objetivo
Validar um fluxo único de conteúdo público com assinatura criptográfica e recibo transacional **no ambiente descartável do GitHub Actions**, sem API nem publicação real.

## Sequência do workflow
1. A rotina PHP (F3) gera uma carga pública sintética, aprovada por atores fictícios e assinada por chave Ed25519 efêmera.
2. O Node calcula o SHA-256 dos bytes exatos dessa carga, fixando release ID + digest para a compilação Astro (F14).
3. O banco PostgreSQL 18 (F5) cria o ledger experimental, grava release, aprovação de um segundo ator e outbox pendente de um terceiro ator com **o mesmo hash produzido pelo PHP**.
4. SQL confirma correspondência de chaves compostas por organização/release/hash, segrega papéis, prova escopo de leitura RLS entre empresas e não permite enfileirar sem aprovação.
5. O Astro compila conteúdo assinado e emite HTML estático, validando títulos e descrições esperados.
6. O último build propositalmente usa hash diferente e precisa ser rejeitado; nenhuma regra é publicada.

## Limites críticos de segurança
Os atores e a organização usados no SQL são **constantes sintéticas**, e o pipeline de CI roda como administrador apenas do banco efêmero. O teste não prova a identidade real, nem a segurança de uma aplicação que controla livremente variáveis de tenant. O hash é calculado pelo pipeline de teste, não escolhido por um aprovador autenticado independente.

Ainda é necessário construir autenticação MFA, concessões por empresa com revogação, writer transacional de produção, aprovação persistente e outbox worker com autorização no momento do efeito, controle de replay pós-restore, chaves em cofre, política de rollback e integração real ao Portal.

Nenhum CRM, pagamento, DNS, Cloudflare, domínio, banco, API ou VPS de produção foi modificado. O teste não cria rotas HTTP nem realiza deploy.

Referências: Portal #119, #121, #123, #131, #133 e Imagine Comercial #16.
