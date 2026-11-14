# Portal GSC F114 — estado OAuth PKCE iniciado por sessão MFA real (laboratório)

A F113 provou que uma sessão de negócio gerada pelo autenticador original
do Portal (senha Argon2id, TOTP e rotação do bearer) pode verificar
`seo.gsc.view` e um grant GSC em uma transação PostgreSQL18.

A F114 avança no caminho distinto de **conectar** uma propriedade:
`seo.gsc.connect` não é `seo.gsc.view`, e uma sessão não concede
automaticamente nenhuma das duas.

## Implementação

1. A função `portal_gsc_f114_connect_principal`, com proprietário
   `NOLOGIN`, confirma no PostgreSQL a sessão first-party business,
   MFA, revisões atuais de identidade/membership/empresa, status ativos
   e **grant de conexão explícito**. Usa `FOR SHARE` até COMMIT.
2. O backend CLI de laboratório cria PKCE S256 usando a classe
   `GoogleSearchConsoleOAuthPreflight` já existente. `tenantRef` e
   `actorRef` não vêm do navegador: são **referências candidatas
   derivadas dos UUIDs verificados** no PostgreSQL. Não representam
   ainda o cadastro de propriedades Google de clientes.
3. A classe `OfflinePortalGscOAuthStateStartReview` cifra o
   `codeVerifier` com XChaCha20-Poly1305 usando a AAD idêntica ao
   ledger existente e uma chave aleatória, exclusiva da fixture.
   O campo `serverOnly`, a chave, o bearer e os UUIDs jamais são
   incluídos na resposta pública de laboratório.
4. A função `portal_gsc_f114_store_state` repete a prova de sessão
   **na mesma transação** e grava o hash do estado e o verificador
   cifrado, com TTL de 600 segundos e restrição de unicidade.
5. O leitor separado `GoogleSearchConsoleOAuthStateLedger` usa um
   papel PostgreSQL mínimo, com `FORCE RLS`. A regra de usuário é
   `AS RESTRICTIVE` para que a política genérica antiga baseada
   apenas em tenant **não permita leitura por outro ator**.
   `consume()` bloqueia o replay via UPDATE...RETURNING.
6. Teste contra concorrência: uma segunda conexão tenta revogar
   a sessão e remover o grant de conexão durante a transação.
   A operação deve sofrer `lock_timeout`. Depois do logout real
   pelo Portal, não se pode emitir outro estado.

Os três cenários Node F104 (completo, parcial, vazio) são executados
em PostgreSQL 18 descartável com migrations reais de identidade e
GSC, incluindo rollback. Código GSC pré-existente e emissor SEO
ficam fixados em SHAs exatas.

## Bloqueios e riscos ainda existentes

**Não habilita fluxo OAuth Google real.** A URL de autorização
apresentada é apenas uma prévia sintética, sem requisição de rede
ou client ID operacional. Não há endpoints Laravel, callback público,
Google HTTP, refresh token ou autorização de visualização.

Um registro de estado persistido pode sobreviver ao logout;
portanto o futuro callback deverá **revalidar** a sessão, o grant
de conexão e a empresa antes de consumir o estado ou trocar o
authorization code. A F114 prova apenas a **emissão atômica**,
não um callback operacional revogável ponta a ponta.

A fixture de papéis e as políticas SQL são exclusivos de laboratório
e exigem revisão independente de privilegios/GRANTs, imutabilidade
de chaves, RLS e `SECURITY DEFINER`. A identidade por UUIDs
derivados precisará ser reconciliada com o vínculo durável de
propriedade Google e política de retenção. Devem ser aprovados
AppSec/DBA/LGPD, consentimento e escopos, secrets manager,
staging com backup/restore e liberação formal antes de qualquer
merge ou deploy.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CUSTOMER-DATA.**
