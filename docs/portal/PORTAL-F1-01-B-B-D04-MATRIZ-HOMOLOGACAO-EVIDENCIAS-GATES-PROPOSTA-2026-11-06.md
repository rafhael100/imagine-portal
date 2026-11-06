# PORTAL-F1-01-B-B-D04 — Matriz de homologação, evidências e gates antes da programação

**Data:** 06/11/2026 · **Estado:** PROPOSTA DOCUMENTAL AINDA NÃO APROVADA.  
**Precedência:** [D01](PORTAL-F1-01-B-B-D01-MODELO-FISICO-CANDIDATO-ISOLAMENTO-2026-11-06.md), [D02](PORTAL-F1-01-B-B-D02-CONTRATOS-PERMISSOES-REVOGACAO-RLS-PROPOSTA-2026-11-06.md), [D03](PORTAL-F1-01-B-B-D03-CONTRATOS-FISICOS-IDENTIDADE-RLS-REVOGACAO-PROPOSTA-2026-11-06.md) e ratificações documentais [DEC-01](PORTAL-F1-01-B-B-DEC-01-RATIFICACAO-DIRETRIZES-BB01-BB08-2026-11-06.md), [DEC-02](PORTAL-F1-01-B-B-DEC-02-RATIFICACAO-DIRETRIZES-BBD02-01-A-06-A-2026-11-06.md) e [DEC-03](PORTAL-F1-01-B-B-DEC-03-RATIFICACAO-BBD03-01-A-06-A-2026-11-06.md).  
**Regra de escopo:** definir a evidência necessária para futuras etapas. **Nenhuma execução de SQL, migração, RLS, testes PostgreSQL, login ou deploy está autorizada por D04**. Não realizar merge em `dev`, `main` ou qualquer branch.

## 1. Por que D04 existe

As alternativas A anteriores selecionaram orientações: esquema compartilhado com chaves compostas/RLS, vínculo canônico, papéis locais versionados, revogação transacional, função privada mínima candidata, transação com contexto local e laboratório fictício como primeiro teste. Ainda faltam **contratos exatos de autoridade e evidências verificáveis** para evitar que o sistema pareça seguro apenas porque os testes de objetos PHP passaram.

D04 separa quatro resultados distintos, sem fechamento por inferência:
1. **Diretriz documental:** decisão A/B/C ratificada, sem código.
2. **Contrato técnico aprovado:** invariantes, ameaça, responsabilidade, versão de tecnologia e critérios de teste documentados e revisados; não equivale a implementação.
3. **Recorte programável autorizado:** escopo limitado, branch isolada, ambiente de laboratório, proibições, testes negativos e condição de parada expressos pelo responsável.
4. **Homologação e promoção:** evidência de execução, revisão independente, autorização de merge em dev e, por último, gates próprios de `main` e produção.

Uma aprovação de D04 não autoriza os passos 3 ou 4.

## 2. Quem pode afirmar identidade, permissão e titularidade

| Informação | Autoridade futura exigida | Não aceitar como prova |
|---|---|---|
| Ator Portal | Identidade própria do Portal resolvida após autenticação verificada, sessão e revogação | E-mail, CNPJ, texto de ID CRM, payload cliente, header genérico |
| Empresa selecionada | Apenas intenção de contexto do ator, validada contra vínculos oficiais | Seletor no front-end, subdomínio, token externo sem contrato |
| Membership | Registro canônico oficial, ativo e vigente, com revisão do servidor | Instância PHP construída por cliente, cache stale, convite |
| Papéis/grants | Catálogo fechado, concessões locais versionadas e alçadas aprovadas | Nome do papel, configuração do cliente, grant direto concorrente |
| Dono do recurso | Chave composta de titularidade e integridade em fonte privada controlada | ID de recurso desacompanhado de `organization_id` confiável |
| Permissão para efeito sensível | Decisão atual sob barreira com revisão e trilha verificável | `allow` guardado em sessão ou job, consulta a réplica obsoleta |

A identidade e a autorização do Portal não se tornam equivalentes às credenciais e vínculos CRM. O contrato do bootstrap que permite a alguém descobrir apenas suas organizações é distinto do predicado privado RLS da organização escolhida. A análise de ameaça deverá incluir aplicação comprometida, injeção, pooling, roles, exportação, jobs e revogação concorrente. `SET LOCAL` é transporte de contexto, **não atestado criptográfico**.

