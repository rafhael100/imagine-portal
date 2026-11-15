# Portal F132 — primeira autenticação administrativa com senha e TOTP

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação técnica após [Portal SEO RC47 #873](https://github.com/rafhael100/imagine-portal/pull/873) e [F131 #876](https://github.com/rafhael100/imagine-portal/pull/876).

## O que esta entrega implementa de verdade

Até aqui, a base Laravel do Portal não possuía conta administrativa nem login real. A F132 cria uma fundação separada das credenciais e sessões do CRM:

- Tabela `portal_admin_users`, sem criar contas automaticamente, com e-mail canônico único, senha gerada por `Hash::make()`, segredo TOTP cifrado com a chave do Laravel, conta inicialmente inativa e contador de uso único.
- Modelo `PortalAdminUser` em guard de sessão `portal-admin`. **Credenciais do CRM não são aceitas.**
- Rotas de login, MFA, área administrativa vazia e logout sob `/portal-admin/*`.
- Rejeição por padrão: `PORTAL_ADMIN_AUTH_ENABLED=false`. **As rotas nem são registradas** no Laravel enquanto a flag estiver falsa; middleware ainda nega requisições se for desligada após criar o route cache. Não há registro público nem recuperação de conta por e-mail.
- Antes do TOTP: senha validada, rate limit e **sessão apenas pendente**, sem login no guard; ID de sessão e token CSRF substituídos.
- Após TOTP: código RFC6238/HMAC-SHA1 de seis dígitos com tolerância de um intervalo, segredo previamente provisionado em formato hexadecimal e cifrado; UPDATE atômico do contador `last_totp_counter` impede reaproveitar o mesmo código entre sessões concorrentes.
- Somente após validar o segundo fator ocorre `Auth::guard('portal-admin')->login(...)` e renovação de sessão.
- A rota `/portal-admin` verifica novamente usuário ativo, sessão MFA e prazo antes de apresentar **somente um painel administrativo vazio**, sem acesso a dados comerciais.
- Logout POST invalida sessão e atualiza token CSRF.
- Páginas com identidade Imagine `#F9941D`; sem JavaScript/CDN externo; respostas privadas não armazenáveis, `noindex` e proteção contra iframe.

## Requisitos para ativar em staging

**Não habilitar ainda sem revisão de segurança.** A configuração só deve ser ativada após existir:

1. Banco PostgreSQL próprio do Portal, migração controlada, administrador autorizado, segredo TOTP gerado com método seguro e provisionamento fora do navegador.
2. `APP_KEY` forte e protegido, `APP_URL=https://...`, `SESSION_DRIVER=database` ou `redis`, `SESSION_SECURE_COOKIE=true` e sessões persistentes/criptografadas.
3. Gestão de usuários e contas (criação somente por operador autorizado), reset seguro de credenciais, recuperação MFA com dupla verificação e trilha de auditoria.
4. Revisão AppSec/DBA/LGPD, rate limiting Redis compartilhado, observabilidade e logs sem senhas/códigos.
5. Staging VPS com TLS, CSRF, cookies, cabeçalhos, testes de sessão e reversão operacional.
6. **Autorização por empresa** para conectar o login administrativo a recursos do Portal/Sites/SEO Engine. A F132 ainda não implementa contas de clientes, membros de empresas, permissões `seo.view` ou acesso a serviços.

## Testes reais

`.github/workflows/portal-f132-admin-login-pg18-ci.yml` usa PHP 8.3/8.4, Laravel 13 e PostgreSQL18 descartável:

- Senha correta nunca autentica antes do segundo fator.
- Conta inativa e senha incorreta são recusadas; tentativas de senha são limitadas.
- MFA antes da senha ou após a expiração do desafio é negado.
- Código correto concede sessão; logout a encerra; reutilização do TOTP falha por contador em banco.
- Desativação da conta corta acesso, mesmo quando o identificador foi previamente carregado na sessão.
- Rotas de clientes, cadastro público e integração Google permanecem 404.
- Teste unitário do vetor HMAC/TOTP RFC6238 e códigos malformados.
- Os testes gerais sem banco ignoram a suíte específica F132; a matrix PG18 é a fonte de evidência para essa funcionalidade.

A execução da CI usa somente credenciais sintéticas e chave de criptografia descartável. Nenhuma conta real é criada e nenhuma configuração da VPS foi modificada.

**ReleaseStatus: HOLD_ACCOUNT_PROVISIONING_AUTHORIZATION_STAGING_AND_SECURITY_REVIEW.**

## Ajuste de compatibilidade do framework
A primeira CI de autenticação passou no PostgreSQL, mas o workflow geral mostrou três testes legados que exigem apenas as rotas `/up` e `storage/{path}` enquanto a funcionalidade estiver inativa. Para preservar essa garantia, o arquivo de rotas agora só registra `/portal-admin/*` se a flag estiver verdadeira **no bootstrap**. A CI específica F132 habilita a flag exclusivamente nos contêineres descartáveis; a CI geral continua sem rotas administrativas. Em uma implantação futura, habilitar exige reconstrução controlada do route cache no ambiente correto.
