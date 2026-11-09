# PORTAL-AUTH-PERF-07 — Emissão Ed25519 e contrato de autorização verificável (fase controlada)

**09/11/2026.** Issue #125. Branch filha do protótipo Portal PVIEW-PERF-06; sem merge, produção ou conexão de clientes.

## Estado real do Portal antes desta mudança
O Portal possui `PortalSessionAuthority`, `PortalMembershipAuthority`, `PortalResourceAuthority` e `PortalAuthorityBoundary`, mas estas são **interfaces/políticas sem adaptadores autenticados**. `DenyingPortalAuthorities` nega por padrão. Um objeto `PortalSessionSnapshot` construído em memória **não comprova login, MFA ou vínculo de serviço**. A nova declaração assinada não modifica esse bloqueio, não substitui as fontes e não autoriza usuários reais.

## Novo contrato criptográfico de laboratório
`SyntheticCapabilityIssuer` usa sodium **Ed25519 real** para assinar domínio `IMAGINE-PORTAL-CAPABILITY-SYNTHETIC-V1\n` + payload JSON, com envelope `portal-capability-envelope.v1`, `key_id=ephemeral-lab-key-1`, campos versionados e explícitos: issuer `imagine.portal`, audiência `imagine.performance-ai`, organização, tenant, serviço, recurso, ator, sessão, escopo `campaigns:read`, MFA fictício, revisões de membership/serviço/sessão, epoch, estado, timestamp de emissão, expiração de 20 segundos e nonce de 128 bits via `random_bytes`. A assinatura não usa segredo estático nem assume JWT. Nenhum segredo real ou chave privada é gravado no Git.

A emissão só funciona em **PHP CLI, `APP_ENV=testing`, `PORTAL_PERF_SIGNED_LAB=1`**, com chave privada de tamanho Ed25519 entregue em memória. O emissor CLI `preview/tests/emit_perf_capability.php` gera par de chaves **efêmero no processo**, escreve apenas a chave pública hex em um arquivo temporário, emite o envelope em stdout e apaga a chave privada da memória. A etapa de CI e os testes PHPUnit verificam assinatura, ausência de segredos, nonce distinto, curto prazo e bloqueio de produção; todas as rotas de autenticação Performance/Portal permanecem 404.

## Limites de segurança explícitos
**Assinatura criptográfica de dados fictícios NÃO equivale a identidade autenticada.** A verdadeira emissão exige resolver sessão do Portal, MFA, membership com permissão por serviço e contratação ativa usando autoridades confiáveis + revogação corrente. Não existe sistema real para rotação de chaves, KMS, distribuição de public keys, prevenção de replay durável entre réplicas, auditoria de emissão, cancelamento de concessões e autenticação mTLS operacional. Não registrar issuer em route/provider.

O motor F1-29 deve rejeitar uso não-test e obrigar assinatura válida, fonte de revogação corrente (não um claim autoafirmado), nonce de uso único e escopo exato. **Não deve emitir ALLOW para Google/Meta/PSP**. Após este recorte, AppSec/DBA e produto devem homologar as autoridades reais antes de habilitar operações.

P0, HT-R01, SEC-R03/T3, dados de clientes, deploy, main/fast-track e aprovação de produção continuam abertos. Nenhuma despesa, cobrança ou Ads real.
