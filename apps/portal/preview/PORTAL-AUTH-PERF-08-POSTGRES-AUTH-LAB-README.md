# PORTAL-AUTH-PERF-08 — Fonte PostgreSQL de sessão, membership e serviço

**Data-base:** 09/11/2026. Issue #127, branch filha de PORTAL-AUTH-PERF-07 [#126](https://github.com/rafhael100/imagine-portal/pull/126).

## Objetivo
Criar uma fonte **persistida e consultada por SQL** para os atributos de identidade e contrato que a declaração Ed25519 F07 transporta. Não utilizar identidades/contratos inventados diretamente na futura rota de emissão. Os testes usam **apenas dois usuários e organizações fictícios**, nunca credenciais, CNPJ, e-mails ou contratos de clientes reais.

## Implementado
A migration `2026_11_09_000001_create_portal_perf_authority_lab.php` cria três tabelas:
- `portal_perf_authority_sessions`: token opaco armazenado somente como **SHA-256**, sessão, ator, empresa, status, MFA registrado em timestamp, expiração e revisão de conta/sessão.
- `portal_perf_authority_memberships`: vínculo empresa/ator, permissão explícita apenas `campaigns:read`, status e revisão de membership.
- `portal_perf_authority_services`: empresa/tenant/serviço, situação contratual **fictícia** `active_mock/pending_mock/revoked_mock`, revisão e epoch de recuperação.

Todas possuem `ENABLE + FORCE RLS`. Sessões só são visíveis à role SQL `portal_f08_reader` quando seu hash coincide com o hash de sessão definido **localmente em uma transação**. Membership e serviço só são visíveis na organização derivada da sessão carregada do banco; o cliente não pode fornecer livremente `actor_ref`, organização, tenant ou permissão a esta API de laboratório.

`PortalPerfSqlAuthorityResolver::resolve()` exige PHP CLI/testing/flag de CI, papel PostgreSQL SELECT-only, conexão de banco específica, token opaco válido e serviço de teste permitido. Abre `REPEATABLE READ READ ONLY` e faz consultas preparadas: sessão pelo hash; status/expiração/revisões e MFA recente (até 10 minutos); membership ativo para o ator/empresa encontrados; serviço correspondente com contrato `active_mock` e revisões/epoch positivos. Se alguma fonte falhar, volta `null` sem resultado parcial, limpa o escopo e não emite declaração. Retorna `PortalPerfAuthorityFacts` readonly com identidades/revisões somente fictícias para uma próxima integração testada com o emissor F07.

## Limitações críticas
Isto é **estrutura real de banco com dados sintéticos**, não login de cliente real: não existe processo de MFA/SSO operacional que valide e mantenha essas linhas, autorização comercial contratual real, expiração/revogação em múltiplos nós, KMS de serviço, auditoria de login/consentimento e retenção/exclusão homologadas. Um papel SQL com capacidade de executar SQL arbitrário pode definir GUCs customizadas; logo RLS com `app.portal_* ` só é defesa em profundidade, não substitui middleware autenticado, queries fixas, menor privilégio e auditoria AppSec. SHA-256 de sessão é adequado como hash de token **gerado com entropia alta e aleatória**, não para senha de usuário.

Os contratos reais exigem uma fonte persistente auditável do Portal, vínculos com serviço e permissões homologadas, prova MFA, emissão restrita e revogação quase imediata. Não houve qualquer concessão de acesso ao cliente e as autoridades reais atuais continuam **deny-all**.

P0, HT-R01, SEC-R03/T3 e produção em aberto. Sem merge/main/fast-track/VPS/deploy, Ads Google/Meta, PSP, dados de cliente ou novos custos.
