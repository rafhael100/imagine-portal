# Composição privada do runtime do Portal

PortalBrowserPrivateRuntime liga o arquivo privado validado aos adaptadores existentes. Não registra driver, container de aplicação ou rotas. authentication cria uma conexão nova como portal_browser_runtime; sessionCreator retorna a factory Laravel já protegida contra compartilhamento de PDO e cria uma conexão nova como portal_browser_envelope_runtime por handler. Nenhum papel administrativo é aceito como parâmetro.

Esta candidata aceita somente PostgreSQL em 127.0.0.1, no mesmo namespace de rede, com porta validada. Senhas são argumentos separados do DSN, conexões não são persistentes, prepares são nativos e os limites são conexão 3 s, statement 5 s, lock 2 s e transação inativa 30 s. Não usar para PostgreSQL remoto: esse caso exige composição distinta com verificação TLS e certificado. Um contêiner de aplicação precisará compartilhar explicitamente o namespace privado do PostgreSQL ou de um proxy local; não publicar o banco em 0.0.0.0 para contornar esta restrição.

A chave de criptografia Laravel deve vir da configuração confiável da aplicação e ser independente das chaves TOTP/HMAC e do CRM. O chamador é responsável pela custódia e rotação. O runtime protege debug/serialização, suprime diagnósticos de conexão e não encadeia PDOException. Isso não protege contra código comprometido ou inspeção da memória.

Os testes de integração usam arquivo temporário 0600 em diretório 0700 e segredos descartáveis: senha/MFA/revogação entre conexões; conexões de envelope independentes durante lock ativo e autenticação simultânea; erros de ambas as senhas sem cadeia diagnóstica; recusa de host remoto antes de conexão. As mesmas provas rodam no laboratório PostgreSQL 18 e no Docker privado existente.

Próximos requisitos operacionais: confirmar a candidata final integrada, revisão AppSec/DBA/LGPD, provisionar banco/papéis/chaves privados, configurar proxy confiável e cookies HTTPS, ensaiar recuperação sem retroceder revisões/contador MFA e então habilitar rotas em homologação. Esta composição não satisfaz nem declara essas aprovações.

Esta candidata também incorpora F314/F315 (#520/#522): bloqueio de reconstrução do leitor por unserialize e recusa de symlinks em todos os ancestrais. O runtime recusa os mesmos caminhos de reconstrução. Não integrar os incrementos cumulativos duas vezes.

## F326 — chaves de sessão criptográfica independentes
A composição candidata recusa `Encrypter` cuja chave bruta AES-256 (32 bytes) seja igual, em bytes, ao segredo TOTP, `proof_key` HMAC, `scope_pepper` **ou às senhas de identidade/envelope do PostgreSQL** carregados do arquivo privado. Refusa implementações de encriptação que não exponham chave verificável via `Illuminate\\Encryption\\Encrypter`. O teste integrado PG18 exercita as três colisões com dados sintéticos, além do fluxo positivo com nova chave independente. Essa verificação **não comprova** independência em relação às chaves do CRM ou sistemas externos; custódia independente e rotação controlada permanecem requisitos de homologação.
