# GSC Slice S — vault cifrado de candidatos a token OAuth

O Lote S acrescenta persistência PostgreSQL **cifrada** para *candidatos* a token Search Console, usando dados sintéticos nos testes. O código ainda **não está autorizado a armazenar nem usar credenciais reais** sem backend autenticado e revisão independente.

## Escopo

`GoogleSearchConsoleEncryptedTokenVault` oferece:
- `storeSyntheticCandidate` — aceita apenas um objeto de token previamente classificado, escopo exatamente `webmasters.readonly`, empresa/operador/fingerprint em formato estrito e prazo de até 3.600 segundos. Criptografa os tokens com libsodium **XChaCha20-Poly1305 AEAD**, nonce aleatório e AAD vinculado à empresa, operador, propriedade e expiração. A chave de 32 bytes é recebida do servidor e guardada em `SensitiveParameterValue`.
- `inspectSyntheticCandidate` — somente backend, exige os três identificadores exatos, prazo válido, registro não revogado e verificação criptográfica. Devolve objeto opaco sem expor tokens em JSON, `print_r` ou `var_export`.
- `revokeSyntheticCandidate` — bloqueia imediatamente leituras futuras do registro com alteração atômica do timestamp de revogação, sem retornar dados do token.

A migration `2026_11_12_000004_create_portal_gsc_oauth_token_candidates.php` define uma linha por empresa, ator e propriedade, escopo readonly fixo, limites temporais e ciphertext com versão. O CI executa migração e rollback em PostgreSQL **descartável**.

## Garantias e limites

Os testes do laboratório S verificam cifra (sem tokens em texto claro), isolamentos entre empresas e propriedades, falha com chave errada, ciphertext trocado entre registros, idempotência, expiração, revogação, filtros de escopo e impossibilidade de emitir tokens pelo resumo de revisão.

**Esta é uma infraestrutura de armazenamento, não um serviço de autenticação Google.** O parser de resposta M não prova a origem do token; `storeSyntheticCandidate` não deve ser conectado a nenhuma rota, job de cliente ou retorno HTTP até existir verificação externa de sessão MFA/RBAC, propriedade/tenant grant, origem TLS do Google e aprovação de AppSec/DBA/LGPD. A chave real deve ser provida por cofre seguro com rotação e controle de acesso, e há pendências de retenção/purga e revogação do refresh token no Google.

Toda interface de revisão retorna autorizações operacionais falsas. **DRAFT/NO-GO:** sem merge dev/main, deploy, Google API real, tokens de clientes ou migrações em produção.
