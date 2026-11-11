# Portal GSC-F106 — state OAuth durável e de uso único (laboratório PostgreSQL 18)

## Implementado

- Em PostgreSQL 18 **descartável**, tabela de pendências registra somente SHA-256 do estado OAuth (nunca o token state puro), ator/organização UUID, fingerprint exato da propriedade e vigência de 300 segundos.
- Função de emissão recusa formatos inválidos, TTL adulterado e colisão de digest; função de consumo usa um **UPDATE atômico** para garantir consumo único entre múltiplas conexões e derrubar tentativas com ator, organização ou propriedade trocados.
- Papel técnico portal_gsc_f106_owner é NOLOGIN/NOSUPERUSER/NOBYPASSRLS; tabela FORCE RLS com policy restrita ao proprietário; papel cliente só tem EXECUTE nas duas funções, sem SELECT/INSERT/UPDATE/DELETE.
- Cliente PHP estritamente CLI/testing/loopback autentica o papel de menor privilégio, usa consultas parametrizadas e nunca devolve dados da pendência ou exceções SQL.
- CI executa PHP 8.3/8.4 + PostgreSQL 18 efêmero, casos positivos/negativos e limpeza de roles/objetos.

## LIMITAÇÃO DE SEGURANÇA — não é OAuth operacional

O papel cliente **não autentica o usuário nem a empresa**: a função de emissão aceita apenas dados artificiais fornecidos pelo próprio laboratório. A verdadeira criação da transação deve exigir sessão Portal/MFA e grant seo.gsc.connect verificados no backend, além de CSRF, consentimento e associação de propriedade.

Este contrato tampouco persiste code_verifier criptografado (somente state), não implementa callback, troca de token, cofre de credenciais, revogação, OAuth Google real ou rotas HTTP. Não pode ser importado no autoload/runtime do Laravel ou imagem Docker. Qualquer produção deve possuir desenho revisto por AppSec/DBA, execução não-superuser, política de expiração/limpeza, registros mínimos e testes E2E de concorrência/negação.

**HOLD:** nenhuma liberação main/deploy, chamadas Google, acesso de cliente, DNS, 301 ou fornecedores.
