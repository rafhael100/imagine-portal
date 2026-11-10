# PORTAL-F64 — Docker privado para homologação, sem ativar clientes

**Escopo real:** construir uma imagem PHP 8.4/Laravel 13 a partir do `composer.lock` e executar, em contêineres isolados, o endpoint de saúde já existente. É uma **infraestrutura verificável**, não um Portal liberado. Não há credenciais, contas, SQL/migrations, CMS, páginas de cliente, API, autenticação, pagamento ou publicação.

## O que o pacote prepara

- PHP 8.4-FPM, dependências travadas no `composer.lock`, Composer sem plugins nem scripts, PHP extensions necessárias, execução como usuário não root
- Gateway nginx com **única rota `GET/HEAD /up`** e 404 em todo o resto; solicitações de escrita negadas; sem acesso ao diretório `public` por rota genérica
- Porta **apenas `127.0.0.1:18080`**, nunca `0.0.0.0`; rede interna Docker sem acesso público
- Sistema de arquivos dos dois contêineres somente leitura, `tmpfs` efêmero para áreas exigidas, capacidades Linux removidas e `no-new-privileges`
- Sem PostgreSQL/Redis/queue, sem sessão persistente, sem chamada a fornecedores
- CI de build/smoke de contêineres na PR para confirmar `/up=200`, demais caminhos 404, só uma rota Laravel e ausência de porta pública

## Execução opcional somente após a VPS estar disponível

Na máquina de homologação Ubuntu com Docker Compose, um checkout revisado e isolado e **sem qualquer DNS público**, dentro da raiz do projeto:

```bash
umask 077
printf 'PORTAL_F64_APP_KEY=base64:%s\n' "$(openssl rand -base64 32)" > infra/homologation/f64/.env
docker compose --env-file infra/homologation/f64/.env \
  -f infra/homologation/f64/compose.yaml config --quiet
docker compose --env-file infra/homologation/f64/.env \
  -f infra/homologation/f64/compose.yaml up -d --build
curl -fsS http://127.0.0.1:18080/up
```

Para encerrar:

```bash
docker compose --env-file infra/homologation/f64/.env \
  -f infra/homologation/f64/compose.yaml down -v --remove-orphans
rm -f infra/homologation/f64/.env
```

Se for necessário visualizar o health de outra máquina, prefira **túnel SSH** e jamais abra a porta na firewall. A aplicação não exibe as telas nesta stack; as sete telas da prévia são exportadas offline pelo F58.

## Gates operacionais que continuam faltando

Liberação e IP da VPS; container registry e atualização controlada; administração segura de segredos; backup/restore anti-rollback; banco isolado e RLS; Laravel AuthN/MFA/tenant; TLS/domain/reverse proxy; monitoramento/alertas; AppSec/DBA/LGPD; aceite versionado; integradores de Sites/Performance/CRM; aprovação formal de rollout. A finalidade F64 é provar **o envelope mínimo do runtime privado**, não antecipar essas decisões.

**Nunca publicar diretamente esta stack ou substituir o gateway por roteamento geral sem revisão de autenticação e segurança. PR DRAFT.**