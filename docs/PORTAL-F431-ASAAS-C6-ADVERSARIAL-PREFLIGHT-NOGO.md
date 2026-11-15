# F431 — Testes adversariais dos gateways (Asaas/C6) sem dinheiro real

## Objetivo

Preparar a homologação operacional do roteamento de pagamentos por cenário de falha,
mantendo todas as rotas de cobrança **desativadas**. O teste é independente de
credenciais reais: o Asaas é exercitado por transporte falso injetado;
o C6 por certificados PEM/P12 inteiramente gerados na CI, sem tráfego bancário.

## Evidências automatizadas

| Eixo | Teste executado | Limite |
|---|---|---|
| Asaas POST | ativação fictícia R$980: cliente, referência, valor, vencimento e método no payload | sem escrita remota |
| Asaas falha | HTTP 401/403/409/422/429/500/502/503/504, redirecionamento, timeout e resposta malformada | falha incerta, sem repetição cega |
| Asaas conciliação | GET apenas por ID exato, valida valor/cliente/referência, status e reversões | sem extrato do Asaas real |
| Asaas webhooks | token de comprimento mínimo e comparação constante, inválidos rejeitados | webhook real ainda pendente |
| PSP routing | C6 desabilitado, não usado por preço mais barato; sem fallback oculto; faturas existentes presas ao PSP original | não altera decisão administrativa |
| Worker legado | testes mockados idempotência, reconciliação, reversão e URLs permitidas | nenhuma transação bancária |
| C6 PEM/P12 | OpenSSL cria material sintético; rejeita segredo legível por terceiros, chave divergente, symlink, bundle misto e senha incorreta | não faz mTLS/OAuth real |
| Bootstrap seguro | sintaxe dos instaladores, sem executá-los na VPS | não instala arquivos reais |

## O que falta para homologação REAL

1. Credenciais **sandbox** Asaas e C6 fornecidas apenas por cofre/segredo
   sob controle Imagine, sem GitHub/chat/logs. Confirmar produtos C6 habilitados
   (Pix, boleto, cartão, Pix Automático), endpoint e contratos de tarifas.
2. Execução em **staging autorizado** com saída de rede limitada por firewall,
   certificado C6 instalado por usuário privilegiado, TLS/mTLS/OAuth
   comprovados, leitura de cobranças fictícias, webhooks, assinatura/tokens,
   devoluções e conciliação por IDs únicos.
3. Provar reprocessamento pós-timeout sem duplicidade de cobrança:
   primeiro consultar a referência no PSP; **não reemitir um POST** em estado
   incerto. Separar cada modalidade e segredo por gateway.
4. Provar que desativação do C6 não impede cobranças antigas Asaas; nenhuma
   migração automática de assinaturas, cartões, boletos ou estornos.
5. Revisão financeira, AppSec/DBA/LGPD, backup/restauração da VPS e GO formal
   antes de permitir pagamento a clientes reais.

**Esta PR é DRAFT / NO-MERGE / NO-DEPLOY / NO-BILLING.** Não afirma
Asaas/C6 homologado externamente. Rastreio #802. Esta rodada de CI não requer
nenhuma credencial de cliente.
