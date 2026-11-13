# SEC-R03-F07 — Comprovante assinado vinculado a um evento de revogação

**Resultado esperado: contrato criptográfico reutilizável, transporte operacional negado. Não é autorização para colocar clientes no Portal.**

O domínio Laravel ganhou `PortalRevocationReceiptVerifier`, `PortalSignedRevocationClaim` e `PortalRevocationPublicationProbe`. O comprovante Ed25519 usa campos JSON canônicos com propósito explícito `imagine-portal/revocation-ack/v1`, nonce randômico de 256 bits, UUID de evento, identidade de empresa, identidade sintética de cluster, geração do witness, cobertura do backup primário e pisos mínimos de revogação de sessão/membership. O verificador rejeita campo extra, evento errado, assinatura inválida, outra empresa, nonce antigo, formatos incorretos e números fora dos limites.

**Diferença material de F05/F06:** a resposta não informa apenas um piso assinado; agora deve corresponder também ao **evento específico de revogação solicitado**. O `PortalRevocationPublicationProbe` compara o comprovante às revisões mínimas de sessão/membership, exige cobertura posterior à geração do backup primário e recusa um ID de cluster igual ao primário. Essas verificações são apenas contratuais. Elas não provam que o cluster é realmente diferente ou que os dados são duráveis.

O contrato `PortalRevocationPublicationTransport` está registrado em `PortalWitnessSafetyServiceProvider` **somente** com `DenyingPortalRevocationPublicationTransport`, que sempre devolve `null`. Não há serviço externo, endereço de rede, rota, segredo, chave operacional, DNS, TLS, acesso de cliente ou publicação real. Os testes `PortalRevocationPublicationProbeTest` exercitam o contêiner Laravel e assinaturas sintéticas, cobrindo nonce distinto por tentativa, evento/empresa inválidos, chave e assinatura trocadas, pisos insuficientes, cluster não separado, erros e ausência de remoto.

### Limites de segurança

- Uma assinatura correta **não** comprova que a testemunha não foi restaurada para um estado anterior, nem que fez fsync de uma revogação ou que seu armazenamento não está no domínio de backup PostgreSQL.
- O código ainda não liga publicações de revogação a transações duráveis de sessão. Sem protocolo de ordenação, quorum de witness, leitura posterior e quarentena operacional, nenhuma confirmação pode ser interpretada como revogação concluída.
- `PortalSignedRevocationClaim::canAcknowledgeDurableRevocation()`, `canAuthorizeServing()` e `PortalRevocationPublicationProbe::canConfirmDurableRevocation()` retornam **sempre false**.

**Issue #64 permanece NO-GO.** Review AppSec/DBA, witness independente e prova de recuperação real são pré-requisitos. Não executar merge, deploy, cadastro de clientes nem alterações na VPS.
