# Portal GSC-F107 — envelope cifrado do PKCE verifier

## Implementação

Contrato PHP CLI isolado que cifra o code_verifier OAuth com libsodium secretbox (XSalsa20-Poly1305), nonce aleatório de 24 bytes e chave de 32 bytes fornecida somente por variável de ambiente do laboratório. O ciphertext autenticado inclui, dentro do conteúdo cifrado, digest SHA-256 do state, UUID do usuário e da empresa, fingerprint da propriedade Google, instante de emissão e expiração de 300 segundos.

A saída da função seal contém somente versão, nonce, ciphertext e marcadores HOLD; não contém code_verifier, chave, empresa, usuário ou tokens em claro. A função unseal só devolve o verifier a um chamador **CLI de teste** com chave válida, contexto exatamente igual e tempo válido. Trocas de empresa, ator, propriedade, estado, janela, nonce, ciphertext ou chave são recusadas.

Os testes sintéticos verificam confidencialidade, autenticação do ciphertext, rejeição de campos extras, nonce exclusivo, chave inválida e fail-closed fora do laboratório. Nunca há chamadas à API Google, OAuth operacional, login, armazenamento no banco ou liberação de ambiente HTTP.

## Não confundir com pronto para produção

O laboratório F106 armazena apenas o digest do state e **ainda não** persiste este envelope F107. A integração atômica do envelope com um ledger autorizado e rotas internas ainda está pendente. Antes de operar: autorização autenticada de empresa + MFA, cofre/rotação de chave real, antirreplay durável, verificação da propriedade Google, retenção e revogação, auditoria AppSec e homologação privada. Proibido utilizar a chave sintética do teste em produção.
