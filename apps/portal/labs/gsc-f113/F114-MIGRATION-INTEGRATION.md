# GSC-F114 — Reutilizar a migration F113 na cadeia completa de autorização

A linha de testes F110–F112 antes alterava manualmente, só no banco descartável, a restrição de permission_code para inserir o código seo.gsc.connect. Isso poderia ocultar divergência com a nova migration de aplicação F113.

Esta etapa retira a alteração SQL duplicada e passa a executar, em PostgreSQL 18 efêmero, a **migration Laravel F113 real** antes de instalar os laboratórios de autorização F110, callback F111 e locks concorrentes F112. O CI prova que a constraint criada pela migration contém o escopo exato, que o fluxo F110-F112 ainda passa e que o rollback retorna a constraint original após eliminar os grants exclusivamente sintéticos do runner.

A única alteração em código de produção introduzida pela linha permanece a migration F113 (sem deploy). A F114 apenas elimina duplicação em fixtures e integra testes. O banco de produção continua sem políticas de acesso OAuth, sem client ID Google, sem KMS, sem token exchange e sem endpoints. A permissão seo.gsc.connect **não** é concedida automaticamente.

A revisão independente AppSec/DBA, a instalação do backend autenticado e a homologação da VPS continuam requisitos obrigatórios antes de publicação.
