# PORTAL SEC-R03-C03 — Testemunha independente simulada para detectar rollback da Z4

**09/11/2026. Estado: ENSAIO SINTÉTICO, não implantação Cloudflare.**

## Objetivo e contexto

O PR #120 validou um Durable Object SQLite e um bucket R2 **locais**
em Miniflare/workerd. O PR #116 reproduziu a vulnerabilidade de uma cópia
antiga da própria autoridade Z4. A Issue #122 mantém a execução real em
conta Cloudflare BLOQUEADA até acesso autorizado, quotas e custo verificáveis.

Este recorte investiga um controle adicional: uma **testemunha de alta
marca** (`generation`, `sequence`, SHA-256 do estado Z4 canonizado)
armazenada num domínio de falha e de administração distinto daquele que
pode restaurar a Z4.

## Implementação de laboratório

- `src/c03-witness-model.mjs`: objeto de testemunha **apenas em memória**;
  exige inicialização em `DENY` e geração/sequência zero.
- Cada transição da máquina de estados C02 deve ser reproduzível por uma
  ação de laboratório válida, avançar uma sequência e fazer CAS do valor
  observado. A simulação guarda SHA-256 do estado e rejeita snapshots
  divergentes, dados de revogação reativados e gravações concorrentes antigas.
- `evaluateFixtureWitness`: comparação **puramente de teste**, que
  recusa testemunha indisponível/inválida, Z4 ausente, sequência/generation
  antigas e hash diferente. O retorno `canAuthorizeCustomers` e
  `canApproveRecovery` é **sempre false**, mesmo quando os estados coincidem.
- Testes dedicados conferem duas ordens de interrupção: Z4 atualizada sem
  a testemunha, e testemunha avançada com Z4 antiga. Ambos devem falhar
  fechados **no simulador**.
- Teste explícito de vulnerabilidade residual: **se a própria testemunha
  antiga for restaurada junto com Z4 antiga, os hashes voltam a coincidir**.
  O teste termina com sucesso por confirmar essa falha, não por provar
  proteção contra restauração integral.
- CI herdado do PR #120 executa os testes locais no Node 22 e 24; o job
  Miniflare/SQLite/R2 continua sem acesso à conta Cloudflare.

## Limites de confiança fundamentais

1. Um objeto JavaScript em memória **não é testemunha independente,
   durável, monotônica nem distribuída**. O experimento não implementa
   serviço externo, WORM, HSM, relógio confiável, quórum ou IAM.
2. SHA-256 comprova somente igualdade de bytes contra uma âncora que
   seja **realmente confiável**. Não impede adulteração se um invasor ou
   administrador puder reescrever conjuntamente Z4 e testemunha.
3. O CAS testado é **local a uma instância JavaScript**. Não há proof de
   linearização entre Durable Objects, regiões ou writer/DB commit real.
4. Os tokens `NON_OPERATIONAL_*` são **strings públicas de teste**,
   não MFA, autorização de Diretor, validação jurídica ou login.
5. A testemunha **não pode estar sob os mesmos administradores, chaves e
   estratégia de backup que a Z4** se a ameaça inclui restauração ou
   comprometimento total dessa conta. Apenas separar Durable Object e
   bucket R2 dentro da mesma conta não resolve automaticamente a ameaça.
6. Toda indisponibilidade ou divergência deve implicar **DENY** de
   leitura/escrita real. A disponibilidade, falsos bloqueios, incidentes,
   recuperação da própria testemunha e custódia ainda não têm desenho
   homologado.
7. Antes de qualquer recuperação/OPEN de produção, é necessário um
   protocolo de aprovação auditável por pessoa autorizada, com independência
   de funções e prova de todos os escritores; os testes locais não cobrem.
8. Não migrar o código deste modelo diretamente para API/SQL ou Worker
   operacional. Ele existe para **refutar garantias excessivas** e orientar
   futuras escolhas de infraestrutura.

## Matriz resumida de evidência

| Cenário | Ensaio C03 | Estado operacional |
| --- | --- | --- |
| Z4 antiga / testemunha atual | Negação por divergência | NOT RUN |
| Z4 atual / testemunha antiga | Negação por divergência | NOT RUN |
| Falta de testemunha | DENY | NOT RUN |
| Duas propostas com mesma sequência | 1 CAS aceito no mesmo processo | NOT RUN |
| Z4 e testemunha antigas restauradas juntas | **KNOWN GAP reproduzido: passam a comparação** | BLOQUEANTE |
| Sessões/efeitos após desastre real | Não implementado | NOT RUN |
| Restore/rollback de DO e R2 reais | Não implementado | NOT RUN |
| Revogação concorrente com commit PG | Não implementado | NOT RUN |
| Independência de IAM/host/região/operador | Não verificada | BLOQUEANTE |

**Toda a matriz SEC-R03-X01–X24 continua NOT RUN para aceitação operacional.**

## Próxima fase sem autorização adicional de despesas

- Concluir inventário técnico de todos os readers/writers reais do Portal,
  e encaminhamentos ao Sites, CRM, Performance e n8n por domínio próprio.
- Preparar contrato de testemunha com API de leitura, CAS, log append-only,
  tolerância a falha, quotas, custo e segregação administrativa.
- Testar primeiramente no workerd/Miniflare local, sem credenciais ou deploy.
- Para ativação externa, executar a Issue #122 com conta Cloudflare de
  laboratório, **após verificação de zero cobrança e limites efetivos**;
  não presumir que ambiente Free elimina risco de fatura.
- Submeter a decisão final de topologia, operação, custódia e OPEN à
  Direção, junto dos gates AppSec/DBA. Nenhuma dessas aprovações é
  concedida por esta documentação.

**Proibições mantidas:** não alterar `main`, `dev`, VPS, DNS, recursos
Cloudflare reais, R2, credenciais, cobrança, dados pessoais, grants, rotas,
clientes ou produção. Este documento e testes devem ficar em PR DRAFT.
