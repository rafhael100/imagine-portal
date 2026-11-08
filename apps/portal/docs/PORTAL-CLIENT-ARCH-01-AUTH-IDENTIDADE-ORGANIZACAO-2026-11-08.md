# PORTAL-CLIENT-ARCH-01 — Contrato de identidade e organização

**Data:** 08/11/2026. **Situação:** arquitetura aprovada para aprofundamento pela Direção; este PR ainda NÃO implementa login, SQL, rota privada nem autorização operacional. Base: PRs #71/#72/#74/#75, Issue #39, SEC-P01, autoridade F1 e AGENTS.md.

## Direção arquitetural

- Produto Laravel + PostgreSQL exclusivo do Portal. Interface Blade demonstrativa isolada até o sistema de identidade estar validado.
- Usuário e sessão **próprios do Portal**, sem SSO implícito, bancos de identidade, cookies ou credenciais compartilhados com CRM Imagine. E-mails/IDs coincidentes não comprovam identidade.
- A seleção de uma organização no browser é uma **intenção**. O servidor deve provar, por fonte oficial e atual, o vínculo do usuário, o estado da empresa, a permissão explícita e a titularidade de cada recurso.
- Reutilizar as interfaces já existentes PortalSessionAuthority, PortalMembershipAuthority, PortalResourceAuthority e OrganizationAuthorizationService. Não criar avaliador paralelo sem necessidade. Os snapshots e mocks são livremente construíveis; **não** provam emissor, sessão, MFA ou revogação reais.
- O método canAuthorizeHttp da classe PortalBoundaryVerdict continuará falso na demonstração. Permitir HTTP real requer novo gate de segurança, implementação e testes integrados.

## Entidades lógicas propostas — NÃO migrations

| Entidade | Dados / restrição obrigatória |
|---|---|
| portal_users | ID próprio, contato verificado, status, revisão de credenciais e auditoria |
| portal_credentials | senha derivada com algoritmo aprovado, não em texto claro, estado e rotação |
| portal_sessions | referência aleatória de alta entropia, apenas hash no servidor, expiração, revogação, revisão; cookie HttpOnly/Secure/SameSite, CSRF |
| portal_mfa_factors | fator e recuperação sob custódia de segredos, estado e revogação, sem credenciais na UI |
| portal_organizations | identidade de tenant, status ativo/suspenso, versão de grants, auditoria |
| portal_memberships | chave composta organization_id + user_id, estado e versão, role sem privilégios implícitos |
| portal_grants | concessões explícitas por organização/usuário/permissão conhecida, revogação |
| portal_resources | organization_id obrigatório com PK/UK e FK composta; proprietário nunca derivado do browser |
| portal_audit_events | correlação, ator, organização e resultado, minimização de dados e política de retenção |
| portal_outbox | organização, evento, chave idempotente, entrega e replay sob autorização atual |

**Ainda não aprovados:** colunas finais, índices, modalidades MFA, políticas de retenção, esquema real ou migrações.

## Fluxo futuro de confiança

1. Ingress aceita somente sessão Portal; headers de ator, cargo e grants do navegador são ignorados como autoridade.
2. Autenticador oficial verifica sessão, usuário ativo, expiração, MFA e revisão de revogação em store confiável, sob política antiabuso.
3. Seleção de organização é confrontada com vínculo ATIVO e organização ATIVA na fonte oficial; erro ou indisponibilidade => DENY.
4. Recurso é resolvido por organization_id + resource_id na autoridade do servidor; campo tenant do cliente não pode determinar ownership.
5. Role SQL sem BYPASSRLS e sem privilégio de owner/superuser; constraints compostas e RLS como defesa adicional, usando tenant de contexto servidor confiável sob transação.
6. View-model da tela só contém dados efetivamente autorizados. PviewSection e fragmentos de navegação nunca concedem permissões.
7. Mutações exigem CSRF, checagem no ponto de escrita, revogação concorrente, idempotência e auditoria; cache/job não sobrepõem o estado atual.
8. Restore após revogação continua bloqueado até reconciliação verificável, nos limites de SEC-R03, sem assumir que o epoch dentro do backup é suficiente.

## Matriz de riscos mínima

- Sem sessão, sessão antiga ou MFA insuficiente => DENY.
- Ator A tentando recurso B, mesmo e-mail no CRM e seleção de organização B => DENY.
- Membro removido/suspenso, org inativa, permissões alteradas e cache antigo => DENY.
- Cookie fixation, CSRF, enumeração, força bruta, reset de credenciais e bypass MFA => DENY.
- Operações concorrentes de revogação e commit => não podem promover privilégio antigo.
- RESTORE de snapshot anterior à revogação => não revalida sessão nem grants; todas as filas/writers precisam de fencing.
- A prévia Blade nunca é publicada como área autenticada enquanto esses controles não estiverem demonstrados.

## Decisões de produto e segurança ainda abertas

- AUTH-D01: convite/autoinscrição e verificação de identidade/empresa (CNPJ).
- AUTH-D02: MFA por perfil, métodos aceitos, step-up e recuperação.
- AUTH-D03: sessão, duração, dispositivos, logout global, rate limits e suporte.
- AUTH-D04: convites, múltiplas empresas, estados, papéis e grants explícitos.
- AUTH-D05: integração segura Laravel/Blade e integração Portal com CRM sem compartilhar identidade.
- AUTH-D06: recuperação de backup e autoridade anti-rollback independente.
- AUTH-D07: trilha de auditoria, privacidade, retenção e exclusão.

**Governança:** gates D02-SEC01–06, D03/RLS, P0 R01-01/02/03/09/10, G-ID/G-BB, SEC-R03/T3 e release continuam ABERTOS. Sem login real, migrations, ALLOW, deploy, merge, VPS ou novos fornecedores neste PR.
