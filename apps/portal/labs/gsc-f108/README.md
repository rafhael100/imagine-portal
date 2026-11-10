# Portal GSC-F108 — integração do state PostgreSQL com PKCE cifrado

## Implementação executada no laboratório

- A extensão PostgreSQL 18 agrega nonce e ciphertext autenticado do code_verifier à mesma linha do state OAuth (SHA-256, usuário/empresa UUID, fingerprint da propriedade e TTL 300 segundos), sem texto puro no banco.
- Emissão é transacional: state e envelope são inseridos juntos ou não são inseridos. A callback de laboratório faz um único UPDATE atômico, marca o state como consumido e **somente quando todas as verificações coincidem** retorna um envelope ainda cifrado.
- A classe PHP CLI desencripta via F107 apenas depois do consumo no F108; replay, troca de empresa/usuário/propriedade, expiração, falsificação de ciphertext e mudança de chave não revelam o verifier.
- Acesso direto à tabela é vedado a cliente não-superuser. SECURITY DEFINER pertence a role técnica NOLOGIN, com FORCE RLS. CI PHP 8.3/8.4 com PostgreSQL 18 descartável e testes adversariais.

## Limites expressos

- Ainda não existe autoridade de sessão/MFA/consentimento/grant autorizando a criação do state; as funções de emissão são **somente para teste com identidades inventadas**.
- Não há client ID Google, token endpoint, callback HTTP, requests reais, refresh token nem armazenamento persistente de tokens. O code_verifier somente é retornado ao adapter PHP CLI para testar o futuro backend de troca de tokens; nunca para frontend, log ou API.
- Nenhuma migração, rota, autoload, Docker runtime ou ambiente real do Portal foi alterado. Sem merge main, custos ou deploy. Falta conectar estas primitivas à IAM operacional e revisão independente AppSec/DBA da issue #251.
