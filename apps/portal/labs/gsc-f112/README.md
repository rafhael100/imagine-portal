# GSC-F112 — Serialização de revogação concorrente (laboratório PG18)

## Motivação

A F111 revalida o vínculo da sessão e dos grants no callback, mas essa revalidação era uma leitura comum. Um UPDATE/DELETE concorrente podia ocorrer entre a leitura e a entrega do PKCE cifrado.

## Implementação isolada

- Função de guarda F112 em PostgreSQL 18 usa SELECT ... FOR SHARE das **seis linhas** relevantes: sessão, identidade, organização, membership, grant SEO e propriedade candidata da empresa.
- A emissão F112 adquire esses row-locks **antes** de chamar o emissor F111; o consumo F112 queima o state via F111, revalida o IAM e adquire locks **antes de liberar ciphertext**.
- A escrita de revogação que usa UPDATE/DELETE sobre as mesmas linhas precisa aguardar a transação que detém o lock, ou a emissão/callback precisa aguardar uma revogação anterior e reavaliar os dados.
- A role de execução continua EXECUTE-only. O proprietário da função de guarda é NOLOGIN/NOSUPERUSER/NOBYPASSRLS. O PostgreSQL exige UPDATE em pelo menos uma coluna para SELECT FOR SHARE: foi concedido somente UPDATE(updated_at) a esse owner nas cinco tabelas de identidade; políticas UPDATE são **exclusivas do banco descartável de CI**, mantendo acesso nulo a status, segredo, grants e outras colunas sensíveis.
- O cliente perde EXECUTE direto nos caminhos F111 issue/consume, impedindo contorno dos locks.
- Testes utilizam duas conexões PDO concorrentes e lock_timeout curto para demonstrar que uma revogação de sessão ou grant não finaliza durante a transação de callback, que revogações já confirmadas bloqueiam novas operações e que estados consumidos não retornam depois de restaurar um grant.

## Limites

Não reproduzir os GRANT UPDATE ou as policies RLS desta prova na produção sem projeto de segurança específico e revisão DBA. O contrato em labs/ segue SEM login operacional, autenticação HTTP, consentimento, OAuth Google, token endpoint, cofre/KMS ou credenciais reais; dados e chaves sintéticos. Um teste de lock não equivale a prova formal de todas as interleavings, nem cobre ações externas ao banco. Há custos de contenção se várias pessoas acessarem a mesma empresa; será necessário medir sob carga e definir isolamento, timeouts e retry fail-closed.

**Status: draft NO-GO**, sem main, deploy, DNS, dados reais ou fornecedores. Revisão AppSec/DBA independente obrigatória antes de qualquer acesso de cliente.
