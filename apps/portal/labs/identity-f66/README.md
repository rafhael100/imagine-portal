# PORTAL-F66 — fundação de identidade PostgreSQL exclusiva do Portal

**Entrega:** migração Laravel de banco PostgreSQL, **sem rotas de autenticação**, compatível com a arquitetura Portal-only e acesso empresarial multiempresa. Não usa as tabelas, credenciais, sessão ou MFA do Imagine CRM. Permanece em PR de desenvolvimento e não migra a VPS.

## Modelo concreto definido

- `portal_identities`: usuários do próprio Portal com email normalizado, hash Argon2id opcional para convite, status, revisão de segurança e campos reservados a MFA criptografado (ainda sem secret store/fluxo de criptografia implementado)
- `portal_organizations`: organização com ID UUID exclusivo, referência e revisão de segurança
- `portal_memberships`: ligação forte de usuário a organização, status, papel `owner/manager/member` **sem permissões automáticas**, revisão e unicidade por pessoa/empresa
- `portal_membership_grants`: permissões explícitas, sem wildcard, alinhadas ao enum atual (`services.view`, `services.manage`, `billing.view`, `members.manage`)
- `portal_auth_sessions`: apenas hash SHA-256 do bearer opaco, vínculo FK composto à membership de mesmo usuário e empresa, status pending_mfa/active/revoked, validade, versões e evidência temporal de MFA

As cinco tabelas iniciam com `ENABLE ROW LEVEL SECURITY` e `FORCE ROW LEVEL SECURITY`, **SEM NENHUMA POLICY PERMISSIVA**. Mesmo que um papel comum receba GRANTs, não obtém linhas nem pode inserir sessões válidas sem política revisada. Somente o superusuário de banco descartável consegue criar dados de teste. Esta é uma decisão proposital fail-closed; **o esquema sozinho não autentica ninguém**.

## Testes de integração CI

`.github/workflows/portal-f66-identity-pg18-ci.yml`, PHP8.3/8.4 e PostgreSQL18 descartável:

1. Aplicar **somente** `2026_11_09_000002_create_portal_identity_foundation.php` no banco `portal_identity_lab`.
2. Inserir identidade/organizações/vínculos/permissões/sessão **sintéticos** como administrador do runner.
3. Verificar FORCE RLS em cinco tabelas, ausência de policies, SELECT invisível para role comum mesmo com GRANTs e bloqueio de escrita.
4. Negar email não normalizado, role e permissão ilegais, sessão ligada a outra empresa, sessão active sem MFA e versões inválidas.
5. Confirmar que Laravel ainda registra apenas `/up`; executar rollback **somente da migração F66** e comprovar que as cinco tabelas foram removidas.

## Segurança e requisitos antes de ativar login

- Definir fonte de identidade confiável Portal-only, onboarding/invite, verificação de email, senhas Argon2id, redefinição, MFA real e recuperação.
- Proteger cookie/sessão com CSRF, SameSite, Secure, HTTPS, expiração, revogação concorrente, bloqueio de brute-force, auditoria.
- Projetar roles de banco não-superuser, RLS policies revisadas com recursos/owner reais e transações seguras. Variáveis de sessão SQL isoladamente **não comprovam autoridade**.
- Implantar chaves/KMS para MFA, rotação, LGPD e evidência contra rollback; integrar a testemunha independente SEC-R03/Z4, cumprir revisão AppSec/DBA antes de dados reais.
- Nenhuma rota `/login`, `/register`, `/mfa`, `/api` ou consentimento de cliente foi criada. Não executar migração em VPS/prod sem gate independente.

**PR DRAFT / REVIEW ONLY — sem deploy, merge, cliente, credencial operacional nem custo novo.**
