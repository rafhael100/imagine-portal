# PORTAL-AUTH-PERF-09 — Assinatura Ed25519 dos fatos consultados no PostgreSQL

**Data-base:** 09/11/2026. Issue #129; PR draft filho de PORTAL-AUTH-PERF-08 [#128](https://github.com/rafhael100/imagine-portal/pull/128).

## Implementação efetiva
`PortalSqlBackedCapabilityIssuer` só aceita **token opaco de sessão**, identificador de serviço de teste, chave privada efêmera e relógio de teste. Antes de assinar qualquer declaração, executa `PortalPerfSqlAuthorityResolver::resolve()`, que consulta **sessão por SHA-256 e RLS**, membership ativo e permissão de leitura, contrato `active_mock`, MFA registrado/recente, sessão vigente e revisões/epoch atuais na mesma transação PostgreSQL `REPEATABLE READ READ ONLY`, tudo com role sem superuser e SELECT-only. **O ator, organização, tenant, sessão, escopo, membership revision, service revision, session revision e epoch nunca são recebidos livremente de uma requisição; vêm do banco de laboratório.**

Após resolver, gera exatamente o envelope de domínio assinado **Ed25519** que o verificador Performance F1-29 conhece: versionado, issuer `imagine.portal`, audiência `imagine.performance-ai`, 20 segundos de validade e nonce aleatório de 128 bits. O recurso `demo-cam-a1` **ainda é um item ilustrativo fixo, não uma prova real de propriedade da campanha**. A declaração carrega apenas `campaigns:read` e `service_state=active_mock`; ela **não concede campanha, compra de mídia ou cobrança**.

O CLI `emit_pg_signed_perf_capability.php` cria um par Ed25519 **dentro de um processo PHP descartável**, escreve apenas a chave pública num caminho /tmp e o envelope assinado em stdout, apagando a chave privada da memória; a emissão não exige Composer nem salva segredos. Funciona exclusivamente com PHP CLI, `APP_ENV=testing`, flags de laboratório F07/F08/F09 e PG16 efêmero.

## Testes
O CI F09 cria/sobe PG16 no runner público e executa primeiro **60 testes F08** de sessões/MFA/membership/contratos, incluindo revogações e recuperação (terminam em membership revision 3, service rev4/epoch6). Depois executa **45 testes F09**: assinatura real com chave aleatória, múltiplos nonces, dados do DB no payload, revogação de sessão, MFA ausente, membership revogado, contrato pendente/revogado, mudança de revisões observada imediatamente, SQL indisponível, segredos não persistidos e bloqueio fora do laboratório. O teste termina com membership rev4, service rev5/epoch7, emite um envelope de ponta a ponta e valida essas revisões na declaração. A regressão do emissor F07 e rotas 404 também roda.

## Pendente para clientes reais
As linhas são **registros de laboratório**, não autenticação de sessão pelo Portal operacional, nem licença comercial validada. Ainda faltam: login real e MFA legítimo com sessões persistidas e revogação, vínculo de organização/campanha, assinatura armazenada em KMS com rotação, entrega mTLS e proteção anti-replay durável, autorização segura contra revogação concorrente após a leitura SQL, estado de pagamento e infraestrutura de auditoria/AppSec/DBA e LGPD.

Nenhum endpoint/ServiceProvider/worker conectado, senha real, cliente, Google/Meta ou cobrança. Sem merge/main/fast-track/VPS/deploy/custos. Gates P0/HT-R01/SEC-R03/T3 abertos.
