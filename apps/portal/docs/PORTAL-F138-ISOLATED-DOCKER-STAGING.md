# Portal F138 — stack Docker isolada de homologação (somente proposta)

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continua a [RC49 #887](https://github.com/rafhael100/imagine-portal/pull/887). Nenhum comando desta documentação foi executado em VPS real nesta entrega.

## Arquitetura

O diretório `apps/portal/deploy/staging` contém um stack **somente para futura homologação manual**:

- `app`: PHP 8.4-FPM Laravel 13, dependências do `composer.lock`, sem segredos embutidos. Código imutável em imagem; só `storage` e `bootstrap/cache` em volumes próprios.
- `web`: Nginx que serve **somente `public/`**. A única entrada PHP permitida é `index.php`, sem indexação e sem execução arbitrária de outros arquivos PHP.
- `db`: PostgreSQL 18 com volume próprio, sem publicar porta externa. Rede interna separa DB de Nginx; nenhum container do CRM/Sites/Agentes/Universidade é compartilhado.
- **HTTP de staging publica apenas `127.0.0.1:18081`**. Só um proxy HTTPS previamente aprovado no host poderá expor domínio de homologação; não usar esse HTTP diretamente na internet.
- A conta DB proprietária da inicialização/migrations é **diferente** da conta do app. O script `initdb/create-app-role.sh` cria runtime `NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT`, sem GRANT de tabelas.
- `grant-runtime.sql` libera **apenas SELECT de administradores e grants SEO, UPDATE das colunas do contador TOTP, CRUD da tabela `sessions` e do cache/lock do rate limiter**. Não concede UPDATE de chave GSC/SEO, nem INSERT na auditoria administrativa, nem CREATE em schemas.
- Migration F138 para `cache/cache_locks`, permitindo que o rate limit de login seja compartilhado entre processos PHP, em vez de confiar em cache `array`.

## Preparação manual futura — sem executar antes dos gates

1. Criar subdomínio HTTPS próprio de homologação, restringir acesso por rede/VPN, conferir reverse proxy, firewall, backups de volume, política de dados e contas técnicas.
2. Conferir e revisar a PR, imagem Docker e dependências. **Não mesclar automaticamente em main.**
3. Copiar `.env.staging.example` para `.env.staging` somente no servidor aprovado, com permissões `0600`. Gerar `APP_KEY` e senhas fortes **fora do repositório**. Nunca compartilhar esse arquivo no chat ou Git.
4. Executar `docker compose --env-file .env.staging -f compose.yaml config --quiet` para validar sintaxe sem imprimir segredos. Nome/porta/volumes são exclusivos do Portal staging.
5. Após aprovação DBA e backup, criar banco por `up -d db`; o script de bootstrap cria a conta runtime **apenas no primeiro início do volume**.
6. Executar migrations **como o owner separado**, não com a role runtime. Só após conferência e revisão das migrations, aplicar `grant-runtime.sql` usando `psql -v app_role=...` com owner. Nunca conceder SUPERUSER, BYPASSRLS ou privilégios indiscriminados ao app.
7. Verificar `php artisan portal-admin:staging-preflight` dentro do container em ambiente staging autorizado, depois de configurar HTTPS e sessão de banco. A flag de admin HTTP permanece desabilitada até aceite específico.
8. Iniciar `app/web` e conferir `/up` por loopback, retorno 404 de `/portal-admin/login` enquanto desligado, cookies seguros atrás de TLS e reversão em ambiente isolado.
9. Para provisionar um administrador de homologação, usar CLI separada F133 e caminho de segredo privado, com arquivo TOTP protegido. Nunca permitir self-registration ou reutilizar credenciais do CRM.

## Pontos que exigem validação antes de qualquer implantação

- Revisão independente AppSec/DBA/LGPD, digest/pinning de imagens, SBOM, updates, Hardening PostgreSQL, monitoramento, limitação de recursos, isolamento de rede e restrição de acesso ao Docker daemon.
- Garantir que migrations e grants sejam aplicados separadamente: **o comando Docker Compose não executa migrations nem libera admin automaticamente.**
- Testar TLS do proxy host, `APP_URL`, HSTS, cookies Secure/HttpOnly/SameSite, sessões persistentes entre processos, cache de tentativas, backup/restauração, limites de acesso e rollback.
- Validar contratos/tenants reais e OAuth Google; nenhuma tela de cliente é alimentada por este stack.
- Esta configuração é proposta de homologação, não referência definitiva para produção. Senhas nunca devem aparecer em parâmetros persistidos no histórico shell ou logs compartilhados.
- A inicialização PostgreSQL só executa `docker-entrypoint-initdb.d` num volume **novo e vazio**; alterações de credenciais exigem operação DBA supervisionada.

**ReleaseStatus: HOLD_APPROVED_VPS_STAGING_TLS_DB_PERMISSIONS_BACKUPS_SECURITY_REVIEW.**

