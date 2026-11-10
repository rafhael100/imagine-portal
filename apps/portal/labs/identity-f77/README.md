# PORTAL-F77 — limitação de tentativas de autenticação persistida no PostgreSQL

## Entrega

A migração `2026_11_10_000005_create_portal_auth_throttle_buckets.php` define `portal_auth_attempt_buckets`, com chave composta (`scope_hash`, `action_code`) e contador por janela. A tabela registra **somente um HMAC SHA-256 irreversível gerado por servidor** e a ação (`login`, `totp` ou `recovery`); não armazena nome, e-mail, telefone, IP, senha, cookie ou bearer. Possui `FORCE ROW LEVEL SECURITY` e **zero policies**.

A função `portal_f77_claim_auth_attempt` encontra-se **apenas no SQL de laboratório** `labs/identity-f77/auth_throttle.sql`:
- papel não-superuser `portal_f77_throttler` pode executar a função, mas não consultar ou alterar a tabela;
- `SECURITY DEFINER` com `search_path` fixo, função criada e retirada de `PUBLIC` na mesma transação;
- `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` serializa incrementos concorrentes no próprio PostgreSQL e permite 5 tentativas em 15 minutos; a 6ª e seguintes retornam falso;
- janela é medida pelo relógio de início da instrução PostgreSQL, evitando divergência entre campos; o contador é limitado a 6, sem crescimento infinito;
- escopos e ações desconhecidos falham fechados; cada ação tem contador independente.

## Evidência verificável

Workflow PG18 PHP8.3/8.4 aplica migrações F66/F76/F77 em banco descartável, testa as provas anteriores de autorizações e executa os testes de limite:
- cinco chamadas consecutivas por **conexões distintas** retornam true; a sexta retorna false e o contador fica em 6;
- HMACs e ações diferentes não compartilham contador;
- a role com EXECUTE não lê tabela e a role comum não chama a função;
- registros inválidos, scope uppercase e ação com texto de SQL injection são recusados;
- janela vencida (simulada por admin do CI) volta a permitir uma tentativa;
- bloqueio de linha transacional impõe timeout (erro) e a tentativa não é aceita nem contabilizada; após liberação, a próxima chamada pode avançar;
- nenhuma policy RLS permissiva aparece; rollback limpa a função e a tabela.

## Requisitos antes de qualquer uso real

O servidor precisará gerar um `scope_hash` independente do cliente usando segredo de pepper protegido externamente. É necessária uma estratégia combinando tentativa por identidade, por origem/IP e dispositivo, evitando tanto brute force distribuído quanto bloqueio arbitrário de terceiros. Precisa também de rate-limit no proxy, controles de privacidade/LGPD, limites de recuperação, monitoramento, clock e política de retenção/expurgo.

O papel com EXECUTE ainda pode disparar contadores para qualquer hash conhecido, portanto a função **não é apropriada como RPC pública**, nem fica em `database/migrations`. Não existe login web, verificação de senha/MFA integrada, identidade de cliente, política RLS operacional ou autorização de liberação. O banco descartável do CI não é homologação VPS e restauração de backup pode reduzir contadores. SEC-R03 antirollback continua pendente.

**PR DRAFT, NO DEPLOY/NO MERGE. Nenhum custo novo, cliente, PSP, anúncio ou provedor foi ativado.**
