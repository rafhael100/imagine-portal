# PORTAL-F1-01-B-DEC-01 — Aprovação expressa da implementação controlada B-A

**Data:** 05/11/2026  
**Estado:** **APROVADA EXPRESSAMENTE SOMENTE A F1-01-B-A**; ainda não iniciada por Codex neste registro.  
**Manifestação:** o responsável respondeu **“ok aprovado”** à pergunta expressa: “Você aprova a programação controlada da F1-01-B-A, exclusivamente com estruturas de domínio e testes fictícios, sem banco, login real, merge na `main` ou deploy?”

## 1. Decisão normativa ratificada

**Autorizar exclusivamente** a implementação da **F1-01-B-A** no repositório `rafhael100/imagine-portal`, em uma **branch filha isolada** do head conferido de `dev/fast-track-portal-2026-11-05`, com Pull Request **draft** destinado à branch de desenvolvimento de origem e **sem merge automático**.

O trabalho autorizado é:
1. **PHP/Laravel puro**, classes e interfaces de domínio para identidade **local do Portal** (somente IDs fictícios), organização, memberships explícitas, status do vínculo e permissões por contexto de empresa.
2. **Decisão de autorização segura por padrão**: negar acesso na ausência de vínculo ativo, de permissão explícita, de contexto organizacional válido ou quando houver diferença entre empresa de ator, vínculo e recurso. Não inferir acesso por coincidência de e-mail, CNPJ ou nome.
3. **Testes sintéticos e isolados** em PHPUnit, positivos/negativos, demonstrando que a pessoa pode participar de múltiplas empresas com permissões diferentes e que uma não lê/autoriza a outra.
4. Atualizar documentação estritamente do recorte, relatórios dos testes e orientações `AGENTS.md` aplicáveis ao novo ramo.
5. Executar CI disponível PHP 8.3/8.4, preservar lockfile e fornecer PR auditável com evidências, sem dados pessoais reais.

**Referência normativa vinculante:** [proposta F1-01-B v0.1](PORTAL-F1-01-B-MODELO-ORGANIZACOES-MEMBERSHIPS-PERMISSOES-PROPOSTA-v0.1-2026-11-05.md). A direção **B-A foi expressamente aceita**; as alternativas B-B (persistência) e B-C (login) **não** foram autorizadas.

## 2. Fonte e limitação técnica

- Branch base autorizada para **derivação**: `dev/fast-track-portal-2026-11-05`.
- SHA observado no ato do registro: `098cdfd50024ea872861f3ba3a058409e5f976b7`; **confirmar o SHA atual imediatamente antes de criar a branch**, e interromper/escalar se tiver mudado indevidamente.
- Branch filha sugerida: `dev/portal-f1-01-b-domain-2026-11-05`.
- PR futuro **draft** com base `dev/fast-track-portal-2026-11-05`; **não** direcionar a `main`.
- `main` verificada: `50b683a499e674641df5749051f3d05f7ac3f627`, sem alteração.
- O PR #11 da fundação permanece draft sem merge; o aproveitamento como base para **ramo filho B-A** fica abrangido pela autorização B-A, mas **não equivale à aprovação geral de integração ou merge** de PR #11.

## 3. Limites e vedações expressos

**NÃO AUTORIZADOS:**
- Banco PostgreSQL real, migrações SQL, Eloquent persistente, cadastro de tenants, usuários reais, importações ou migração do SQLite/testes antigos.
- Login, autenticação, MFA, sessões, redefinição de senha, convites enviados, provisionamento, RBAC real ou autorização HTTP de produção.
- Acesso/ligação ao Imagine CRM, Imagine Sites, Performance AI, Universidade Imagine, n8n, PSP, pagamentos, planos reais ou leads.
- Uso de informações pessoais reais, e-mails de clientes, CPF/CNPJ real, segredo, token, dados de produção ou serviços externos.
- Alterações à `main`, merge automático, publicação, deploy, VPS, DNS, Cloudflare, compra/infraestrutura.
- Continuação autônoma da F1-01-B-B, F1-01-B-C, F1-01-C, F1-02 ou demais P0.
- Encerramento tácito de D02-SEC, D03 físico, P0-DOC03/06/12 ou habilitação de rollback automático AGI-11-RB01.

**Importante:** os guardrails antigos de `apps/portal/AGENTS.md`, destinados à F1-01-A, devem ser **observados para o que continua proibido** e só complementados **na branch filha** pelo escopo B-A agora autorizado, sem alterar a branch base ou PR #11.

## 4. Critérios de aceitação da entrega B-A

1. Classes sem I/O, sem consulta ao banco, sem camada web, sem migrations/credenciais.
2. Fixture inteiramente fictícia; testes de permissão correta, ausente, revogada, pendente, cruzada, contexto inválido, privilégio indevido e isolamento entre organizações.
3. Testes PHPUnit e `composer validate` com `composer.lock` preservado, executados em ambiente de testes autorizado (GitHub Actions).
4. Diff com todos os arquivos da implementação localizados sob `apps/portal/`; um PR draft novo aponta **somente** para a branch de desenvolvimento de origem.
5. Evidências verificáveis: comandos, contagem de testes/asserções, status de CI, SHA, lista de arquivos e riscos residuais.
6. **Não** alterar nem mesclar o PR #11, a `main`, VPS ou integração CRM.

## 5. Situação de encaminhamento

A autorização de programação foi registrada no GitHub. **O Codex não foi iniciado automaticamente nesta etapa de registro**. A tarefa técnica deve ser iniciada no Codex pelo responsável, usando a instrução integral consolidada em [PORTAL-F1-01-B-A-TAREFA-CODEX](PORTAL-F1-01-B-A-TAREFA-CODEX-APROVADA-2026-11-05.md), para obter PR draft separado.

O acompanhamento da execução deverá distinguir **aprovado**, **encaminhado**, **em execução**, **PR criado**, **testes executados** e **integrado**. Este documento só comprova os dois primeiros estados quando a tarefa tiver sido entregue para uso, **não** que o código B-A já existe.

**Status final:** **F1-01-B-A aprovada para programação controlada; execução no Codex pendente de acionamento efetivo.**
