# Portal F133 — provisionamento seguro da primeira conta administrativa

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Complementa [F132 #877](https://github.com/rafhael100/imagine-portal/pull/877).

## O que foi implementado

A F132 oferece um fluxo de autenticação administrativa com senha e TOTP, mas não existia mecanismo de criar uma conta fora dos testes. A F133 adiciona o comando de servidor `php artisan portal-admin:bootstrap`, sem disponibilizar nenhuma rota de cadastro público.

O comando:

- É **bloqueado por padrão**. Só funciona em `APP_ENV=testing` com flag exclusiva de CI, ou em `APP_ENV=staging` quando `PORTAL_ADMIN_PROVISION_ENABLED=true` for explicitamente configurado. Não roda em produção por engano.
- Exige PostgreSQL e `APP_KEY` configurada.
- Requer e-mail válido e senha com pelo menos 14 caracteres, maiúsculas, minúsculas, número e símbolo. Em staging a senha é digitada por prompt de terminal **oculto**. Em CI usa um valor sintético exclusivamente para o runner.
- Gera 20 bytes de TOTP com `random_bytes`, codifica como Base32 RFC4648 e prepara URI `otpauth://` para o autenticador.
- Armazena no PostgreSQL **somente** hash da senha e segredo TOTP cifrado com Laravel `Crypt`.
- Cria um arquivo de inscrição TOTP novo, modo **0600**, sob um diretório privado modo **0700**, fora do repositório; recusa arquivo existente, caminho relativo, pasta pública ou antecessores simbólicos.
- Executa criação da conta e gravação exclusiva do arquivo sob controle transacional: falha desfaz o registro e tenta retirar o segredo do disco.
- Não imprime senha, chave TOTP nem URI de inscrição nos logs.
- Cria a conta **inativa por padrão**. A opção `--activate` exige decisão explícita e deve ser usada apenas após verificar a pessoa e o ambiente.

## Operação futura de homologação — exemplo, não executado

Somente após revisão e configuração segura da VPS staging:

```bash
mkdir -m 700 -p /srv/imagine/private/admin-bootstrap
php artisan portal-admin:bootstrap \
  --email=administrador@exemplo.com \
  --totp-file=/srv/imagine/private/admin-bootstrap/inscricao.txt \
  --activate
```

O comando solicitará a senha em um terminal interativo. O arquivo privado conterá o código Base32 e a URI de cadastro no aplicativo autenticador. Deve ser entregue ao operador autorizado por canal seguro e eliminado do servidor após o pareamento, conforme a política de segurança. **Não colar o conteúdo em tickets, chats, GitHub ou logs.**

O provisionamento não ativa automaticamente as rotas HTTP: a flag `PORTAL_ADMIN_AUTH_ENABLED` continua separada e desligada por padrão. Para staging real, sessão persistente, HTTPS e cookie seguro são obrigatórios.

## Evidências CI

O workflow F132/F133 executa PostgreSQL 18 em runner descartável, PHP 8.3/8.4 e testes de login reais F132. A suíte adicional F133 valida:

- Vetor RFC4648 Base32 e formato `otpauth://` sem chamadas externas.
- Conta criada com senha hash, segredo cifrado e sem ativação padrão.
- Arquivo 0600 contendo segredo compatível com o dado cifrado; nenhuma senha/segredo aparece na saída do comando.
- Ativação por flag explícita e recusa de conta duplicada.
- Recusa de arquivo existente, diretório permissivo e execução fora de ambiente autorizado.
- Nenhum banco ou arquivo de produção é alterado.

## Bloqueios de produção

Ainda faltam administração de contas e revogação segura de acesso, recuperação de senha/MFA, logging e auditoria de provisionamento, identidade/organizações reais, proteção de canal privilegiado, KMS, teste staging de sessão e CSRF/TLS, revisão AppSec/LGPD e autorização de deploy. O comando não libera qualquer dado do CRM, GSC, Sites ou SEO Engine.

**ReleaseStatus: HOLD_STAGING_ADMIN_OWNER_MFA_ENROLLMENT_AND_SECURITY_REVIEW.**
