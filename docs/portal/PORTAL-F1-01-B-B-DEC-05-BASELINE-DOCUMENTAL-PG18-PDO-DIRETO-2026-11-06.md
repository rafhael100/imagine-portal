# PORTAL-F1-01-B-B-DEC-05 — Baseline documental do primeiro laboratório PostgreSQL

**Data:** 06/11/2026 (America/Sao_Paulo)  
**Manifestação do responsável:** “ok”, em resposta à proposta expressa: “Você aprova PostgreSQL 18 e conexão PDO direta como baseline documental do primeiro laboratório, deixando o PgBouncer para uma fase posterior, sem autorizar ainda SQL, migrations, execução de testes ou merge?”  
**Estado:** **APROVADO EXCLUSIVAMENTE COMO BASELINE DOCUMENTAL PRELIMINAR**. Não é homologação técnica, autorização de laboratório, programação, testes funcionais ou merge.

## 1. Objeto exato da decisão

| Dimensão | Baseline documental ratificada | Limitação / pendência |
|---|---|---|
| Banco | **PostgreSQL 18 (major)** para futuro laboratório isolado e descartável; PostgreSQL 17 deixa de ser a escolha padrão, mas permanece alternativa técnica em caso de impedimento fundamentado | Minor corrigida, imagem/OS/arquitetura/digest, disponibilidade, manutenção e parecer Plataforma/DBA ainda não aprovados |
| Backend | **Laravel 13** com dependências existentes e revisão de compatibilidade pelo lock vigente | Não autoriza atualizar dependências ou editar aplicação |
| PHP | **PHP 8.3 e 8.4** em perfis de validação separados, quando futuramente autorizados | Patches, `pdo_pgsql` e `libpq` efetivos devem ser fixados/verificados; CI sem PG não prova compatibilidade |
| Conexões | **PDO/pdo_pgsql direto ao PostgreSQL, não persistente**, com transação explícita e contexto por unidade transacional | Autenticidade do ator, RLS, grants, locks, rollback, timeout, idempotência e cancelamento seguem sem homologação |
| Pooling | **PgBouncer fora do primeiro laboratório**; perfis E83/E84/N84 e qualquer transaction pooling ficam diferidos para fase posterior, sujeita a autorização própria | Não declarar G-BB06 completo/homologado, nem extrapolar resultados diretos para pooling; controles e riscos pooled ficam registrados |
| Dados | **Fixtures inteiramente fictícias** e sem credenciais ou conectores CRM, dados de clientes, sessões reais ou produção | Ainda não autoriza criar ambiente, fixtures, banco ou qualquer arquivo executável |
| Ambiente | Isolado e descartável como diretriz; orçamento e destruição antes de autorização posterior | Não contratar, instalar, provisionar nem usar VPS nesta decisão |

O laboratório futuro, **somente após nova ordem expressa**, deve prever ao menos as células de conexão direta D83 e D84 com o mesmo PostgreSQL 18 revisado. A matriz dos **34 cenários H01–H12, C01–C10 e P01–P12** permanece válida e **não executada**. Ensaios especificamente dependentes de PgBouncer devem ser explicitamente marcados **ADIADOS / NÃO TESTADOS**, nunca como aprovados; a homologação de qualquer ambiente pooled exige nova bateria específica.

## 2. Precedência e controles inalterados

Este documento complementa a [D06](PORTAL-F1-01-B-B-D06-DETALHAMENTO-GATES-TECNICOS-2026-11-06.md) e a [RV02](PORTAL-F1-01-B-B-D06-RV02-PARECER-CONSOLIDADO-CONDICOES-LABORATORIO-2026-11-06.md); **prevalece somente na escolha documental do major PG18 e no adiamento expresso de PgBouncer**. Permanecem válidas as aprovações DEC-01–DEC-04 **somente como diretrizes documentais preliminares**, documentadas na branch do [PR #19](https://github.com/rafhael100/imagine-portal/pull/19).

**ABERTOS / NÃO HOMOLOGADOS:** G-BB01–G-BB08, D02-SEC01..06, D03 físico/RLS, P0-DOC01..14, P0-DOC06/G-04 e homologação H/C/P. O controle de acesso real do Portal e seus contratos SEC não estão autorizados nem substituídos por uma fixture. A ausência de PgBouncer no primeiro laboratório não elimina a necessidade de validar contexto e troca de empresa em PDO direto.

**NÃO AUTORIZADO:** escrever/rodar SQL, criar migration/tabela/fixture executável, instalar PostgreSQL/PgBouncer, conectar banco, executar CI/testes de PG/RLS, código Laravel funcional de persistência, login/MFA, integração CRM, acesso a segredos ou dados reais, modificar a `main`, integrar PRs, alterar VPS ou realizar deploy.

O [PR #25](https://github.com/rafhael100/imagine-portal/pull/25) permanece em rascunho e sem merge. O [PR #19](https://github.com/rafhael100/imagine-portal/pull/19) permanece distinto, sem merge. Nenhum gate de segurança pode ser encerrado por esta decisão.

## 3. Pendências concretas antes de pedir laboratório

1. **Arquitetura/Plataforma/DBA:** fixar minor PG18, imagens/OS/digests e versões PHP, `pdo_pgsql`, `libpq`; validar disponibilidade e custo máximo/quotas do ambiente descartável; elaborar plano de limpeza/destruição e rastreabilidade.
2. **Identidade + Segurança:** revisar emissor Portal/fixture local, bootstrap, limites de confiança do runtime e separação Portal–CRM; impedir que a identidade simulada se torne autenticador real.
3. **DBA + Segurança independente:** revisar grants/RLS/evaluador privado, grafo antirrecursão, privilégios da operação de lock e ausência de bypass/oracle.
4. **Dados/Backend/Produto:** revisar ERD/FKs, catálogo de poderes, último gestor, barreira transacional e commit inconclusivo/replay.
5. **Privacidade + QA:** aprovar finalidade/guarda/descarte de provas *sintéticas* e plano reproduzível de casos negativos e positivos sob conexão direta, sem afirmar homologação do perfil pooled.
6. **Responsável:** somente após esses pareceres, avaliar ordem separada com **SHA base, branch filha isolada, operações permitidas, limites de ambiente/custo, critérios de parada, evidências e exclusões**. O pedido de laboratório ainda não é autorização para executá-lo.

**Próximo trabalho permitido pelo escopo anterior:** revisão e planejamento documental dos pontos acima, sem programação e sem aprovar automaticamente os pareceres que ainda não foram emitidos.

**Situação final da F1-01-B-B funcional:** NÃO AUTORIZADA.
