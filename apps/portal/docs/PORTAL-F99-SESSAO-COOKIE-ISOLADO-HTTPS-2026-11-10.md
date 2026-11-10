# PORTAL-F99 — Cookie de sessão próprio, seguro e restrito ao host

Data: 10/11/2026. Estado: segurança preparatória; login operacional NÃO ativado.

## Contrato do Portal

O cookie futuro do Portal possui um nome fixo exclusivo:

- Nome: __Host-imagine-portal-session
- Secure: true (exige HTTPS no navegador)
- Domain: null (sem atributo Domain; cookie exclusivo do host)
- Path: /
- HttpOnly: true
- SameSite: lax
- Partitioned: false

As opções que determinam o alcance do cookie não aceitam overrides de SESSION_COOKIE, SESSION_DOMAIN, SESSION_PATH, SESSION_SECURE_COOKIE, SESSION_HTTP_ONLY, SESSION_SAME_SITE ou SESSION_PARTITIONED_COOKIE vindos de ambiente compartilhado.

O prefixo __Host- é suportado pelos navegadores modernos apenas quando o cookie foi definido por HTTPS, sem Domain e com Path=/. O prefixo reduz o risco de um subdomínio definir ou substituir o cookie do Portal. Ele **não substitui** controle de sessão confiável, CSRF, MFA, autorização e TLS real.

O CRM Imagine manterá seu próprio login, sessão, identidade e banco; este PR não altera nem presume o nome do cookie efetivo do CRM. A garantia aqui é evitar a reutilização de um namespace padrão genérico ou extensão a subdomínios.

## Ambiente e testes

- O scaffold usa SESSION_DRIVER=array, sem sessão persistente nem login real. O health-check /up é a única rota autorizada.
- Na máquina local sem HTTPS, cookies __Host- não devem ser usados para simular autenticação real. Um teste de interface autenticada requer origem HTTPS em homologação futura, com certificados válidos.
- PortalSessionCookieIsolationTest verifica as opções carregadas pelo Laravel e ausência de Set-Cookie em /up e em caminhos de negócio não implementados.
- F99 adiciona um auditor estático das opções de sessão e casos negativos: nome compartilhado com CRM, perda do prefixo __Host-, escopo de domínio, caminho incorreto, Secure/HttpOnly desativados, SameSite ampliado, parametrização por ambiente, duplicidade de opções e symlinks.
- GitHub Actions Node 22/24 verifica as opções e os gates F93/F94. O PR executa PHPUnit/Laravel e o smoke Docker privado para detectar incompatibilidades.

## Pendências de autenticação

Ainda não existem autenticação de cliente, sessão persistente homologada, refresh/revocation, rotação de token, MFA operacional, validação LGPD/DBA/AppSec nem DNS/HTTPS público do Portal. O arranjo de cookie não concede direito de acesso e não ativa CRM/SSO.

A F98 mantém os serviços de autorização configurados para negar tudo. Sem merge na main, sem deploy na VPS, sem dados reais, sem custos novos. PR DRAFT / NO-GO.
