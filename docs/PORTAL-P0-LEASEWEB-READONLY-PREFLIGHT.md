# Imagine P0 — preflight SOMENTE LEITURA da Leaseweb

**Objetivo:** medir se a VPS está pronta para iniciar uma homologação paralela do Comercial → Portal → Sites, **sem tocar** na instalação anterior, no banco, em credenciais, DNS ou serviços. O script pertence a uma PR *draft* e ainda não é comando de implantação.

## Execução prevista após revisão do código

Arquivo: `apps/portal/tools/integration/leaseweb_readonly_preflight.py`.

No servidor privado **com acesso autorizado**, revise o arquivo e execute localmente com Python 3:

```bash
python3 leaseweb_readonly_preflight.py --json
```

O script não precisa de `sudo`. Ele não faz download, mudança de configuração, `docker exec`, `docker compose up`, `docker compose down`, `psql` ou qualquer escrita em disco. **Não execute arquivos de uma PR por `curl | bash`**. Baixe, leia e compare o SHA antes de usar.

Ele testa exclusivamente o diretório fixo `/srv/imagine/purchase-preview` (presença, sem leitura de conteúdo), as permissões de `.private/runtime.json` e `.private/admin-password` (somente `lstat`), presença e resposta dos comandos `docker --version`, `docker compose version` e `docker info --format`, espaço livre, memória total e acessibilidade da porta **127.0.0.1:18083**. Não procura e não imprime credenciais, identidades, hosts externos nem conteúdo de contêiner.

**Saída:** metadados não sensíveis e `release_decision.status=NO_GO`, **mesmo quando o ambiente aparenta estar saudável**. `preflight_basic_host_ready` não equivale a aprovação de homologação, nem a teste de backup/restauração. Um usuário sem acesso ao socket Docker terá relatório parcial e conservador.

### O que ainda precisa de evidência

- Certificados HTTPS privados, domínio interno ou hostnames revisados e autenticação forte; sem exposição pública.
- Backup restaurado em banco independente, migrações Laravel reversíveis e política de rollback.
- Cadeia real de IAM/MFA, autorização de tenant, assinatura/revogação S2S e proteção de pedidos.
- Contrato/consentimento válidos, PSP oficial e webhooks verificados, NF e LGPD.
- Gestão de segredos fora do código, limites de recurso, monitoramento, alarmes, trabalho editorial e publicação Sites.
- Auditoria independente AppSec/DBA e aprovação específica de release.

**Não altere a instalação atual** nem reexecute `prepare.py` sobre `.private` existente. Monte homologação em contêineres e volumes separados após esses gates, com validação funcional completa e rollback. A execução da CI deste script no GitHub serve **somente para testar a segurança e o formato do comando**, não inspeciona sua VPS.
