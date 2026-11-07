# SEC-P02-R04-DEC-02 — Ratificação documental preliminar R04-D01-A a R04-D05-A

**Data:** 06/11/2026. **Origem:** Direção de Produto Imagine declarou expressamente "aprovado" em resposta ao pedido de aprovação das **alternativas A de R04-D01 a R04-D05, exclusivamente como diretrizes documentais preliminares**.
**Estado:** RATIFICADAS DOCUMENTALMENTE como orientação de detalhamento. **NÃO** implementadas, verificadas em laboratório ou homologadas; não autorizam merge, SQL, banco, autenticação real, infraestrutura ou liberação a clientes.

## 1. Decisões ratificadas — alcance preciso

| Identificador | Alternativa A ratificada como diretriz preliminar | O que NÃO foi homologado |
| --- | --- | --- |
| **R04-D01-A** | Ordem de autoridades e barreiras hierárquicas P (política global) → A (conta e fator) → S (sessão) → O (organização/política) → M (membership/concedente/alvo) → R (recurso/efeito); domínios e IDs adquiridos em ordem estável, sem uma G global **exclusiva** para todas as requisições | Algoritmo e modos físicos de lock, isolamento, snapshot, serialização efetiva, SLA, performance, ausência de deadlocks, garantias externas |
| **R04-D02-A** | Baseline de projeto: PostgreSQL série **18**, PHP **8.4**, Laravel **13**, PDO_PGSQL/libpq e transação no primário; sem pool externo inicial para o caminho de autoridade até provas específicas | Patch/binários instalados, configuração da VPS, TLS/pool, extensões, compatibilidade operacional, instalação ou custo |
| **R04-D03-A** | Separação de papéis SQL entre runtime web/job, owner, migrador, auditoria/manutenção; privilégio mínimo; RLS por operação em relações privadas; owner tenant imutável, chave/FK composta | GRANTs/policies reais, SECURITY DEFINER, roles criadas, DDL/migrations, prova de isolamento e auditoria DBA |
| **R04-D04-A** | Usar **aplicação Portal confiável + RLS** como fronteira de referência para threat modeling e mitigação de SQL injection. Contexto actor/tenant deriva da autoridade servidora; SET LOCAL não é prova criptográfica | **NÃO há aceitação gerencial do risco residual** de SQL arbitrário capaz de falsificar contexto e/ou runtime comprometido; proteção contra essa ameaça não demonstrada. Se a ameaça precisar ser contida, o projeto deve reavaliar arquitetura antes de ativar |
| **R04-D05-A** | Proteger último gestor elegível contra remoção ordinária/rebaixamento concorrente; nunca bloquear suspensão de conta por segurança; em perda do último gestor, empresa entra em recuperação restrita com alçada independente e trilha | Provas de mandato, critérios legais, alçadas/dupla revisão, recuperação real, permissões excepcionais, endpoints e fluxo operacional |

**Precedência:** esta ratificação detalha as diretrizes já escolhidas em SEC-P02-R02-DEC-01 (D01-B, revogação hierárquica; D02-A reforçada, aplicação confiável + RLS). Mantém SEC-P02-R03-HIER-01, R03-PG-01, R03-GATE-01, R04-DEC-01 e R04-VAL-01 como instrumentos de especificação e teste. A antiga preferência por uma única barreira G global exclusiva para toda requisição NÃO é arquitetura-alvo; permanece histórico de proposta/contraexemplo.

## 2. Riscos e pendências que NÃO se fecham com a ratificação

- **R01-01:** origem confiável de autenticação humana Portal, emissor de sessão, fator e independência CRM continuam sem implementação/prova.
- **R01-02 e R01-03:** revogação de conta/sessão/grants/política global permanece sem prova em concorrência e sem mapa físico fechado de writers, ordenação e locks.
- **R01-09:** isolamento multiempresa não foi demonstrado em PostgreSQL real; dicionário lógico e referências não são DDL.
- **R01-10:** roles/GRANTs/RLS reais ainda não existem; **contexto falsificável por SQL arbitrário continua risco explicitamente aberto e NÃO aceito**.
- Último gestor, Bootstrap/MFA/recuperação e bloqueio de acesso em falha de store/banco dependem de alçadas, threat model, tolerâncias e testes.
- P1/P2, outbox/CRM/Performance, restore, privacidade, retenção e evidência AppSec/DBA permanecem abertos.

**Gates mantidos ABERTOS:** D02-SEC01..06, G-ID01..09, G-BB01..08, D03 físico/RLS, H/C/P do PR #19, P0 e release. Nenhuma revisão humana independente foi concluída ou substituída pela assinatura gerencial.

## 3. Próxima etapa documental permitida apenas mediante nova autorização

Transformar R04-D01-A..D05-A em plano detalhado e critérios de prova por mecanismo; preparar pacotes para revisão AppSec/DBA, comparando alternativas sem alterar a diretriz aprovada. Para qualquer laboratório sintético, SQL, PHP real, teste PostgreSQL, nova dependência, merge de PRs ou deploy, é necessário **novo pedido específico e aceite explícito da Direção**. Se uma revisão questionar a viabilidade ou a fronteira de ameaça, apresentar divergência e risco; não reinterpretar silêncio como aceite.

**Restrição de GitHub:** registrar somente este documento e metadados de rastreabilidade na branch documental do PR #33. Não alterar código, arquivos históricos de ADRs, PR #19, PR #24, main, dev ou VPS. O PR #33 permanece **draft e sem merge**.

**Resultado da deliberação:** as cinco alternativas A **foram ratificadas como diretrizes técnicas documentais preliminares**, e nada além disso.
