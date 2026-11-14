# Portal P0 — conciliação de proteção SQL assinada com catálogo e checkout Asaas

## Escopo e rastreabilidade

Esta candidata parte da [PR #745](https://github.com/rafhael100/imagine-portal/pull/745), já aprovada em **10/10 workflows** na HEAD de origem. Recupera os controles de isolamento de identidade e pós-venda da [PR #742](https://github.com/rafhael100/imagine-portal/pull/742), HEAD `042472ed4d18b35fd11da4c6ba728066562e08f3`, para corrigir a diferença crítica de acesso SQL entre elas. **Cópia de código rastreada, não merge de histórico Git**.

### Conciliado nesta branch

1. `customer-schema.sql` exige `FORCE ROW LEVEL SECURITY` para clientes/pedidos; tabela de segredo HMAC de SQL com `pgcrypto`, inacessível ao login de aplicação; função `preview_order_allowed` valida uma autorização assinada em vez de confiar em GUC arbitrária.
2. `CustomerPreviewStore` utiliza `SET LOCAL` para principal e MAC dentro da transação. Consulta, pedidos, alterações, listagem e leitura administrativa ficam vinculadas ao principal autenticado. Login sintético usa lookup dedicado, sem `SELECT *` de identidades.
3. **Preservação da experiência comercial da #745:** `create` continua recebendo a oferta validada no servidor, grava `offer_snapshot` imutável, aceita apenas a mesma versão/preço na repetição do idempotency key e apresenta o snapshot ao cliente/admin. Não altera preço ou serviço já contratado.
4. `CustomerWorkPreview` roda sob o mesmo escopo SQL assinado e protege histórico de eventos de produção, ajustes e aprovação com RLS obrigatório.
5. `prepare.py` gera segredo isolado e aleatório para a base fictícia, sem o publicar. O `router.php` exige configuração restrita, `private-preview-route-gate.php` bloqueia rotas de laboratório compartilhado fora do runner. Nenhum destes arquivos deve ser reutilizado em produção.
6. Rotas de escrita do preview recebem limites por usuário com sessão já verificada. Não há publicação de Sites ou cobrança real.
7. A CI combina as suítes de contrato, ofertas comerciais/Asaas e navegação com verificações adversariais PostgreSQL 18 de `SELECT` não autorizado, claims assinados inválidos, enumeracão de identidades e eventos; testes de segurança da borda HTTP sem efetuar solicitações externas.

### Limites

- O RLS assinado desta PR é um **laboratório para identidades fictícias**, não IAM/MFA, contrato legal, CNPJ verificado ou controle de produção.
- `GITHUB_ACTIONS` não é uma credencial de produção. Um único repositório com histórico amplo não aprova automaticamente deploy. Revisar comparativos dos outros 19 arquivos compartilhados divergentes, especialmente controle de telas e limites de rate.
- A base vigente do checkout é mantida; os demais 27 arquivos exclusivos da #742, incluindo scripts antigos de integração CRM/Sites, **não são automaticamente promovidos**. Integração multissistema precisa de gate e fonte de autoridade operacional independentes.
- A CI usa Postgres 18 e Chromium em runner descartável. **Sem execução na VPS**, nenhum token Asaas, boleto, Pix, cartão ou pagamento real.
- Conclusão operacional exige backup/restauração, AppSec/DBA, Sandbox Asaas real, IAM/contrato, fonte de liquidação/revogação independente, staging privado e decisão de lançamento.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CHARGE.**
