# PORTAL-INT-F169 — Resumo privado de sessões ativas

A F168 acrescentou ao Portal Node.js a capacidade de encerrar sessões em outros aparelhos, exigindo senha atual e mantendo a sessão corrente. Para que o cliente compreenda a ação, F169 adiciona **um resumo apenas numérico e privado** das suas sessões, sem revelar tokens, hashes, CSRF, IPs, dispositivos ou informações de outros clientes.

## API

`GET /api/sessions/summary` exige sessão válida e retorna exclusivamente:
- `active_sessions`: número de linhas não expiradas na tabela `sessions` para `user.id` corrente;
- `other_sessions`: `max(0,active_sessions-1)`;
- `max_active_sessions`: limite da F164, atualmente 8.

A consulta usa filtros SQL por `user_id=?` e `expires_at > Date.now()` com parâmetro, sem aceitar um ID de usuário de terceiros. Usuários não autenticados recebem 401. Funcionários com perfil de acesso restrito podem consultar **apenas a própria contagem**, porque a rota está explicitamente permitida no guard sem novas permissões de outros recursos.

## Interface

A página **Minha conta** de clientes e a conta administrativa/da equipe exibem as contagens em um elemento `aria-live=polite`, oferecendo feedback útil antes da revogação. Depois de encerrar outras sessões, a UI atualiza os números. Se a consulta falhar, apenas mostra mensagem genérica, sem dados sensíveis.

## Provas

O teste HTTP usa dois clientes sintéticos, dois logins da mesma pessoa, leitura separada das contagens, revogação via senha atual, verificação do novo número e resposta 401 para conexão anônima. Confirma que a resposta não contém hash, CSRF ou ID da conta. A CI F169 roda Node22/24, valida sintaxe do servidor e das duas interfaces e executa a suíte completa.

## Limites

É uma funcionalidade do **Portal Node/SQLite existente**; o futuro emissor Laravel/PostgreSQL segue inativo. A contagem inclui apenas sessões desse Node e não considera logins de sistemas externos, CRM ou Google. Não retorna inventário de dispositivos por falta de dados de dispositivo verificados. Não faz deploy, merge dev/main, cobrança ou consulta de informações de clientes.

**NO-GO:** revisão AppSec/infra e homologação de UI/cookies antes da publicação.
