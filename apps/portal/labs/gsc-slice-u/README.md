# GSC Slice U — rotação gradual de chave XChaCha20 no vault

O Lote U permite migrar **candidatos OAuth sintéticos** de ciphertext `v1.` para `v2.` sem expor tokens a uma API ou registrar segredos. Nenhuma rotação real é iniciada neste PR.

## Modelo de dois segredos

`GoogleSearchConsoleEncryptedTokenVault` passa a aceitar:
- chave atual de 32 bytes, sempre encapsulada em `SensitiveParameterValue`;
- versão atual `1` (padrão) ou `2`;
- chave anterior opcional de 32 bytes, **somente quando a versão atual é 2**.

A versão 1 continua gravando `v1.`, preservando os testes existentes. Na implantação de uma chave nova, a versão 2 grava `v2.` e, quando recebe a chave anterior, **consegue ler v1 e v2**. A leitura com a chave nova sem a anterior aceita apenas v2.

`rotateSyntheticCandidate(tenant,actor,fingerprint,now)`:
1. Exige versão 2, duas chaves diferentes, empresa/ator/propriedade exatos, state ainda ativo e ciphertext v1;
2. Decifra o token em memória com a chave anterior, verifica escopo e expiração;
3. Gera um nonce novo e cifra novamente com a chave nova;
4. Executa `UPDATE ... WHERE sealed_tokens=:old AND revoked_at IS NULL` para atualizar **uma vez**. Outro worker, propriedade ou ciphertext atualizado não pode ser sobrescrito.
5. Retorna apenas booleano, sem código, token, tenant ou chaves em logs/saída de cliente.

A migration de candidatos de token (ainda **não aplicada em produção**) e seu schema descartável foram ajustados para aceitar explicitamente `v1.` e `v2.`. Nenhuma alteração em tabelas operacionais já existentes ocorreu.

## Procedimento futuro sujeito a revisão

1. Obter chave v2 e chave v1 de um **cofre de segredos**; nunca colocá-las em PRs, actions logs, arquivos de configuração versionados ou chats.
2. Publicar o código **somente após AppSec/DBA/LGPD e staging**, com a aplicação v2 capaz de ler ambas as versões.
3. Migrar as linhas v1 em job interno com lotes pequenos, logs apenas de contagens e monitoramento seguro.
4. Confirmar zero linhas v1, interromper instâncias antigas, retirar acesso à chave anterior e testar recuperação/rollback antes de eliminá-la do cofre.
5. Uma futura rotação v2→v3 requer projeto específico. Este lote cobre **somente 1→2**, não provê rotação perpétua nem cria scheduler.

## Testes

PostgreSQL descartável testa leitura mista durante a migração, recriptografia em uma única atualização, negação de repetição, isolamento multiempresa, chaves erradas, falta de legado, candidato expirado/revogado, gravação de novos candidatos v2 e dumps sem tokens/chaves.

**DRAFT/NO-GO:** sem uso Google real, credenciais verdadeiras, merge em dev/main, deploy ou cobrança. Nenhuma migração de chave real foi executada.
