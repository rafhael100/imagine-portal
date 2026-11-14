# PORTAL-P0 — Gate de promoção da prévia autenticada para a VPS privada

**Estado:** PROCEDIMENTO DE REVISÃO, **não é autorização de implantação**. Referência do candidato: PR #688, empilhada sobre a interface #687, por sua vez sobre a prévia persistente privada #686. Nunca aplicar no Portal operacional nem no banco de identidades do Portal.

## 1. Estado de partida confirmado por #686

A prévia antiga de **checkout fictício** foi instalada no worktree isolado `/srv/imagine/purchase-preview`, stack `imagine-purchase-preview`, com PostgreSQL 18 separado e gateway **127.0.0.1:18083**. O Nginx protege **somente** `/_rehearsal/purchase` via HTTPS/Basic Auth existente. O registro #686 não comprova que `/_preview/portal` esteja roteado e protegido na VPS. Por isso, um CI verde da #688 **não significa** que login/cliente/admin já possam ser acessados pela internet.

## 2. Condições obrigatórias antes de alterar o host

- Revisão independente de AppSec/IAM, UX e DBA; fixar a **SHA exata** de todos os arquivos, verificar CI Chromium+PG e negar promoção se houver qualquer teste falho/cancelado.
- Confirmar que o operador tem acesso à VPS autorizada, janela de manutenção, plano de retorno e backup da configuração Nginx e do **volume de dados do checkout de teste**. Não depender do nome de um backup antigo sem confirmar sua existência.
- Registrar a configuração Nginx realmente instalada, incluindo herança de `auth_basic`, path de senhas, TLS/Host, limite de payload e location precedence. **Não copiar cegamente uma location**: a prévia deve continuar atrás da mesma autenticação Basic e do domínio de desenvolvimento. Garantir roteamento de `/_preview/portal`, `/_preview/portal/`, `/_preview/portal/login`, `/_preview/portal/logo`, `/_preview/portal/client/*`, `/_preview/portal/admin/*` e `/_rehearsal/purchase*` para **127.0.0.1:18083**, sem criar outra porta pública nem interferir em rotas existentes.
- Gerar e confirmar **duas senhas sintéticas distintas** somente no console privado, usando `configure_profiles.py`. Nunca compartilhar senhas reais/Basic Auth/DB pela PR, CI, screenshot ou chat. O arquivo `.private/profiles/users.json` deve ter UID82, permissão 0400, diretório 0700 e ficar fora da imagem e do controle de versão.
- Não registrar provider de homologação no Laravel global; manter `APP_ENV=staging`, `APP_DEBUG=false`, o gateway ligado apenas ao loopback e banco independente com FORCE RLS. Não utilizar identidade, assinatura, dados ou pagamento reais.

## 3. Prova de segurança e funcionalidade exigida

1. Antes de qualquer login de teste, HTTPS **sem Basic Auth** deve responder `401` para login, logo, dashboard cliente, dashboard admin e checkout. Repetir o teste no caminho sem e com barra final. Nunca usar `curl -k` (não ignorar TLS).
2. Após autenticação Basic **e antes do login sintético**, `/_rehearsal/purchase` e todos os detalhes do pedido devem negar acesso (`403`); cliente/admin não devem mostrar dados. A tela de login de teste pode ser acessada atrás do Basic.
3. Com o perfil `cliente.teste`, verificar sessão de servidor, CSRF, Onepage/Landing/Performance, transições fictícias, briefing e leitura dos mesmos registros em **Meus serviços** e **Financeiro**; conferir a coluna **Próximo passo**, assim como persistência após refresh e novo acesso.
4. Com `administrador.teste`, validar oito páginas e o *mesmo conjunto exato de IDs* de pedidos. Admin deve receber `403` em GET/POST de compra e não deve acessar páginas exclusivas do cliente. Cliente deve receber `403` nas oito páginas administrativas.
5. Testar logout, replay de cookie antigo, senha incorreta, limite de tentativas, cabeçalhos `no-store`/`noindex`, cookie Secure/HttpOnly/SameSite e responsividade em 320/390/768/1280 px; verificar que Nginx não amplia payload, métodos ou Host.
6. Reiniciar **somente** application/database da stack privada em teste controlado e comprovar que pedidos/briefings persistem, perfis sintéticos continuam segregados e toda rota pública real continua sem exposição.

## 4. Rollback / critério de parada

Se alguma condição falhar, **NÃO promover**: conservar a versão atual, restaurar o snapshot Nginx verificado e validar `nginx -t` antes de eventual reload, revertendo apenas a rota alterada e a imagem da prévia. **Nunca executar `docker compose down -v` na VPS**, nunca destruir/reescrever o volume PG de teste; não alterar `main`, banco de identidade, DNS ou gateway financeiro. Registrar resultados e hashes em PR/issue #669.

## 5. Limite de segurança inalterado

Essas contas `cliente.teste` e `administrador.teste` não provam MFA, membership nem autorização de cliente real. O bloqueio de anti-rollback **#64 permanece ABERTO**. A prova de CI ou o login privado não autorizam produção, publicação, ativação de Sites/CRM, pagamento real ou contratos. 
