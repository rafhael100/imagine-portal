# PORTAL-PAY-05 — Persistência candidata isolada PostgreSQL 18

Este laboratório exige PostgreSQL 18 **descartável**, sem host port e sem dados reais. Não é uma migração operacional. A CI cria ambiente do zero, aplica \`schema.sql\`, executa \`prove.sql\` e destrói a instância.

### Provas de referência
- \`policy_revisions\`: uma versão por organização, política JSON, digest e referência da revisão. \`state = review_only\` evita inferir política ativa;
- \`payment_intents\`: organização + chave idempotente, gateway original imutável no futuro, modalidade, política revisada e estados candidatos;
- \`gateway_event_inbox\`: identidade de evento única por \`(gateway,event_key)\`, HMAC do corpo e vínculo opcional com intenção;
- todas tabelas com **ENABLE + FORCE ROW LEVEL SECURITY**, **nenhuma policy** e **nenhum grant para a aplicação**;
- role sintética temporária com SELECT, mas sem policy, enxerga zero linhas mesmo havendo linhas fictícias no banco.

A CI NÃO confirma que o sistema está pronto para dinheiro real. Falta migrador auditado, roles de escopo mínimo, políticas tenant-bound verificadas, gravações e transições duráveis com bloqueio, credenciais em vault, rotas autenticadas, webhooks confirmados e conciliação. Nunca aplicar este script em banco de produção.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE.**
