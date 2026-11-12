# Sessões criptografadas e serializadas no PostgreSQL

Implementação candidata, sem registro de driver, rotas, login ou credenciais operacionais. O handler implementa SessionHandlerInterface e cifra sempre o envelope por meio do Encrypter da aplicação, mesmo com session.encrypt desativado. Apenas SHA-256 do identificador Laravel de 40 caracteres é persistido; dados de sessão ficam cifrados e autenticados. O segredo do Portal é independente das chaves TOTP e de provas de identidade; nada é compartilhado com CRM.

A conexão e o papel do envelope são exclusivos, distintos do autenticador. O runtime só executa quatro funções. O proprietário é NOLOGIN e NOBYPASSRLS, com RLS forçado e poderes somente na tabela de envelopes. O executor não lê a tabela diretamente, não lê identidades e não pode chamar funções de autenticação. O instalador deixa o executor NOLOGIN.

Um advisory transaction lock calculado sobre namespace Portal e digest do ID abrange leitura, processamento e gravação. A sessão usa uma transação dedicada; rotação remove o ID anterior e grava o novo envelope no mesmo commit. O handler não aceita transação externa. Se o request não alcançar save, close/destrutor desfazem a transação. Espera por lock é limitada a 2s (configurável entre 100ms e 5s), statement timeout 5s e timeout idle-in-transaction 30s. Requisições longas precisam política específica; timeout é erro e não acesso concedido.

Expiração usa relógio do PostgreSQL, duas horas de inatividade do envelope; o autenticador conserva sua expiração absoluta independente de duas horas. Limite de ciphertext é 64 KiB. GC remove no máximo mil envelopes expirados por chamada e usa SKIP LOCKED. Ciphertext inválido falha fechado. Os comandos de propriedade e execução dos dois instaladores enumeram assinaturas exatas, sem conceder funções de sobrecargas desconhecidas.

A prova PG18 deve usar Laravel Store real e dois processos concorrentes para comprovar ausência de perda de incrementos, falha limitada por timeout, rotação atômica, rollback de request incompleto, expiração, isolamento dos papéis e recusa de ciphertext corrompido. A prova HTTP completa usa o controlador, autenticador SQL real e envelope SQL real, com chaves efêmeras e identidades @example.invalid.

Composição operacional, provisionamento e rotação de chaves/contas, política confiável de proxy, revisão DBA/AppSec e ensaio de recuperação/antirrollback externo continuam pendentes. A classe não modifica providers, config ou bootstrap. Nenhum resultado de CI promove liberação operacional.