## 3. Matriz de evidências exigidas antes de permitir código funcional

| Gate candidato | Evidência documental anterior à autorização de um laboratório | Responsáveis propostos | Estado |
|---|---|---|---|
| **G-BB01** — PostgreSQL | Versão principal com ciclo de suporte, matriz PHP 8.3/8.4 + extensão + driver + pooler; topologia descartável e custos zero/explicitados | Arquitetura + Plataforma | **PENDENTE** |
| **G-BB02** — modelo físico | Diagrama ER, UUID escolhido, inventário de tabelas privadas, FKs compostas, unicidade, vigência, índices, restauração/expansão reversível | Dados + Segurança | **PENDENTE** |
| **G-BB03** — autoridade | Emissor de identidade Portal e estados, fonte única de grants, bootstrap mínimo e modelo de recurso, sem legado/CRM | Identidade + Segurança + Produto | **PENDENTE** |
| **G-BB04** — alçadas e ciclo | Matriz de concessão, auditoria, reingresso e último gestor, revogação e tratamento de concorrência | Produto + Segurança | **PENDENTE** |
| **G-BB05** — RLS/privilégios | Inventário de roles e GRANTs, modelagem SELECT/INSERT/UPDATE/DELETE, desenho da função privada e alternativas sem recursão, revisão de `search_path` e de bypass | DBA + Segurança | **PENDENTE** |
| **G-BB06** — transações | Contrato de transação/pool, resíduo/rollback, timeout, retry e barreira de revogação, inclusa avaliação de réplicas e filas | Backend + Dados + Plataforma | **PENDENTE** |
| **G-BB07** — privacidade/auditoria | Finalidade, acesso, retenção por classe, eventos mínimos, segredos proibidos, tratamento de restore com revogações e evidência LGPD | Segurança + Privacidade | **PENDENTE** |
| **G-BB08** — qualidade | Mapeamento de H01–H12, C01–C10, P01–P12 a scripts de teste **futuros**, dados exclusivamente fictícios, donos, critérios de falha, CI e revisão | QA + Segurança + DBA | **PENDENTE** |

Responsáveis são papéis propostos; nomeação concreta e aprovação por alçada ainda necessárias. Não marcar gate como fechado a partir de mera existência da linha. As evidências não podem ser substituídas por CI Node nem pelos 21 testes PHP de B-A.

## 4. Negativas mínimas e evidências de execução futura (não executadas)

| Categoria | Casos obrigatórios | Evidência de homologação futura |
|---|---|---|
| Identidade e cross-tenant | Ator A, empresa X, recurso Y; ator CRM com mesmo texto; tentativas por e-mail e CNPJ; membership ausente | Respostas genéricas de negação e consultas SQL auditadas sem linha indevida |
| Vínculos e concessão | Invited, suspended, revoked, expiração no limite; papéis sem grant; autoelevação/terceiro; remoção do último gestor | Snapshots fictícios, revisões monotônicas, eventos mínimos |
| FK/tenancy | Tentativa de FK entre tenants, troca de `organization_id`, duplicidade de vínculo, index/constraint que revela outro tenant | Rejeição transacional, teste de concorrência, sem enumeração em erro externo |
| RLS e runtime | SELECT/INSERT/UPDATE/DELETE sem contexto e cruzados, função privilegiada, owner/BYPASSRLS, grants e policies permissivas OR | Evidência executada sob role real e inspeção de catálogo/permissões, com revisão independente |
| Pooling e revogação | Transação X seguida de Y em mesma conexão após commit, rollback, erro e timeout; acesso versus revogação nas duas ordens | Log de correlação sanitizado, bloqueio de resíduo, prova de barreira e repetição idempotente |
| Jobs, cache e exportação | Job enfileirado com grant antigo, cache obsoleto, exportação em lotes, réplica atrasada | Negação/revalidação, cancelamento por lote, sem novos efeitos após revogação |
| Auditoria, restore e abuso | Evento obrigatório falha, segredo em logs, restore ressuscita grant, alteração por staff sem mandato | Abortar operação e bloquear recuperação até reconciliação controlada |

