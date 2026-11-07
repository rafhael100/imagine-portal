# SEC-P02-R04-DEC-01 — Cinco decisões técnicas para avaliação da Direção

**Data:** 06/11/2026. **Status:** PROPOSTAS DOCUMENTAIS PARA APROVAÇÃO; NÃO RATIFICADAS NESTA ETAPA.
**Origem da autorização:** a Direção disse "ok vamos" à recomendação de detalhar a ordem de locks, baseline PostgreSQL/driver, privilégios, risco de SQL falsificado e último gestor, sem código ou merge. A escolha anterior D01-B (hierarquia) e D02-A reforçada (Laravel confiável + RLS) é diretriz aprovada; as alternativas concretas R04-D01..D05 aqui não são decisões tomadas por silêncio.
**Repositório:** rafhael100/imagine-portal, PR #33 na branch documental; preservar PRs #19/#24.

## R04-D01 — Ordem exata de autoridades e locks

**A (recomendada para especificação e testes futuros):** ordem lógica estável P (política/catálogo global) → A (conta e fator) → S (sessão individual) → O (organização, política e entitlement) → M (membership, concedente e destinatário) → R (recurso/efeito). Para cada nível, adquirir somente domínios necessários, sempre ordenando IDs de um mesmo nível de modo determinístico. Leitura autorizadora usa barreira compatível com leitura, e mudança de estado/grant usa barreira incompatível com decisões afetadas; modos físicos e upgrade de locks exigem DBA. Um catálogo global pode exigir coordenação excepcional em P, mas operações usuais de empresas distintas NÃO usam a antiga barreira exclusiva G global por requisição.

**B (alternativa):** precedência O→A para otimizar contexto empresarial, com protocolo distinto para revogação de contas pertencentes a múltiplas empresas. Risco: inversão de locks e TOCTOU se faltar coordenação entre O e A.

**Recomendação:** A, sujeita a grafo fechado de todos os writers (incluindo job/CLI/suporte), modo/ordem físico, snapshot, revalidação ao adquirir a autoridade e testes de deadlock, expiração e p95/p99. Nenhum lock de PostgreSQL foi executado. Não confundir versão/epoch com barreira transacional. Garantia externa depende de fencing/idempotência no receptor; bytes enviados não se recuperam.

## R04-D02 — PostgreSQL, PHP, driver e pool

**A (recomendada):** PostgreSQL **18**, sempre no minor suportado e corrigido quando for efetivamente instalado; PHP **8.4** + Laravel **13** + extensão **PDO_PGSQL**/libpq. Para a primeira validação de autoridade, conexão transacional direta ao primário, **sem pool externo** e sem leitura decisória em réplica até haver testes de contexto, rollback, savepoint, prepared statements, retry e afinidade de conexão. Nenhum patch minor exato ou imagem/container é fixado por este documento.

**B (alternativa):** PostgreSQL **17** ainda suportado, PHP compatível e o mesmo driver, caso haja restrição concreta de imagens/operador que não permita PG18; requer comparar ciclo de suporte e recursos físicos.

**Recomendação:** A. Em 06/11/2026, a política PostgreSQL aponta 18 como versão estável suportada até 14/11/2030, 17 até 08/11/2029, e PG19 RC1 anunciado em 15/10/2026, ainda não baseline estável. Laravel 13 suporta PostgreSQL e PHP 8.3–8.5; o composer do Portal declara PHP ^8.3 e laravel/framework ^13.0. PDO_PGSQL usa libpq. O .env.example atual só oferece placeholders DB e SESSION_DRIVER=array, **não um banco ou sessão real**. Não instalar dependências nem consultar VPS nesta etapa.

**Fontes oficiais:** https://www.postgresql.org/support/versioning/ ; https://laravel.com/framework/docs/database ; https://laravel.com/docs/13.x/releases ; https://www.php.net/manual/en/ref.pdo-pgsql.php .

## R04-D03 — Modelo de privilégio e RLS

**A (recomendada):** separar papéis operacionais runtime_web, runtime_job, owner/policy manager, migrator e audit/recovery. Runtime sem superuser, BYPASSRLS, schema owner, DDL/TRUNCATE, SET ROLE para privilegiado, acesso a segredos ou função genérica elevada. RLS explícita em tabelas tenant para SELECT, INSERT, UPDATE e DELETE; chave/FK composta (organization_id, recurso) e tenant imutável. Revisar políticas permissivas OR, views, triggers, SECURITY DEFINER, EXECUTE, search_path, role inheritance e erros de unicidade/FK que enumeram terceiros. Entrypoints de operação privilegiada, se existirem, precisam nomes/escopos mínimos e revisão de autorização, sem SQL dinâmico ou impersonation.

