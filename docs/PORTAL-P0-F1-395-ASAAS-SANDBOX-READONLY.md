# F1-395 — primeiro contato com o Asaas Sandbox (somente leitura)

**Estado: DRAFT / NO-GO.** A verificação em CI é inteiramente offline. Este recorte **não** configura conta, webhook ou cobrança, não muda uma transação e não autoriza serviços.

O ambiente, o prefixo da chave e os headers são baseados na [autenticação oficial do Asaas](https://docs.asaas.com/docs/autentica%C3%A7%C3%A3o-1). A API [GET /v3/payments](https://docs.asaas.com/reference/list-payments) é documentada para consultas; a verificação usa **apenas** `GET https://api-sandbox.asaas.com/v3/payments?offset=0&limit=1`.

## O que está disponível no repositório

- `AsaasSandboxReadinessProbe`: endpoint, método e paginação fixados; exclusivamente chaves que começam em `$aact_hmlg_`; rejeição de chaves `$aact_prod_`; User-Agent, TLS peer/hostname, sem proxy/redirect, limites de conexão, tempo e payload. Só retorna booleano; nenhuma cobrança é impressa ou salva.
- `apps/portal/ops/psp/asaas-sandbox-readonly-preflight.php`: script **CLI** (não rota HTTP), exclusivamente `APP_ENV=staging`, `ASAAS_SANDBOX_READONLY_PROBE=1`, `--probe` explícito, arquivo secreto 0400/0600 pertencente ao usuário, não symlink. **Recusa GitHub Actions** mesmo com opt-in, e não lê variáveis contendo chaves.
- `tests` offline: respostas 401/403/429/302/500, JSON inválido, estrutura inesperada, excesso de dados, chave de produção e header injetado devem ser recusados.
- Ajuste em `AsaasSandboxGateway::settled`: um cartão `CONFIRMED` não é `RECEIVED` e não pode ser interpretado como liquidação. Bloqueio adicional de estornos, chargebacks, pagamentos recorrentes e moeda estrangeira. A fonte F1-390 continua exigindo prova **independente** e nova consulta PSP.

## Execução manual FUTURA em homologação privada, nunca na produção

Não colocar token no chat, GitHub, comando shell, histórico ou variáveis de CI. Após preparar a VPS e o vault, o operador pode fornecer a chave Sandbox por arquivo protegido pertencente ao mesmo usuário do processo. Exemplo ilustrativo, sem gerar arquivos sensíveis:

```bash
APP_ENV=staging ASAAS_SANDBOX_READONLY_PROBE=1 \
php apps/portal/ops/psp/asaas-sandbox-readonly-preflight.php \
  --probe --credential-file=/caminho/privado/asaas-sandbox-api-key
```

A saída informa apenas sucesso ou bloqueio. **Não executar** com chave de produção, nem usar essa verificação como autorização para gerar cobranças. Um sucesso mostra somente que há conectividade/autenticação read-only e que uma listagem vazia/limitada responde conforme o formato; não valida contrato, gateway completo, reserva de webhook, Pix Automático, IP allowlist, dados fiscais, IAM/MFA, pagamento liquidado ou autorização Sites.

A ativação da API, das migrations na Leaseweb, dos endpoints HTTP ou do worker só pode ocorrer depois de revisão independente, credenciais guardadas fora do GitHub e gates de operação/segurança.
