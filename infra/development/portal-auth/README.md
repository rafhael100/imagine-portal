# Instância privada de autenticação do Portal

Candidata de desenvolvimento, sem publicação HTTPS ou liberação de clientes. Reutiliza exclusivamente o PostgreSQL persistente já instalado; não cria nem altera banco, roles, volume ou identidades. Não executar o instalador da fundação novamente.

O aplicativo compartilha o namespace de rede do contêiner PostgreSQL existente para usar 127.0.0.1:5432. O gateway se conecta à mesma rede interna e publica somente 127.0.0.1:18082. FPM e PostgreSQL não publicam portas no host. Antes de iniciar, verificar que a rede indicada possui Internal=true e que o banco está saudável. A aplicação recebe somente runtime.json e app-key; nunca database-admin. Mounts são read-only e ambos os serviços executam sem root/capabilities, com filesystem read-only e logs HTTP desativados.

Configuração explícita no shell administrativo (sem valores de credenciais):
```sh
export PORTAL_DB_CONTAINER=imagine-portal-development-db-postgres-1
export PORTAL_DB_NETWORK=imagine-portal-development-db_private
export PORTAL_DEV_PRIVATE_DIR=/srv/imagine/portal-development/infra/development/portal-db/.private
docker network inspect "$PORTAL_DB_NETWORK" --format '{{.Internal}}'
docker inspect "$PORTAL_DB_CONTAINER" --format '{{.State.Health.Status}}'
python3 preflight.py
docker compose config --quiet
docker compose build application
docker compose up -d
python3 smoke.py
```

O smoke não autentica uma identidade: verifica saúde, CSRF, cookie, rejeição de sessão ausente, senha inválida e origem externa, bloqueio de rotas fora da lista. Pode criar envelopes criptografados expirantes no banco, sem criar usuários. Não imprimir corpos CSRF/cookies ou dumps. Os testes positivos de senha/MFA/logout usam dados sintéticos no laboratório descartável separado.

HTTPS é marcado pelo gateway fixo exclusivamente para requisições vindas do host em loopback. Não usar esse gateway via rede pública nem apontar o nginx público para ele nesta etapa. Não aceita X-Forwarded-* como prova de cliente; limitação: IP visto pela aplicação é o gateway. A integração com o proxy público exigirá avaliação explícita de TLS, IP, rate-limit e proteção de acesso.

Desativação reversível: docker compose stop gateway application. Isso não para o PostgreSQL nem apaga dados. Nunca executar down -v no projeto do banco. Após recriar o contêiner do PostgreSQL, recriar somente application/gateway para atualizar o namespace e DNS. Instalação depende de sudo e evidência de backup recente. Conta inicial, senha/MFA pelo operador, recuperação e revisão independente continuam pendentes.
