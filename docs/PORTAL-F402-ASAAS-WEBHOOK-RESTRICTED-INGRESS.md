# F1-402 — receptor Asaas Sandbox com login PostgreSQL independente

**Status: DRAFT / NO-GO / SEM DEPLOY.** A PR #757 registra a execução do Sandbox na VPS, mas essa evidência não equivale a aprovação de segurança ou produção.

## Correção de privilégio

O receptor anterior usava a conta `postgres` para abrir a sessão e só depois executava `SET LOCAL ROLE purchase_rehearsal_hook`. Esse comportamento concedia à entrada HTTP credenciais administrativas sobre a base.

A revisão adota `purchase_rehearsal_hook LOGIN` com senha independente e flags `NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION`. A senha é criada por `prepare.py` **somente ao inicializar um banco descartável novo**, em `.private/hook.pgpass` (proprietário UID 70, modo 0400). A aplicação Laravel não recebe essa credencial e o código versionado não contém tokens.

O comando de gravação chama `docker compose run --rm -T --no-deps webhook-broker ... -U purchase_rehearsal_hook`, em cliente PostgreSQL 18 de uso único. O serviço fica sob `profiles: [on-demand]`, sem portas, na rede privada Docker, usuário 70, sem capacidades, sem privilégios novos, sistema de arquivos somente leitura e montagem de senha somente leitura. O `docker compose up` normal não o inicia.

O SQL registra somente hash do ID do evento, tipo e referências técnicas. O campo `payment.status` ou `value` recebido no webhook não autoriza um pedido; o reconciliador financeiro deve consultar o Asaas independentemente. Eventos duplicados são idempotentes, e persistência indisponível retorna 503 para permitir entrega posterior.

O processo listener exige `APP_ENV=staging`, `ASAAS_SANDBOX_WEBHOOK_ENABLED=1`, ausência de `GITHUB_ACTIONS=true` e arquivo `.private/asaas-webhook.json` regular (sem symlink), próprio do usuário, modo 0600 e tamanho limitado. **Nenhuma flag é ativada automaticamente** por este recorte.

## Critérios de homologação

O CI valida a autenticação real do usuário SQL separado no PG18 descartável, a proibição de ler `customer_id` e a fila de intenções financeiras, o recebimento HTTP autenticado, duplicatas e indisponibilidade, além da navegação Chromium e reinício do banco. O receiver não é autorizado a fazer pagamentos, emitir notas, atualizar contratos ou publicar Sites.

**ATENÇÃO À VPS EXISTENTE:** a role da base atual pode estar como `NOLOGIN`, e um initdb usado nos testes NÃO altera bancos já criados. O operador/DBA deverá realizar uma migração separada com backup e rollback, provisionar/rotacionar segredo em armazenamento privado, validar autenticação no banco real, configurar o serviço com identidade de host mínima e testar recebimento HTTPS e retentativas. Não executar `ALTER ROLE` automático com dados sensíveis nem transmitir senhas em chat/GitHub. O serviço atual não deve ser reiniciado por esta revisão.

A pessoa que pode executar Docker no host ainda tem privilégios de infraestrutura; a conta SQL restrita sozinha não corrige isso. **O isolamento por cliente da PR #768 não foi incorporado ao ramo operacional #757.** Não aprovar release até reconciliar os dois ramos, garantir escopo individual nas tabelas de ordens, eventos, boletos/assinaturas e relatórios, e passar testes AppSec/DBA/LGPD.

**NO-GO** para merge amplo, deploy na Leaseweb, cobrança real, pagamento de cliente ou publicação Sites.
