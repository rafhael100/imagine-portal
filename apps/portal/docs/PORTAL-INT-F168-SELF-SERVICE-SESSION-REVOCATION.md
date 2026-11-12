# PORTAL-INT-F168 — Encerrar sessões em outros aparelhos

## Recurso real do Portal Node/SQLite

A F164 limita a oito os logins ativos de uma conta. O próximo recurso de segurança permite ao próprio usuário **encerrar acessos em outros aparelhos**, sem precisar trocar a senha e sem encerrar a sessão atual.

## API e controles

`POST /api/sessions/revoke-others` exige:
- Cookie de sessão válida e usuário carregado de `sessions` no servidor;
- `Origin` igual ao domínio configurado e `X-CSRF-Token` correto, por reutilizar os controles globais de escrita da API;
- Campo `currentPassword` verificado com o hash atual no servidor;
- Limite de 5 tentativas por conta/hora.

Dentro de `BEGIN IMMEDIATE`, a API executa somente `DELETE FROM sessions WHERE user_id=? AND hash<>?`. Assim, revoga tokens da mesma conta que não são o token atual, não toca nas sessões de outros usuários e evita logout acidental do solicitante. Em caso de falha da transação, faz rollback sem retornar sucesso. Uma auditoria registra `sessions.revoked_others` com a própria identidade.

`server/team.mjs` autoriza este autoatendimento a funcionários com perfil restrito **somente por meio da rota específica**; não amplia qualquer permissão de finanças, projetos ou outros clientes.

## Interface

- Página **Minha conta** do cliente (`account-ui.js`) ganhou a seção **Dispositivos conectados**, com confirmação de senha e botão para encerrar outras sessões.
- Página de conta administrativa e da equipe (`portal.js`) possui o mesmo recurso.
- As duas interfaces usam a função POST existente, bloqueiam o botão enquanto processam a operação, limpam o campo de senha após sucesso e exibem confirmação, sem expor tokens ou lista de sessões.

## Testes

`tests/portal.test.mjs` cobre login em dois aparelhos, negação por senha errada, negação por CSRF incorreto, preservação da sessão atual, revogação da anterior, isolamento de outro cliente, auditoria e acesso de autoatendimento por funcionário sem liberar a rota financeira. A CI F168 valida `node --check` do servidor e de ambas as interfaces e executa Node22/24 com a suíte completa.

## Gate

É uma alteração funcional do servidor Node existente, **não** habilitação da fundação Laravel/PostgreSQL nem do SSO. Não existe listagem de dispositivos reais/IPs, somente revogação em massa de outras sessões. A homologação exige avaliação de segurança e teste de UI em navegador com a configuração de cookies/proxy da infraestrutura final.

**NO-GO:** revisão independente e deploy controlado ainda necessários. Nenhuma mudança em `main`/`dev`, produção, fornecedores ou despesas.
