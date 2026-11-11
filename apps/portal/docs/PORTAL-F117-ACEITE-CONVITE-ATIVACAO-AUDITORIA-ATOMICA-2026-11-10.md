# PORTAL-F117 — Aceite de convite, ativação de vínculo e auditoria na mesma transação

**Data:** 10/11/2026. **Status:** prova PostgreSQL 18 descartável e segura por padrão; **NO-GO operacional**.

## Objetivo

As provas anteriores cobriam partes diferentes da entrada de um cliente: F115 comprovava sinteticamente o endereço, F116 criava uma identidade com senha Argon2id e TOTP, F114/F112 criavam e verificavam uma sessão provisória `invitation_only`, F108 consumia o convite auditado e F113 impedia a elevação da sessão provisória para uso de serviços. Faltava uma transação que promovesse o vínculo `invited → active` **somente depois** de confirmar a sessão provisória e o token de convite, sem deixar mudanças parciais.

A F117 cria `labs/identity-f117/atomic_membership_activation.sql`, com um papel técnico `NOLOGIN/NOSUPERUSER/NOBYPASSRLS`, sem leitura de senha ou segredo MFA e sem capacidade de criar uma sessão ou concessão de serviço. O papel do teste é exclusivamente `EXECUTE-only` e não pode invocar os predicados legados de aceitação. A função `portal_f117_accept_with_audit(session_sha256, invitation_sha256, organization_id)` executa:

1. Obtém a identidade **do registro da sessão Portal no banco**, não de um ID declarado pelo chamador. A primeira consulta não autoriza nada: em seguida são bloqueadas e revalidadas, em ordem fixa, a identidade, a organização, o vínculo e finalmente a sessão.
2. Verifica identidade e empresa ativas, vínculo ainda `invited`, e-mail e TOTP cadastrados, sessão `active` de propósito imutável `invitation_only`, não revogada, validação MFA com até 5 minutos, prazo e revisões de segurança de identidade/vínculo/organização ainda coerentes.
3. Executa a prova F108→F107→F106 para consumir o convite correspondente à **mesma identidade e empresa**, com evento F89 `invitation.consumed`. Caso a função retorne `false`, não altera vínculo ou sessão.
4. Muda `portal_memberships.status` para `active`, aumentando `membership_revision` exatamente em um, e revoga a **sessão provisória** original em lugar de convertê-la numa credencial empresarial.
5. Insere o segundo evento F89 `membership.activated`, com digest determinístico de UUIDs do vínculo, evento, organização e identidade — sem token de sessão ou de convite e sem seus hashes bearer-equivalentes.

As alterações 3–5 compartilham uma única transação PostgreSQL. Qualquer falha, incluindo erro de RLS, falha da inserção de auditoria, timeout ou concorrência, aciona rollback da subtransação inteira. A migração F89 amplia seu catálogo fechado exclusivamente para `membership.activated`, sem liberar políticas da aplicação.

## Acesso mínimo e RLS

O proprietário técnico F117 lê apenas as colunas relevantes das tabelas, ganha `UPDATE(status)` de identidade e empresa **somente para adquirir locks `FOR SHARE`**, com policies `WITH CHECK(false)` que rejeitam escrita real. Um policy dedicado permite `invited → active` do vínculo com revisão positiva, outro permite somente `active/invitation_only → revoked/invitation_only` da sessão. A inserção F89 aceita apenas `membership.activated`. Nenhum desses papéis é usado por Laravel ou pela produção.

Estas policies com visibilidade ampla para um definer NOLOGIN são **provas de laboratório**, não regras tenant-aware para a operação real. O procedimento é executável apenas no PostgreSQL descartável e não pode ser exposto como função pública, RPC ou endpoint. Os argumentos digest são material equivalente à posse do bearer.

## Testes adversariais

`labs/identity-f117/test_pg_atomic_membership_activation.php` usa duas conexões independentes e um escritor administrativo fictício para provar:

- conta técnica sem LOGIN, superuser ou BYPASSRLS, e cliente sem leitura direta, escrita, INSERT de sessão ou concessão de serviço;
- identidade obtida da sessão, rejeição de sessão CRM, org estrangeira, convite de outra pessoa e digest inválido;
- apenas uma transação consegue consumir convite, ativar vínculo, revogar sessão provisória e registrar exatamente os eventos `invitation.consumed` e `membership.activated`;
- segunda transação concorrente bloqueada até COMMIT, replay recusado, e as alterações não ficam visíveis antes do COMMIT;
- se o segundo evento F89 falhar, **tudo** volta a `pending/invited/active` sem eventos falsos;
- se a revogação da sessão falhar, nenhuma ativação ou consumo sobrevive;
- revogação de organização simultânea bloqueia aceite e invalida revisões; MFA antigo, sessão revogada, empresa errada e token inválido negados;
- nenhuma permissão de negócio ou sessão `business` é criada, e a função técnica não pode alterar identidade fora do fluxo de autorização.

A matriz GitHub CI executa os testes F115/F113/F114/F116 e F117 em PHP 8.3/8.4 com PostgreSQL 18, aplica migrations de laboratório, desmonta as policies/roles/functions e reverte os schemas. Os checks do PR cobrem testes Laravel, gate F111 e contêiner privado somente com `/up`.

## Dependências reais não resolvidas

- O usuário do laboratório é sintético. Não há emissor confiável de sessão, entrega real de e-mail, recuperação, KMS, CSRF, mitigação de phishing, proteção de tentativas distribuídas nem onboarding de cliente.
- **Convites emitidos antes da primeira matrícula F116** ficam com revisão de identidade antiga: F116 aumenta `security_revision` de 1 para 2. Um emissor futuro precisará revogar o convite anterior e emitir um novo, associado à revisão atual, após enrolamento; não deve suprimir a checagem de revisão.
- Ativar membership **não concede permissões automaticamente** nem emite sessão `business`. O login empresarial posterior terá que autenticar de novo por fluxo próprio, emitir nova credencial de propósito `business` e passar pela função F113.
- `SEC-R03` (testemunho externo anti-rollback), revisão independente de DBA/AppSec/LGPD, RLS operacional por organização, auditoria imutável fora do banco e homologação segura na VPS continuam gates obrigatórios.

**Sem API, login ou e-mail reais; sem merge `main`, deploy, acesso CRM, Ads, alteração de clientes ou despesas novas. PR draft / NO-GO.**
