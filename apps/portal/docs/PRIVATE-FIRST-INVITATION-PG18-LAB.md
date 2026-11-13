# Portal Imagine — convite inicial em laboratório PostgreSQL 18

**Estado: implementação e testes sintéticos; não é cadastro de operador real, nem provisionamento operacional.** Continuação da PR #596 (login privado) em PR filha exclusiva de revisão.

## Escopo entregue

`Tests\\Support\\DisposableFirstInvitationBootstrap` executa uma única transação SQL em banco **descartável** `portal_browser_lab`, pelo papel administrativo `postgres`, validando explicitamente as variáveis de laboratório, driver PostgreSQL, banco e identidade da conexão. Rejeita e-mails não terminados em `@example.invalid`, referências malformadas, entrada com caracteres de controle, conexão herdada e banco previamente populado.

A transação adquire locks exclusivos sobre as seis tabelas de identidade e sessão e a tabela de chaves privadas antes de conferir que todas estão vazias. Reserva UUIDs novos no próprio PostgreSQL e insere:

- organização `active`, apenas para dar contexto ao convite;
- identidade `invited`, **sem senha**, sem verificação de e-mail, sem segredo TOTP e sem MFA confirmado;
- membership `invited` de papel `owner`, **sem grants de permissão**;
- **nenhuma** sessão, token, credencial ou acesso comercial.

Uma segunda execução não pode criar outro convite, mesmo que o endereço de e-mail seja diferente. Falhas retornam diagnóstico genérico, sem vazar SQL nem dados do operador, e abortam a transação.

O workflow `portal-browser-identity-pg18.yml` roda, em PHP 8.3 e 8.4, a suíte `DisposableFirstInvitationPg18Test` e os testes existentes de autenticação/MFA/isolamento.

## Segurança, limites e atividades ainda necessárias

1. **Não há código invocável pela aplicação**, serviço registrado, comando Artisan, endpoint, importação de cliente nem integração com CRM. A classe está no namespace de testes e possui guardas de laboratório. Não usar este teste com credenciais reais, nem convertê-lo em um comando operacional sem nova revisão.
2. **A conta continua inativa.** Para operador real, a direção deve indicar e validar organização e endereço de e-mail; uma prova confiável de posse do e-mail e o cadastro de senha com segredo introduzido privadamente precisam ser implementados. A confirmação e o CAS de MFA devem ocorrer antes de qualquer promoção da identidade e membership a `active`.
3. **Sem autorização de negócio por papel.** `owner` sozinho não concede `services.view`, `billing.view` ou qualquer recurso. O trabalho de RLS/tenant e #341 continua pendente.
4. **A restauração ainda pode reviver estado antigo.** Issue #64 exige uma autoridade de revogação externa independente, garantia monotônica, quarentena operacional de banco/filas/cache e revisão AppSec/DBA. Este laboratório não atenua o risco de rollback de produção.
5. Não são executados `migrate` ou SQL em VPS, banco compartilhado, ambiente de staging real ou produção. Não foi enviado e-mail ou criado operador real, não há dados pessoais nem despesas novas.

**Política de liberação:** PR em DRAFT, sem merge/deploy automático, sem primeira conta real e sem alteração de `main`.

## Regressões de segurança adicionais

A rotina verifica também `portal_browser_private.proof_key`: qualquer material de assinatura previamente armazenado torna o laboratório inadequado para uma primeira conta e impede a operação inteira, mesmo quando as tabelas de identidade estão vazias. Um teste mantém uma transação concorrente com lock exclusivo e confirma que a segunda conexão recebe erro genérico dentro do timeout, não escreve dados parciais e só consegue reservar o convite após a liberação do lock. Isso verifica o uso de locks neste laboratório, mas não garante exclusividade depois de um restore operacional da base.
