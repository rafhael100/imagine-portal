# Portal GSC F111 — preflight de permissão no banco real de sessão

A F110 passou a reconhecer `seo.gsc.view` em enum/migration. A função de
preflight de sessão de negócio do Portal continuava com uma allowlist
fechada para quatro permissões, de forma que a nova permissão seria
recusada mesmo com registro explícito.

A F111 atualiza de forma pareada a allowlist de
`PostgresPortalPermissionPreflight` e a função PostgreSQL
`portal_browser_permission_preflight`: reconhece separadamente
`seo.gsc.connect` e `seo.gsc.view`.

A função já existente preserva o contrato de primeira parte: digest
SHA-256 de referência de sessão, `session_purpose=business`,
status ativo, MFA, expiração, revisão de identidade, organização e
associação, RLS forçado e função `SECURITY DEFINER` somente EXECUTE
para o runtime sem SELECT direto. Nenhum dado do DTO do SEO é usado
para estabelecer identidade ou papel.

O workflow `portal-browser-identity-pg18.yml` instala em laboratório
descartável **as migrations reais** de `connect` e `view`, sem grants
automáticos. Testes PostgreSQL 18 em PHP 8.3 e 8.4 verificam:

- usuário com grant somente `connect` pode inspecionar somente o
  candidato de conexão; `view` é negado;
- usuário com grant somente `view` pode inspecionar somente o
  candidato de leitura; conexão não é inferida;
- sem grant, identidade forjada, outro tenant, wildcard e `manage`
  não produzem resultado autorizado;
- após remoção do grant, suspensão da empresa ou revogação da sessão,
  a próxima consulta falha;
- nenhuma mudança nos providers, rotas HTTP, cofre ou chamadas Google.

**Importante:** um `true` em `hasCandidateGrant()` é somente um
**preflight momentâneo** da sessão e associação no banco, sem
comprovação de que a propriedade Google está autorizada, sem controle
de concorrência com GSC (F108/F109 estão em outra branch) e sem
autorização de exibição. O resultado não é token, escopo reutilizável
ou decisão sobre o acesso ao recurso. Os gates de AppSec/DBA/LGPD,
Google OAuth read-only próprio do Portal, staging e rollback permanecem.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
