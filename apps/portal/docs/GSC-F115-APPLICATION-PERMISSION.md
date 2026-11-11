# GSC-F115 — Alinhar o vocabulário de permissões do Laravel à migration F113

## Correção no código da aplicação

Antes desta etapa, a migration F113 admitia `seo.gsc.connect` em PostgreSQL, mas o enum tipado `App\\Domain\\Authorization\\Permission` ainda só conhecia quatro permissões. Logo, o avaliador puro `OrganizationAuthorizationService` rejeitava o novo código como desconhecido, mesmo diante de um grant explícito.

A F115 acrescenta **apenas** a entrada `ConnectGoogleSearchConsole = 'seo.gsc.connect'` ao enum do domínio, mantendo ausência de curingas ou autorização automática por papel. Os testes PHP verificam alinhamento exato com a migration, negação padrão até para proprietários da empresa, concessão somente ao membro explicitamente autorizado, isolamento entre empresas/usuários, bloqueio de vínculos suspensos/revogados e negação de escopos amplos.

O avaliador é **puro e não autentica** a origem da identidade, o MFA, a propriedade Google, consentimento ou revogação concorrente. Nada deste commit liga o código às rotas HTTP, service provider real ou token exchange. Os contratos PostgreSQL F110–F114 continuam sendo laboratórios descartáveis; nenhuma migration foi aplicada em ambientes reais.

## Release gate

Não mesclar com main nem publicar até existir o emissor de sessão confiável do Portal, autoridade transacional, OAuth Google com PKCE/secret vault, grant por propriedade verificada e revisão AppSec/DBA distinta do autor. A PR não cria gastos, deploy, DNS ou acesso a dados de clientes.