**B (alternativa, não recomendada):** um único papel SQL para requests, jobs e migrations com RLS variável. Facilita operação, mas perde segregação, amplifica credencial comprometida e pode contornar controls.

**Recomendação:** A como desenho a detalhar; sem GRANT, DDL ou policy operacional nesta etapa. PostgreSQL FORCE RLS não contém superuser ou BYPASSRLS e RLS não dispensa os privilégios SQL ou autorização do Laravel.

## R04-D04 — Contexto forjado / ameaça adversarial

**A (recomendada como modelo de discussão, NÃO como aceitação do risco):** fronteira de confiança de aplicação Laravel e sessão Portal confiáveis, com parametrização e revisão de SQL injection, fonte autoritativa de ator/tenant, RLS como segunda defesa contra esquecimento de filtro/tenant manipulado por request e roles mínimos. **Limite explícito:** se o runtime executar SQL arbitrário capaz de definir completamente o contexto que a policy confia, RLS pode permitir impersonação; não alegar que SET LOCAL comprova identidade. A escolha não aceita automaticamente o risco residual.

**B (alternativa de segurança mais forte):** exigir resistência a runtime hostil/SQL arbitrário que forja ator; requer autoridade de validação fora do alcance do runtime ou isolamento de segredo/principal e protocolo no ponto de efeito. O desenho B ainda não foi especificado e pode reabrir D02-A.

**Recomendação:** A somente para definir escopo de threat model e mitigação; riscos residuais precisam deliberação explícita da Direção com evidência AppSec e DBA, nunca aceitação por silêncio. Se o objetivo exigir B, não iniciar fluxo real até redesenho.

## R04-D05 — Último gestor e suspensão de segurança

**A (recomendada):** impedir a remoção ordinária/rebaixamento do **último gestor efetivamente elegível** com verificação transacional no escopo da organização e vínculos, evitando duas remoções simultâneas (write skew). **Não impedir suspensão de conta por fraude, comprometimento de credenciais ou risco de segurança.** Se a suspensão eliminar o último gestor, a empresa entra em estado restrito de recuperação, sem staff universal, concessão automática ou acesso por domínio de e-mail/CNPJ/pagador. Restauração de controle exige prova de mandato de alta garantia, autorização com alçadas definidas e trilha, não reingresso de grants antigos.

**B (alternativa, não recomendada):** bloquear até a suspensão de segurança do último gestor até que haja substituto; isso preserva continuidade administrativa às custas de manter credencial potencialmente comprometida.

**Recomendação:** A. A prova legal de quem tem autoridade para recuperar, dupla revisão, política de privacidade, prazos e caminhos operacionais precisam decisão adicional antes da implementação. Sem identidade de suporte com superpoderes implícitos.

## Resumo executivo para deliberação separada

| ID | Recomendação | Status |
| --- | --- | --- |
| R04-D01 | A — P→A→S→O→M→R com barreiras hierárquicas | PROPOSTA |
| R04-D02 | A — PG18 + PHP8.4 + Laravel13 + PDO_PGSQL; sem pool externo inicial | PROPOSTA |
| R04-D03 | A — roles segregadas, privilégios mínimos e RLS tenant | PROPOSTA |
| R04-D04 | A — fronteira de aplicativo confiável; risco de contexto SQL forjável **não aceito** | PROPOSTA |
| R04-D05 | A — último gestor protegido em revogação ordinária; suspensão de segurança e recuperação restrita | PROPOSTA |

A aprovação R04 exigirá mensagem explícita da Direção. A ratificação será documental e NÃO homologa código, segurança, riscos residuais, gates ou testes. A hierarquia final, versão física, algoritmo de recuperação e limites de SQL hostil exigem validação antes do uso real.

**Todos P0 R01-01/02/03/09/10, G-ID01–09, G-BB01–08, D02-SEC01–06, D03/RLS e gates de release ABERTOS.** Sem PHP/JS, SQL/DDL/migration, login/MFA, laboratório, banco, VPS, despesas, merge de PR #19/#24/#33, main/dev ou deploy.
