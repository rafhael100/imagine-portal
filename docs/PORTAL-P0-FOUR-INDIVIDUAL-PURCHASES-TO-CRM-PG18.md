# P0 — Comercial, Portal por cliente, Sites e CRM: uma prova integrada com clientes de laboratório

## O que foi aproximado

As provas anteriores estavam em duas trilhas. Uma chegava do site Comercial a quatro **contas de clientes distintos** do Portal e depois ao Sites. Outra exercitava CRM com dois recebimentos falsos, porém não partia das contas individuais.

Esta revisão continua **a mesma execução Docker/Chromium** da PR #725, sem substituir o teste de isolamento SQL por identidade: quatro CTAs comerciais compilados pelo Astro → quatro contas privadas individuais no Laravel → pedidos e briefings PostgreSQL18 após reinício → dois comandos Sites assinados *sinteticamente*, dois projetos, outbox e ACK duráveis em segundo banco. Somente depois é criado o terceiro banco PostgreSQL18 descartável `crm_p0_consent_lab`, com fixture da PR CRM #522, commit imutável `50c1eb574aa0ed81055dc256e77302acd1781d71`.

Dois eventos do Sites já ACKados recebem **bindings fabricados apenas no CI** e simulações de consentimento positivo, sempre para `@example.invalid`. A função SQL do CRM grava dois recibos duráveis, confirma os replays idempotentes e nega ausência de opt-in, MAC adulterado, empresa forjada, alteração de payload, vencimento, e-mail não fictício e SELECT direto do papel SQL.

O workflow retém, por sete dias, os artefatos anteriores de imagens e Sites e acrescenta `imagine-owned-customers-sites-fake-consented-crm-pg18-no-go` contendo apenas contagens, hash SHA256 de integridade **não assinado** e flags explicitamente `NO_GO`. O extrato efêmero de pedidos sintéticos é apagado pela rotina de limpeza do runner mesmo se o teste falhar.

## Limitação central

As **quatro contas continuam fictícias**, não há cobrança PSP, identidade MFA real nem propriedade operacional homologada na Leaseweb. Os dois bindings CRM são escolhidos posicionalmente pelo script; não possuem relação autorizada com nenhuma empresa ou site real. O consentimento é inventado pelo teste, não obtido de visitante, e não serve como evidência LGPD. O papel SQL do laboratório é diferente do serviço CRM SaaS. Nenhum produto Sites é publicado, nenhum lead é enviado, nenhum domínio público ou VPS é tocado.

## O que ainda precisa ficar pronto para usuários reais

1. Migrar a autenticação e o isolamento SQL verificados em CI para a arquitetura IAM do Portal, com escopo empresa + cliente, revogação/fencing, MFA e recuperação/backup seguros.
2. Implementar preço/aceite/PSP reais, reconciliar pagamentos por webhooks autenticados e registrar contrato e versão do serviço antes de permitir criação de sites.
3. Construir vínculo assinado serviço-a-serviço de Portal→Sites e Sites→CRM com autoridade externa de revogação, retry pós-commit, DLQ, observabilidade, consentimento, limites de retenção e exclusão.
4. Colocar os serviços **somente na homologação privada** da VPS depois da revisão DBA/AppSec e execução dos preflights de backup/rollback, com acesso do operador e sem cobrança real.
5. Reavaliar gates `NO_GO` por evidência específica — o sucesso deste runner não pode desativar as barreiras por si só.

**DRAFT / NO-GO. Não há autorização de ativação pública, merge automático ou deploy de produção.**
