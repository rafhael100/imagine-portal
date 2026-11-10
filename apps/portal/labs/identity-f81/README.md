# PORTAL-F81 — integração de MFA PostgreSQL e revogação por organização

## Falha estrutural corrigida

A F75 já validava um TOTP cifrado, usando o segredo cadastrado na identidade e uma transação PostgreSQL. A F76 introduziu `organization_revision` nas sessões e a negava no predicado de permissão se fosse ausente ou defasada. Porém **o finalizador de MFA da F75 não conferia essa revisão ao promover sessões pendentes**. Resultado: podia marcar como ativa uma sessão que o gate F76 rejeitaria depois.

A F81 cria uma integração de laboratório isolada, `App\\Labs\\IdentityF81\\PortalOrgRevisionMfaCompleter`, que bloqueia simultaneamente sessão, identidade, membership e organização, e exige **antes de consumir o código TOTP**:

- Identidade e e-mail verificados, MFA cadastrado, usuário/empresa/membership ativos e revisão de identidade/membership inalterada.
- Revisão de organização presente na sessão (NULL é negado) e igual à revisão atual da organização.
- Sessão `pending_mfa` dentro do prazo; segredo TOTP cifrado vindo da identidade bloqueada, nunca do chamador.
- Counter RFC6238 não utilizado, CAS do counter e promoção da sessão na **mesma transação**, incluindo `organization_revision` no UPDATE final.
- Qualquer erro ou alteração de revisão provoca rollback e não gasta o contador.

## E2E dos componentes F75 e F76

O workflow `portal-f81-org-revision-mfa-ci.yml` executa PHP 8.3/8.4 contra PostgreSQL 18 descartável. Migra F66 (identidades), F70 (counter TOTP) e F76 (org revision), mantém FORCE RLS sem policies e instala apenas a função de laboratório `portal_f76_permission_check` com role sem SELECT e EXECUTE restrito.

O teste F81 cobre negação por email não verificado, revisão de empresa alterada, revisão NULL, chave de criptografia incorreta, ciphertext adulterado, TOTP inválido, replay entre sessões, revogação e mudanças de membership/identidade. Demonstra também a composição: sessão que passou pelo MFA fica autorizada somente com grant explícito na role F76, revogação de grant e revisão da empresa negam a permissão imediatamente.

**Não foi ativado login real.** O método recebe bearer de fixture e uma chave efêmera passada no CLI; a autoridade SQL é instalada com admin do runner descartável. Não há cookies, identidade de clientes, MFA provisionado de verdade, consentimento, acesso a dados ou KMS. O hash de sessão não deve jamais ser exposto via RPC/browser. A função SQL SECDEF deve ser revista por AppSec/DBA e migrada para owner dedicado de baixo privilégio antes de uso real.

## Bloqueios para produção

Permanece NO-GO até fonte de identidade/serviço confiável, proteção antirollback SEC-R03 fora do backup, AppSec/DBA/LGPD, KMS, rate limit no proxy, cookies seguros/CSRF, recuperação, RLS de aplicação e homologação na VPS Leaseweb. Nenhum deploy, merge, custo, URL pública ou cliente foi modificado.