Critério **fail closed**: se autenticador/autoridade, contexto, vínculo, grant, propriedade de recurso, auditoria obrigatória ou controle de revisão estiver ausente ou inconsistente, negar e não executar efeito. Um teste de negação precisa comprovar ausência de efeito **e** ausência de vazamento, não apenas retorno HTTP de erro.

## 5. Sequência recomendada de decisão

1. **Agora:** avaliar apenas BBD04-01 a BBD04-06 abaixo como orientações documentais.
2. **Depois:** detalhar a baseline PostgreSQL, os contratos de origem, a matriz de capacidades, o desenho físico e a estratégia RLS com revisão de Segurança/DBA (não executar).
3. **Gate documental específico:** apresentar para aprovação um pacote fechado com a menor implementação sintética possível, indicando quais gates impedem código até serem solucionados e quais ensaios podem ser produzidos em laboratório descartável, sem atalhos na `main`.
4. **Somente com autorização expressa futura:** criar branch filha de código, configurar laboratório isolado, produzir migrations e testes sintéticos exatamente no recorte liberado, sem usar credenciais/dados reais nem conectores de CRM.
5. **Após testes:** revisar logs, evidências e diff; eventual merge somente com autorização posterior distinta; produção exige gates próprios.

Não declarar "B-B pronta" antes das etapas 3 a 5. O primeiro protótipo sintético, se futuramente autorizado, ainda não é identidade nem RLS de produção.

## 6. Decisões BBD04 para avaliação do responsável (TODAS PENDENTES)

| Decisão | Alternativa A | Alternativa B | Alternativa C | Orientação técnica |
|---|---|---|---|---|
| **BBD04-01 — Governança por gates** | Autorização independente para diretriz, contrato, código de laboratório, homologação, merge e deploy | Uma aprovação autoriza planejamento e laboratório | Aprovação documental libera tudo | **A**, bloqueia escopo implícito |
| **BBD04-02 — Matriz de evidências** | Mapear G-BB01..08 e H/C/P a donos, provas e critérios negativos antes de iniciar código | Lista genérica de QA | Considerar CI PHP B-A como prova de RLS | **A**, rastreabilidade de cada ameaça |
| **BBD04-03 — Origem da autorização** | Fonte Portal autenticada + vínculos/grants/recursos oficiais; contexto e RLS como defesa adicional | Contexto SQL como prova suficiente | IDs CRM/e-mail/CNPJ como prova | **A**, sem SSO implícito |
| **BBD04-04 — Prioridade de segurança** | Resolver modelo de roles/RLS, revogação e pool com análise independente antes de usar dados privados | Corrigir riscos depois do MVP real | Confiar em filtro de frontend | **A**, não abrir acesso indevido |
| **BBD04-05 — Laboratório futuro** | Somente fixtures sintéticas, PG isolado/descartável e controle de custos com autorização própria antes de executar | Testes no DB existente sem dados reais | Testes diretamente em produção | **A**, não cria laboratório neste documento |
| **BBD04-06 — Critério de promoção** | CI negativo + revisão DBA/Segurança + aceite formal específico antes de merge; outro gate antes de `main`/VPS | Merge em dev após CI verde sem revisão | Deploy automático ao fechar issue | **A**, separa prova de autorização |

**Pergunta para deliberação:** aprovar ou revisar as alternativas A de BBD04-01..06 **somente como diretrizes documentais preliminares**, mantendo gates abertos, sem SQL, migrations, código, conexão PostgreSQL, merge, `main` ou deploy.

## 7. Estado consolidado

- **Aprovado documentalmente:** BB01–BB08 A (DEC-01), BBD02-01–06 A (DEC-02), BBD03-01–06 A (DEC-03).
- **Em avaliação:** BBD04-01–06; G-BB01–08 e evidências futuras.
- **Aberto:** D02-SEC01..06, D03 físico/RLS, P0-DOC01..14, P0-DOC06/G-04, H01–H12, C01–C10 e P01–P12.
- **Proibido nesta etapa:** SQL, migrations, tabelas, RLS real, PostgreSQL conectado, autenticação/login, mudança de `main`, merge de PRs, alterações VPS, deploy e execução de ensaios técnicos.
