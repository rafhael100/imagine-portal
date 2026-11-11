# PORTAL-INT-F164 — Controle operacional de sessões no Portal Node.js existente

O projeto Imagine Portal possui duas linhas de execução distintas: a aplicação Node.js/SQLite existente (com rotas e testes reais) e a fundação Laravel/PostgreSQL ainda em revisão, negando acesso operacional. As etapas F160–F163 reforçaram a futura fundação PostgreSQL. Nesta etapa, **melhoro uma característica concreta do Portal Node.js**, sem ligar ou misturar os emissores.

## Problema resolvido

A rota Node `/api/login` cria uma sessão com vencimento de 12 horas, mas anteriormente cada novo login apenas adicionava outra linha à tabela SQLite `sessions`, sem limite por usuário ou limpeza de sessões vencidas. Isso permitia acumular registros e manter numerosas credenciais ativas em paralelo.

## Implementação

Em `server/app.mjs`, a função interna `session(res, user)`, usada por cadastro, login e recuperação após troca de senha, agora usa uma transação SQLite `BEGIN IMMEDIATE` para:

1. Eliminar globalmente apenas sessões com `expires_at <= Date.now()`;
2. Preservar no máximo **7 sessões ativas anteriores da mesma conta**, eliminando as mais antigas por vencimento e hash (ordenamento determinístico);
3. Criar o novo token de alta entropia e CSRF normalmente, deixando o limite final em **8 sessões ativas** por usuário;
4. Efetuar commit antes de emitir o cookie. Se o banco falhar, dá rollback sem revogar sessões válidas ou enviar cookie que não existe.

Não toca em contas de outros clientes, não altera a duração de 12h nem as propriedades `HttpOnly`, `SameSite=Lax` e `Secure` em produção. A troca de senha continua revogando todas as sessões antigas antes de emitir a nova.

## Testes

A suíte Node `tests/portal.test.mjs` ganhou casos que exercitam dez logins da mesma conta, confirmam limite de oito sessões, expiração de token antigo, preservação de acesso da segunda conta, remoção de sessão vencida e revogação de todas as sessões quando a senha muda (com confirmação de que senha antiga não funciona mais).

**Limites:** esse controle vale **somente** para o servidor Node/SQLite atual; ele não instala PostgreSQL, autenticação Laravel, sessão compartilhada, SSO ou políticas RLS. O gate independente de segurança e homologação continua obrigatório antes de qualquer merge operacional, publicação ou migração de clientes. Nenhum cliente/credencial real foi acessado, não há despesas e `main/dev` permanecem intactos.
