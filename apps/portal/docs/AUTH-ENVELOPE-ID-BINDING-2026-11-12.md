# Vínculo criptográfico entre envelope e sessão

A cifra autenticada do envelope, isoladamente, impede alteração do conteúdo mas não impede transportar um ciphertext válido para a linha de outro identificador de sessão. O handler agora inclui, dentro da área cifrada/autenticada, um domínio fixo versionado e o SHA-256 do identificador exato. Após decifrar, compara esse cabeçalho com hash_equals antes de devolver qualquer estado à aplicação.

A leitura falha de forma genérica e desfaz a transação se domínio, versão ou digest não coincidirem. A sessão fonte permanece legível; copiar seu ciphertext para outro ID não transfere seu estado. O formato aceita dados binários no corpo e não altera a serialização Laravel.

Não existe fallback para envelopes antigos sem vínculo. Como a implementação ainda é candidata sem ativação operacional, eventual banco de ensaio persistente precisa invalidar os envelopes antigos e exigir novo login; não tentar reconstruir o vínculo a partir de informação não autenticada. A alteração não bloqueia replay de uma cópia anterior do mesmo ID: revogação durável, expiração, controle de revisão e proteção antirrollback continuam necessários.

Os testes PostgreSQL devem comprovar recusa da cópia entre IDs, recusa de formato legado e preservação da sessão fonte, além de repetir HTTP senha/MFA/logout, concorrência e rotação com o novo formato. Sem alteração de providers, config, rotas ou dados reais. Candidata filha da PR #478, sem duplicar integração das PRs sobrepostas.
