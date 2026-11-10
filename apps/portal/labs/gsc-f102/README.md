# PORTAL-F102 — Contrato OAuth Google Search Console (somente laboratório)

Esta etapa implementa a base **offline** de transações OAuth 2.0 com PKCE S256 e `state` aleatório de 256 bits, validade de 5 minutos, uso único inclusive em falhas, associação rígida a usuário, empresa, fingerprint da propriedade e callback HTTPS fixo. Nenhum OAuth foi ativado.

## Modelo para produção (ainda não implementado)

1. Login próprio do Portal, MFA recente e sessão com cookie `__Host-` e CSRF; contrato IAM verificado e transacional, organização escolhida do lado do servidor.
2. Autorização explícita de membro com permissão **seo.gsc.connect**, aprovação do titular da propriedade e consentimento LGPD registrado.
3. `state` de uso único em PostgreSQL/Redis com digest armazenado, TTL e operação atômica; PKCE verifier e tokens protegidos por KMS/criptografia e nunca expostos ao navegador, logs ou Git.
4. Criar URL OAuth com client ID registrado, `redirect_uri` exata e `scope=https://www.googleapis.com/auth/webmasters.readonly`, PKCE S256; validar issuer/audience quando aplicável e retornar ao mesmo usuário/empresa/sessão autenticada.
5. Na callback, trocar código exclusivamente no backend via TLS, confirmar escopos concedidos e identidade vinculada, listar propriedades Google e comparar o `siteUrl` exato (domain vs URL-prefix). Não inferir propriedade por substring/domínio.
6. Registrar grants por empresa e proprietário da propriedade, com menor privilégio e auditoria; proteger por RLS/controle transacional e revogação. Separar completamente login e credenciais Portal/CRM.
7. Desconexão/revogação, rotação e exclusão de tokens, retenção mínima e trilhas sem PII. Proteção de refresh token, limitação de consultas, anti-replay e rollback.
8. Executar E2E em VPS privada, com autoridade IAM real, review AppSec/DBA independente, testes de ataques por tenant, privacidade e autorização de deploy antes de qualquer rota real.

## Restrição
A classe F102 vive em `labs/`, fora do autoload de produção e da imagem Docker. `authorizesGoogleAccess()` e `authorizesPortalHttp()` devolvem sempre `false`; o estado em memória não serve à operação distribuída. Todos os testes usam apenas dados inventados. O OpenSEO já consegue ler a propriedade Imagine, mas a ligação OAuth do OpenSEO **não** é credencial do Portal.