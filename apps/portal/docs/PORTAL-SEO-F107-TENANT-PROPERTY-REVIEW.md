# Imagine Portal — SEO F107: sessão + empresa + propriedade GSC

**15/11/2026 · DRAFT / NO-GO / NO-MERGE / NO-DEPLOY**

## Entrega

A F107 combina **três checagens independentes** antes de produzir um parecer
**apenas de laboratório** do funil SEO, sem autorizar exibição ao cliente:

1. Sessão fornecida por PortalSessionAuthority, ativa, não expirada,
   com MFA sintético e revisão de conta correspondente. A identidade do
   ator vem da fonte de sessão, nunca de campos do navegador.
2. Associação do mesmo ator à empresa selecionada, com status ativo,
   recurso pertencente à empresa e permissão explícita **seo.view**.
   A permissão services.view não concede acesso SEO.
3. Registro recente de grant GSC, inspecionado por
   PgsqlRecordedGscGrantReviewSource e GoogleSearchConsoleTenantGrantGateway,
   usando transação com app.tenant_ref local e RLS PostgreSQL. Confere mesmo
   tenant, ator, propriedade exata/fingerprint, escopo Google readonly e
   registro de verificação com idade máxima de 900 segundos.

O consumidor F106 exige contrato fechado. Cliques e impressões GSC
continuam **descoberta orgânica não verificada**, nunca sessões,
leads ou clientes atribuídos.

Mesmo com todas as checagens positivas, o resultado de laboratório
portal-seo-funnel-scoped-review-lab-v1 mantém todas as flags de
produção **false**. Falhas retornam somente null, sem identificar
empresa, ator ou propriedade.

## O que ainda não constitui autorização real

- Existem políticas puras de empresa/membro, contratos de sessão, validador
  de propriedade, registros GSC e gateway PG/RLS.
- **Não** há emissor autenticado de sessão neste recorte, resolução
  operacional de associações, OAuth Google verificado ao vivo, permissão
  para ler dados reais ou autorização para renderizar dados de clientes.
- O adaptador PostgreSQL é somente leitura restrita, não conectado a
  rotas, controllers ou service container; testes positivos usam fakes
  apenas no próprio teste, sem endpoint HTTP.
- A fronteira requer PHP CLI, APP_ENV=testing e flags explícitas
  PORTAL_SEO_F106_LAB=1 e PORTAL_SEO_F107_LAB=1.

## Matriz de negação

O teste SeoFunnelTenantPropertyBoundaryTest verifica: sessão ausente,
MFA faltando, expiração, revisão de conta divergente, ator errado,
associação inativa ou de outra empresa, permissão errada, proprietário
divergente, propriedade GSC tipo domain vs URL-prefix, fingerprint
errado, grant expirado/futuro, escopo adulterado, flags de autorização
falsas, falha do provedor, dados falsificados e APP_ENV=production.

**Um grant registrado no banco não garante OAuth ainda válido**; um
PortalSessionSnapshot sintético não comprova assinatura, MFA real ou
revogação concorrente. Este recorte não concede capacidades.

## Gates para a próxima etapa real

- Conectar emissores confiáveis de sessão Portal, membership oficial,
  MFA e auditoria, com checagens de revogação e permissões atuais.
- Verificar Google OAuth readonly por empresa, não herdado do OpenSEO;
  cuidar de armazenamento cifrado, rotação, revogação e consentimento.
- Testar o PostgreSQL e RLS sob role não-superuser/não-bypass, com
  concorrência, backup/restauração e rollback na VPS de homologação.
- Somente exibir Visitas, Leads, Clientes quando analytics e CRM
  autorizados oferecerem fontes e atribuição legitimamente vinculadas.
- Revisão independente AppSec/DBA/LGPD/SEO e aceite formal de release.

Referências: [SEO Engine F106](https://github.com/rafhael100/imagine-seo-engine/pull/153)
e [Portal SEO F106](https://github.com/rafhael100/imagine-portal/pull/843).

**Sem alterações em main, DNS, VPS, billing, OpenSEO ou clientes.**
