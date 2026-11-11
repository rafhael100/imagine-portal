# Portal F128 — Trilha de tentativa de revisão (somente CI)

A F127 bloqueia leitura sem permissão explícita e revogação válida, mas o operador de laboratório ainda podia executar a função de revisão sem deixar evidência da tentativa. A F128 introduz **apenas em PostgreSQL 18 descartável** um wrapper `review_and_audit_lab_a` que grava metadados técnicos de uma **tentativa de consulta**, depois delega a decisão para a F127.

## Proteção

- Revoga EXECUTE direto da função F127 ao revisor A; ele recebe exclusivamente EXECUTE da função auditada. O revisor B e os antigos workers não ganham novos direitos.
- A tabela `review_access_audit_lab` guarda somente identificador sequencial, UUIDs sintéticos de empresa/revisor, tipo fixo `synthetic_review_requested` e timestamp. Sem tokens, IP, cookies, e-mail, conteúdo de formulário, identidade real ou resultados de CRM.
- `SECURITY DEFINER` pertence ao papel `NOLOGIN/NOSUPERUSER/NOBYPASSRLS` criado pela F127, e a nova tabela tem `FORCE RLS` e policy de INSERT limitada ao tenant/ator fictícios. Nem revisor nem worker têm INSERT, UPDATE, DELETE ou SELECT.
- Cada **chamada efetivamente confirmada na transação** gera um registro, mesmo quando a F127 nega retorno por sessão revogada ou grant ausente. A função continua sem autorizar retry, ACK ou operação real.

## Limitação de auditoria essencial

A gravação da F128 ocorre **na transação da própria consulta**. Se o chamador fizer ROLLBACK, a tentativa registrada também desaparece. Portanto não equivale a trilha de auditoria independente/imutável de produção. O teste documenta expressamente esta limitação. A solução operacional precisará de logging independente, controle de acesso, retenção LGPD, integridade e revisão externa.

## Validação

A CI recompõe as etapas F117–F127 em PostgreSQL 18 descartável e testa matrizes PHP 8.3/8.4: leitura com grant, leitura negada com revogação ou grant errado, bypass direto proibido, tenant B recusado, nenhum DML do revisor, ausência de dados pessoais e rollback que desfaz o log. Os resultados continuam sendo apenas de fixture sintética.

**NO-GO:** sem endpoint Laravel, CRM real, credenciais, migração em produção, VPS, `main`, custo ou deploy. Requer revisão SEG/DBA/LGPD independente [#315](https://github.com/rafhael100/imagine-portal/issues/315).
