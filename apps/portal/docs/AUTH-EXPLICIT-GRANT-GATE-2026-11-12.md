# Portal — pré-verificação de grants NÃO autoriza recursos (F302–F305)

Estado: **candidata de revisão, NO-GO**, dados exclusivamente sintéticos, sem rotas operacionais de login ou de negócio.

## Contrato parcial já verificável em PostgreSQL 18

A função `portal_browser_permission_preflight(session_sha256, organization_uuid, permission_code)` devolve somente booleano. Para resultar true exige sessão empresarial ativa e não revogada, MFA prévio, TTL válido, identidade/empresa/membership atuais e explicitamente ativos, revisões compatíveis e grant exato vinculado **à mesma organização da sessão**. O runtime SQL não tem SELECT direto nas tabelas protegidas. O adapter `PostgresPortalPermissionPreflight` só consulta essa função, não é ligado ao container e recusa origem privilegiada/DB incorreto/transação do chamador.

Uma identidade pode ter membros e grants em duas empresas. Essa condição **não** permite usar a sessão A em B. Papéis como `owner` não substituem grants nominais. Mudar o grant eleva a revisão do membership e invalida as sessões antigas.

## Gate obrigatório antes de qualquer endpoint empresarial

Uma futura autorização de serviço deverá, **na mesma transação e no mesmo limite de confiança**, validar novamente a sessão e grant, resolver no banco a organização titular do recurso consultado, comparar essa organização à organização **da sessão emitida**, comprovar a política daquele recurso/ação e só então acessar a linha RLS ou realizar a mutação. A organização fornecida na URL, no corpo ou em um header é apenas intenção de consulta, nunca prova de posse.

**Não é suficiente** reunir separadamente: login válido + `preflight=true` + `resource_id` informado pelo cliente. Esse padrão cria janela de corrida de revogação e risco de vazamento entre empresas.

O código F305 reforça essa fronteira executando um preflight SQL realmente **positivo** e, na sequência, comprovando que `PortalAuthorityBoundary` segue negando autoridade sobre recurso não verificado e que a aplicação continua expondo somente a rota `/up`.

## Liberação futura (não implementada)

- Fonte confiável do titular do recurso, sem aceitar snapshots do browser/CRM.
- Revisão AppSec/LGPD/DBA de RLS, bloqueios, concorrência, multiempresa e revogações antes e durante uma operação.
- Integração Laravel e PostgreSQL em staging isolado, testes reais de navegador, headers/cookies HTTPS e rollback.
- Registro explícito de rotas e providers somente após aprovação independente. Nenhum merge em dev/main ou deploy na VPS está autorizado por estes testes.
