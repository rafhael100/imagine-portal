# SEC-R03-F08 — Duas leituras assinadas depois do comprovante de revogação

**Escopo:** teste de contrato de recuperação, não é testemunha remota durável nem autorização operacional.

O `PortalRevocationReadbackGate` complementa a PR #617: antes de aceitar um resultado como **candidato sintético**, exige a prova assinada vinculada ao `eventId`, empresa, pisos e geração de backup, seguida de **duas observações independentes da testemunha**. A assinatura de cada observação e o nonce aleatório são verificados pelo `PortalSignedWitnessReader` usando `PortalWitnessAttestationVerifier`.

O gate compara as duas observações, que precisam ser idênticas, da mesma empresa e do mesmo cluster declarado na publicação; pisos/geração de backup/sequência não podem regredir em relação ao comprovante publicado. Se o assinador desaparecer na primeira ou segunda leitura, lançar exceção, responder com outra empresa/cluster ou alterar o piso entre observações, o resultado é bloqueado. O `PortalRevocationReadbackVerdict` usa causas opacas e não carrega tokens nem campos sensíveis.

A suíte `PortalRevocationReadbackGateTest` cobre: sucesso sintético com três nonces independentes (um para publicação e dois para leitura), transporte de publicação que nega por padrão, leitura faltante ou lançando erro, estados diferentes entre leituras, sequência/backup/piso abaixo do comprovante, empresa alheia, mesmo cluster do primário e evento inválido.

**Ainda não é uma solução operacional contra rollback.** Um emissor de witness restaurado para o mesmo estado antigo pode responder às três operações com novas assinaturas autênticas. Também não existe canal remoto real, armazenamento que resista a restore conjunto, atomicidade entre publicação e commit PostgreSQL, fencing de conexões/filas/cache, quorum, supervisão externa ou aprovação AppSec/DBA. O verdict só pode informar `syntheticChecksPassed`: `canConfirmDurableRevocation()`, `canAuthorizeHttp()`, `canReleaseServing()` e `canReissueSessions()` retornam sempre false.

Sem migração, SMTP, custos, VPS, cliente real, merge ou deploy. Issue #64 e #341 permanecem abertas.
