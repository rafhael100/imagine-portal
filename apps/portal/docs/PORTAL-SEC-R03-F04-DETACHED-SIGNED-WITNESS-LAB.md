# PORTAL-SEC-R03-F04 — Resposta assinada de testemunha de restauração (laboratório)

**Estado: TESTES SINTÉTICOS / NÃO É TESTEMUNHA EXTERNA REAL / ANTI-ROLLBACK OPERACIONAL AINDA NÃO RESOLVIDO / PRODUÇÃO NO-GO.** Esta etapa segue a [issue #64](https://github.com/rafhael100/imagine-portal/issues/64), que documenta a ressurreição de uma sessão revogada após restauração de um backup PostgreSQL.

## Avanço implementado

Criados em `apps/portal/tests/Support`:

- `DisposableWitnessAttestationSigner`: fixture de assinador Ed25519 com uma chave secreta **descartável**. Um estado de testemunha volátil pode ser avançado em sequência e pisos não regressivos, mas **não** sobrevive a falhas ou rollback do próprio assinador. **Não há rede, serviço, gravação durável ou comunicação com VPS.**
- `DisposableSignedWitnessReader`: implementação somente de testes da interface `LabExternalWitnessReader` que gera um **desafio aleatório novo de 256 bits em cada leitura**, exige envelope estrito `{payload,signature}`, vincula a organização, verifica assinatura Ed25519 com **chave pública previamente fixada**, versão e nonce, e recusa falhas sem detalhes de token, tenant ou chave.
- `DisposableSignedWitnessReaderTest`: casos contra resposta assinada capturada e reutilizada, outra chave ou signatário, alteração de tenant/pisos/cluster, esquema malformado, testemunha ausente, incremento regressivo, mudança entre duas leituras e estado da testemunha restaurado.

A pipeline Laravel instala explicitamente a extensão PHP `sodium`, executa PHP 8.3 e PHP 8.4, analisa sintaxe e corre a suíte de testes. O gate existente faz duas consultas independentes ao leitor, com nonces distintos, e **sempre** retorna `canAuthorizeHttp() === false`, `canReleaseProductionServing() === false` e `canReissueCustomerSessions() === false`, mesmo quando a assinatura é válida.

## Limite de segurança central

**Uma assinatura válida com nonce novo NÃO prova que o serviço que assinou ainda contém o estado mais recente.** Se o processo assinador e seu estado forem restaurados juntos, ele pode assinar um piso antigo com um desafio novo e uma chave verdadeira. O teste `test_signed_but_rolled_back_signer_state_is_not_a_real_antireplay_proof` documenta explicitamente esse caso: o gate sintético pode indicar uma *candidatura de revisão*, **mas nunca autorização real**.

Assinar/verificar uma mensagem também não comprova que o banco primário publicou sua revogação à autoridade antes de confirmar ao usuário, nem prova separação física/administrativa entre domínios de falha. Permanecem necessárias uma testemunha monotônica **realmente externa e não restaurável junto ao PostgreSQL**, identidade de máquina/tenant certificada e rotação de chaves, transações de revogação sincronizadas com o witness, controle de concorrência entre verificação e reabertura de tráfego, quarentena de todas as filas/sessões/tokens/caches, testes de recuperação na infraestrutura permitida e análise independente AppSec/DBA.

**Sem conta real, HTTP, sessão de cliente, witness externo, compra de serviço, merge, migração, deploy ou alterações em `main`, `dev` e VPS.** PR DRAFT / NO-GO e issue #64 continuam abertas.
