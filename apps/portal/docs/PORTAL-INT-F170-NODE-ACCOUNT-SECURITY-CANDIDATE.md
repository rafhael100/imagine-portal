# PORTAL-INT-F170 — Candidata consolidada de segurança da conta (Portal Node)

As entregas F164–F169 foram implementadas sequencialmente na aplicação **Node.js/SQLite existente** e em sua interface HTML/JS. Cada uma foi testada em PR de revisão, mas o revisor operacional precisa de uma única visão do conjunto, não de seis diffs parcialmente dependentes.

Esta PR cria uma candidata de segurança de conta **sobre a base Laravel/PG F163 ainda fechada**. Os arquivos de execução permanecem **idênticos à última revisão F169 #381**; somente CI e este documento foram adicionados depois.

## Proveniência congelada

- Ancestral antes das mudanças Node: `f3f33141d237ad0519c97774dd1f2ddbb2079519` (F163).
- Head acumulado **F169**: `1c1a07491f8c8860515a19cf48c333de36da66c3`.
- Árvore Git exata do código revisado: `6dfa34defedd8a3622126a05bb0f561aea24964a`.
- Alterações acumuladas entre os commits: **13 arquivos**, sem arquivos alterados fora da revisão.
- A CI `portal-int-f170-node-account-security-ci.yml` confirma ancestral, árvore e que **somente o workflow F170 e este documento** mudaram desde o head F169.

## Escopo de produto incluído

| Entrega | Funcionalidade | Evidência individual |
| --- | --- | --- |
| [F164 #376](https://github.com/rafhael100/imagine-portal/pull/376) | Sessões por conta limitadas a oito, limpeza de expiração em transação; troca de senha revoga antigas | Node e PHP CI |
| [F165 #377](https://github.com/rafhael100/imagine-portal/pull/377) | Rate limit de login/cadastro identifica cliente atrás de proxy local apenas quando explicitamente confiável | HTTP simulado |
| [F166 #378](https://github.com/rafhael100/imagine-portal/pull/378) | Rejeita IP falsificado à esquerda de `X-Forwarded-For` quando proxy confiável acrescenta peer verificado ao final | HTTP adversarial |
| [F167 #379](https://github.com/rafhael100/imagine-portal/pull/379) | Garbage collection de buckets de rate limit vencidos a cada cinco minutos, sem zerar quotas atuais | Teste SQLite |
| [F168 #380](https://github.com/rafhael100/imagine-portal/pull/380) | Cliente e equipe encerram outras sessões mediante senha atual, CSRF e Origin; sessão corrente permanece ativa; auditoria | API + telas |
| [F169 #381](https://github.com/rafhael100/imagine-portal/pull/381) | Resumo privado com contagem de sessões ativas/próprias, sem tokens ou IPs, atualizado depois de revogar | API + telas |

## Gate de regressão desta candidata

O workflow F170 executa:
1. Verificação Git da árvore exata e ausência de mudanças de runtime posteriores;
2. Testes Node.js 22 e 24 sobre **a candidata completa**, incluindo cadastro/login, orçamento, pagamentos falsos, atendimento, isolamento de clientes, sessão, proxy, CSRF e alterações de senha; valida também a sintaxe de ambas as interfaces;
3. PHPUnit Laravel 8.4 completo, verifica que não há novas rotas de IAM Laravel, exige `DenyingPortalAuthorities` e lockfile imutável.

**Nenhuma suíte Node substitui avaliação real de proxy, UI/cookies em navegador, revisão de segurança dos auditores e observabilidade de produção.** O conjunto Laravel/PostgreSQL ainda é outro domínio, com emissor real, RLS, IAM e MFA bloqueados.

## Gate NO-GO e passos antes da liberação

- Revisão AppSec da implementação Node, sessão, senha, audit, CSRF e controle de acesso de funcionários;
- Teste real de reverse proxy: `APP_TRUST_PROXY` desativado por padrão, servidor Node somente em loopback, cadeia `X-Forwarded-For` sanitizada/sobrescrita ou append do peer verificado;
- QA visual e acessibilidade das telas da conta, desempenho e recuperação de sessão;
- Plano de atualização controlada, logs, backup, restauração, rollback, validação com clientes **fictícios** e aprovação operacional independente;
- Não misturar sessões Node/SQLite com a fundação futura Laravel/PG e não criar upgrade ou migração automática de contas sem aprovação.

**Sem merge em `dev`/`main`, deploy, alteração DNS/VPS, credenciais, clientes reais ou custos.** Esta candidata é apenas revisão executável.
