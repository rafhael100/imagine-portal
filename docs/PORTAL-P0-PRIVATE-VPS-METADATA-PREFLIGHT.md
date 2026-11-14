# P0 — pré-validação da prévia privada antes de homologar na Leaseweb

Esta entrega transforma os requisitos de segurança de infraestrutura em um **verificador executável e somente de leitura**, `apps/portal/ops/private-purchase/preflight_private_runtime.py`. Ela **não** conecta ao servidor da Leaseweb, não lê segredos, não abre portas, não altera serviços e não faz deploy.

## O que comprova sem acesso às senhas

Recebe o JSON de `docker compose -f compose.yaml config --format json` e verifica:
- exatamente três contêineres (`database`, `application`, `gateway`);
- aplicação e PostgreSQL apenas na rede Docker `private` marcada `internal: true`, sem portas públicas;
- gateway com única porta TCP `127.0.0.1:18083 → 8080`, sem `0.0.0.0`;
- runtime Laravel com `APP_ENV=staging`, sem debug, URL privada, sem flags de GitHub CI e sem rotas legadas de compra;
- usuário não-root e filesystem read-only no Laravel e no Nginx, capabilities removidas e `no-new-privileges`;
- imagens esperadas e binds read-only dos três arquivos de segredo/boot;
- **apenas metadados** dos arquivos gerados por `prepare.py`: donos UID 82/70/0, modos 0400/0700 e ausência de symlinks.

O script falha ao encontrar exposições ou arquivos insegros. O teste offline `--self-test` injeta mutações em portas, rede, privilégios e URLs e comprova que são recusadas.

## Uso futuro na VPS privada

Depois de preparar os arquivos exclusivamente fictícios, executar **localmente na VPS**, sem enviar conteúdo para o chat ou GitHub:

```bash
cd apps/portal/ops/private-purchase
docker compose -f compose.yaml config --format json > /tmp/portal-compose-review.json
sudo python3 preflight_private_runtime.py --inspect \
  --compose-json /tmp/portal-compose-review.json \
  --private-dir "$PWD/.private"
```

A CI GitHub roda essa mesma análise sobre a configuração **sem** o override `compose.ci.yaml`, enquanto as instâncias de testes continuam em loopback. Um JSON sanitizado com hash de integridade e `release_gate=NO_GO` é armazenado por sete dias.

## Limites reais

Esta validação é de **configuração e permissões estáticas**, não prova que as imagens estejam assinadas, que o firewall externo esteja correto, que o Nginx público exija HTTPS/Basic Auth ou que o certificado seja válido. Também não verifica IAM/MFA de clientes reais, autorização de publicação, revogação anti-rollback, preço/PSP, consentimento LGPD, backup restaurável nem a própria VPS Leaseweb. As tags de imagens devem ser substituídas por digests revisados em processo posterior, antes de produção.

**Sempre NO-GO para produção e clientes reais. Sem ligação SSH nem aplicação de alterações na VPS nesta entrega.**
