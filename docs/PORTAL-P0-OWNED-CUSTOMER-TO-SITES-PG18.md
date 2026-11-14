# Portal P0 — clientes individuais → Sites PostgreSQL18/outbox (laboratório)

**Objetivo:** ligar duas provas antes separadas no CI, mantendo os quatro pedidos verdadeiramente escritos pelas contas fictícias individuais no Portal Laravel e transportando **somente os dois pedidos Sites elegíveis** ao mecanismo PostgreSQL18 do Imagine Sites. Esta é uma prova de persistência e compatibilidade de código, **não de autorização comercial real**.

## Fluxo integrado

1. O GitHub executa o navegador Chromium no site Comercial Astro compilado: quatro CTAs levam a cadastro, pedido individual e briefing no Portal Laravel.
2. Uma segunda instância PostgreSQL18, com volume e rede efêmeros, é iniciada no runner. O banco Portal permanece isolado na rede Docker original.
3. O runner extrai somente quatro identificadores fictícios, o SKU, o estado de pagamento **simulado** e a presença de briefing dos registros realmente persistidos em `purchase_rehearsal.customer_orders` para as quatro contas de teste Comercial. O extrato nunca contém nome, e-mail, telefone ou hash de senha; não vira artefato.
4. Código Portal pinado gera os comandos Onepage/Landing, além de assinaturas e atestações fictícias, enquanto código Sites pinado aplica admissão, nonce persistente, idempotência e outbox em **outro banco real PostgreSQL18**.
5. Um worker SQL restrito reclama e confirma os **dois** eventos fictícios com o token lease verdadeiro; Google Ads e Meta Ads continuam fora do fluxo Sites.
6. O workflow valida e guarda por sete dias um pequeno JSON sanitizado com SHA-256 de **integridade, não assinatura ou aprovação**.

## Negativas obrigatórias

- Performance não se torna projeto Sites.
- Um pedido cuja prova fictícia de briefing foi removida é recusado antes do encoder.
- Repetir assinatura e nonce não pode ingressar outro projeto; nonce novo + mesma operação deve ser idempotente.
- Nenhum pedido de outra empresa ou usuário real pode entrar.
- Não existe endpoint HTTP público, cliente real, cobrança PSP, contrato jurídico, identidade S2S, testemunha externa de revogação, publicação de site ou mudança na VPS.

**Limite crítico:** o extrato é obtido pelo *administrador de banco sintético no CI*; isto **não** demonstra que o serviço operacional Portal poderá emitir contratos autorizados. A integração real requer IAM/tenant, PSP verificado, consentimento/contrato, revogação e autorização de publicação end-to-end, revisão DBA/AppSec e migração/backup na infraestrutura privada.

**DRAFT / NO-GO / sem merge, deploy, DNS, cliente, PSP ou custo de fornecedor.** A decisão de habilitar serviço somente pode vir de evidências operacionais independentes. 
