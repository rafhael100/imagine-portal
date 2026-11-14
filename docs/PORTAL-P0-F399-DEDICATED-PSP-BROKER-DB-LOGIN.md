# Portal P0 — broker PostgreSQL com credencial própria (CI/NO-GO)

## Mudança implementada

Na revisão anterior, o processo de reconciliação Asaas utilizava `docker compose exec database psql -U postgres` e depois `SET ROLE purchase_rehearsal_psp`. O acesso era amplo antes da mudança de role e não servia como base segura para o worker operacional.

Esta candidata faz o seguinte no **ambiente exclusivamente sintético**:

- `prepare.py` gera duas senhas separadas e aleatórias: aplicação e broker. Durante o **initdb descartável**, é criada `purchase_rehearsal_psp LOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION`.
- A senha PSP é gravada somente em `.private/broker.pgpass`, com proprietário UID 70, modo 0400 e montagem somente leitura. Nem o contêiner Laravel nem o GitHub recebem o segredo em variáveis públicas, argumentos do comando ou logs.
- O serviço `broker` em `compose.yaml` é um cliente `psql` de uso único: imagem `postgres:18-alpine`, usuário 70:70, sem portas, rede Docker `private` marcada `internal`, sistema de arquivos read-only, `cap_drop: ALL`, `no-new-privileges`, `restart: no`. O serviço não fica permanentemente executando nem expõe o Docker socket.
- `Database.sql()` usa `docker compose run --rm -T --no-deps broker ... -h database -U purchase_rehearsal_psp`; **não** usa `postgres` ou `SET ROLE`. A comunicação é TCP interna com autenticação do PostgreSQL.
- `sandbox-payment-schema.sql` recusa a instalação se a conta PSP não existe ou se possui flags privilegiadas. A role financeira tem SELECT/UPDATE apenas em campos de intenção e status; nunca recebe `customer_id`, dados fiscais, `offer_snapshot`, `preview_scope_secret` nem filiação à role da aplicação.
- Preflight de Compose e arquivos privados bloqueia exposição de portas, execução como root, drop de capabilities ausente, mudanças de entrypoint e variáveis adicionais contendo credenciais.
- Testes GitHub em PostgreSQL18 real descartável provam login direto, política RLS, recusa de leitura de identidade/chave HMAC, worker de cobrança simulado, estorno, reinicialização e restore. Nenhuma chamada externa PSP é feita.

## Verificador da homologação privada

Foi criado `apps/portal/ops/private-purchase/check_staging_runtime_readonly.py` para executar uma avaliação **somente leitura** do servidor, após a instalação privada e com a aplicação já funcionando. O comando não instala nem sobe serviços, não acessa a API Asaas e não imprime segredos:

```bash
# No próprio servidor Leaseweb, em ambiente privado previamente revisado:
sudo env APP_ENV=staging PORTAL_STAGING_READONLY_CHECK=1 \
  python3 apps/portal/ops/private-purchase/check_staging_runtime_readonly.py --check
```

Valida metadados dos quatro serviços Docker, arquivos privados, rede interna, gateway acessível **somente por 127.0.0.1**, login SQL do broker com `rolsuper=false` e `rolbypassrls=false`, além de recusar consultas SQL de identidade e chave HMAC. Também verifica que o broker não ficou executando como serviço persistente. Em caso de qualquer erro: `NO-GO`, retorno não zero, **sem ação financeira**. Os testes offline `--self-test` cobrem erros de status HTTP, roles e topologias; o modo `--check` recusa GitHub Actions.

Esta inspeção não valida TLS do proxy de borda, IAM/MFA, contratos, consentimento, política de retenção, Asaas real, webhooks, revogação Sites ou restauração fora do laboratório. Mesmo com PASS, o gate de publicação continua **NO-GO**.

## O que ainda NÃO foi validado

A invocação do Docker no host continua sendo privilégio operacional do host, mesmo que o **usuário SQL** do processo seja restrito. Isso não é um desenho final de execução de worker em produção com service account e isolamento de runtime independente. A instalação na Leaseweb depende de aprovação AppSec/DBA, identidade e armazenamento de segredos no vault, rede privada/TLS, observabilidade, migrações auditáveis, retenção de credenciais, restauração/rollback real e contratos/jornada jurídica.

A credencial gerada neste código destina-se somente ao **PostgreSQL sintético descartável**; ela não deve ser reutilizada em produção. O modo de execução Asaas segue protegido por opt-in explícito de homologação e não é autorizado em CI. **Não ativar cobranças reais, webhooks, site ou publicação.**

**Status: DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-BILLING / NO-PUBLISH.**
