# PORTAL-F1-01-B-B-D07 — Consolidação de pareceres solicitados e pré-requisitos de laboratório PG18

**Data:** 06/11/2026 (America/Sao_Paulo)  
**Estado:** **CONSOLIDAÇÃO DOCUMENTAL — NÃO HOMOLOGADA — PARECERES INDEPENDENTES PENDENTES**.  
**Autorização:** “ok pode prosseguir” à recomendação de **consolidar os pareceres técnicos de identidade, segurança RLS, revogação e custos do laboratório**, após a DEC-05. Não autoriza implementação ou execução de laboratório.  
**Rastreamento:** [Issue #26](https://github.com/rafhael100/imagine-portal/issues/26); [Issue #17](https://github.com/rafhael100/imagine-portal/issues/17); [PR #25](https://github.com/rafhael100/imagine-portal/pull/25), [PR #19](https://github.com/rafhael100/imagine-portal/pull/19), [PR #24](https://github.com/rafhael100/imagine-portal/pull/24).

> **Atualização de precedência por trabalho paralelo:** depois da primeira redação deste documento, o PR #24 avançou para `2e3d2051a9491ac02863b9acecff1e88d89c24cc` com a **SEC-P02-DEC-04** e os documentos E01/E02. SEC06-D01..04 estão agora ratificados **só como diretrizes documentais preliminares**. Nenhum parecer independente, gate de identidade ou laboratório foi homologado por esse avanço.

> **Atualização DEC-06 (06/11/2026):** o responsável aprovou documentalmente ambiente GitHub Actions temporário sujeito à quota, até 30 minutos por perfil PHP e guarda de provas sintéticas por 30 dias com descarte de volumes ao encerrar. Admitiu custo baixo e controlado, sem definir teto numérico. Ver [DEC-06](PORTAL-F1-01-B-B-DEC-06-CONDICOES-LABORATORIO-CUSTO-CONTROLADO-2026-11-06.md). As pendências de ambiente/pareceres abaixo devem ser lidas com esse aceite parcial: disponibilidade e quota ainda não comprovadas, teto agregado e execução ainda não autorizados. Nenhum gate homologado.

## 1. Fontes, precedência e limites

| Fonte | Escopo e estado consultado | O que não se pode inferir |
|---|---|---|
| PR #19, HEAD `75b32cef4afa0e7eae83e3b30fdc13c571a767d4` | F1-01-B-B D01–D05 e DEC-01..04; alternativas A ratificadas **somente documentalmente** | Não existe modelo PostgreSQL implantado nem fonte real de identidade por esse aceite |
| PR #25, anterior à D07 `66056188fd0d06b1b8c830ec2743c91912364aaa` | D06, RV02 e [DEC-05](PORTAL-F1-01-B-B-DEC-05-BASELINE-DOCUMENTAL-PG18-PDO-DIRETO-2026-11-06.md) | CI Node não prova RLS; autorização documental PG18 não executa laboratório |
| PR #24, HEAD `2e3d2051a9491ac02863b9acecff1e88d89c24cc` | SEC-P02 D01–D06 e E01/E02; SEC02/IDN/REC/SEC05/**SEC06** ratificados somente como diretrizes preliminares (DEC-01..04); G-ID01..09 abertos, E01/E02 não homologados | **SEC06-D01..04 já estão ratificados exclusivamente como diretrizes documentais preliminares pela SEC-P02-DEC-04**, sem fechar gates ou autorizar código; E01/E02 são propostas técnicas não homologadas e fixture SEC-P01 não autentica cliente |
| Branch de desenvolvimento `dev/fast-track-portal-2026-11-05` em `0aef6838b0452fcbb195fcad2e50aed748ec41c5` | Referência técnica para PR #25 e #24 na consulta | Documentos dos PRs abertos não estão integrados automaticamente na branch dev |
| `main` em `50b683a499e674641df5749051f3d05f7ac3f627` | Preservada na conferência | Nenhuma autorização para mudar `main` ou VPS |

**Situação das revisões:** PRs #19, #24 e #25 permanecem em rascunho e **não têm revisões formais independentes registradas** na consulta. Este D07 **não substitui o parecer de uma pessoa de Segurança, DBA, Identidade, Privacidade ou Plataforma**. Todo sign-off abaixo deve ser expresso por revisor nomeado, com data, SHA, escopo, ressalvas e decisão; por ora **todos estão PENDENTES**.

## 2. Baseline PG18 documental já aprovada, sem mudanças

| Parâmetro | Decisão vigente de DEC-05 | Ainda falta antes de solicitar execução |
|---|---|---|
| Banco | PostgreSQL **18**, no futuro laboratório **isolado e descartável** | Minor corrigida; OS/arquitetura; imagem/digest e ciclo de patch; capacidade real do executor |
| Aplicação | Laravel **13**, lock vigente | Revisão dos binários/dependências que efetivamente serão executados |
| PHP | **8.3 e 8.4**, em células **separadas** | Patches, ABI, versão `pdo_pgsql`/libpq e artefatos fixados |
| Conexão | **PDO direta não persistente**, transação explícita em primário | Demonstrar contexto por transação, segurança de grants, rollback, timeout e troca X→Y, com futuros testes reais |
| Pooling | **PgBouncer excluído do primeiro laboratório** | E83/E84/N84 e negativas específicas pooled = **adiados/não executados**, nunca como homologados |
| Dados | **Somente fixtures fictícias**, sem PII, CRM ou credenciais reais | Política de descarte da evidência, identificadores artificiais e plano de isolamento |
| Infra/custos | Ambiente futuro descartável sem VPS/produção | **Executor/local, quota, custo máximo, teto de armazenamento e destruição ainda não definidos/aceitos.** Não presumir custo zero |

Nenhum executor, container, script, banco, role, conexão, pipeline de PG, migration, SQL, fixture ou configuração foi criado por este D07.

## 3. Parecer técnico dirigido P01 — Identidade, autorizações e fronteiras SEC/BB

**Responsáveis a nomear:** Identidade/Backend e revisor independente de Segurança.  
**Contratos a confrontar:** SEC-P02-D03/D05/D06 (PR #24), G-ID01/05/07 e G-BB03/04 (PR #25).

- Emissor humano **exclusivamente Portal**, estado, revisão e sessão verificada precisam ser autoridade oficial **para uso real**; não aceitar ID CRM/e-mail/CNPJ, headers ou payloads como fonte de ator.
- **Para um laboratório futuro sem login real:** um adaptador de autoridade *sintético* somente injetado pelo harness de teste, inacessível por rota/UI, deve simular emissor, estado e revisão, com casos negativos de ID coincidente CRM. A simulação **não aprova** autenticação humana/MFA e não deve migrar para ambiente operacional.
- A organização selecionada é intenção; o backend resolve vínculo canônico, papéis, grants e recurso na fonte autorizada. Bootstrap só permite referências mínimas das empresas pertencentes ao ator Portal comprovado.
- O contexto de banco não autentica identidade; runtime comprometido com SQL arbitrário pode falsificar valores locais. Aceitar explicitamente limite do modelo de ameaça para **laboratório controlado**, sem afirmar proteção de produção.
- O PR #24 recebeu a **SEC-P02-DEC-04, que ratifica SEC06-D01..04 apenas documentalmente**; as entregas E01/E02 consolidam evidências/contratos candidatos sem homologar identidade, autenticação ou banco. A DEC-05 (PostgreSQL) não equivale a homologação dos contratos SEC.

**Saída de parecer exigida:** matriz `campo → emissor confiável → verificação/revisão → estado negado → prova`, decisão sobre fixture restrita e riscos residuais. **Status: SOLICITADO / NÃO EMITIDO.** Impedimento: origem/tenant arbitrários ou fixture acessível como autenticador real.

## 4. Parecer dirigido P02 — DBA/Security, RLS e privilégios

**Responsáveis a nomear:** DBA responsável pelo projeto e **revisor independente de Segurança**.  
**Contratos a confrontar:** G-BB02/05/06, D06 RV02-03, SEC-P02 G-ID06.

- Inventariar schemas/entidades privadas e globais, FKs compostas, roles do runtime/migrador/owner e grants com proibição de owner, BYPASSRLS, DDL, TRUNCATE e assunção de role privilegiada no runtime.
- RLS não substitui proveniência da identidade, alçada nem FK; compor barreira restritiva de tenant e permissões explícitas em SELECT/INSERT/UPDATE/DELETE. Garantir verificação da linha antiga e nova e imutabilidade de `organization_id`.
- Avaliador interno mínimo booleano **sem oracle/consulta arbitrária**; proprietário sem login, grants estreitos, `search_path` protegido, dependências sem recursão; revisitar função privada de locks para que não seja bypass disfarçado ou conceda UPDATE amplo ao runtime.
- Inspecionar views, triggers, funções privilegiadas, partições, exportação, auditoria e caminhos indiretos; reconhecer que contexto SQL não resiste sozinho à credencial runtime comprometida.
- Uma recomendação escrita pelo autor não representa parecer independente nem prova de isolamento executada.

**Saída exigida:** inventário de roles/policies/paths e grafo de dependências revisável, parecer de privilégio, condições de negação futura sob runtime. **Status: SOLICITADO / NÃO EMITIDO.** Impedimento: bypass/oracle/recursão ou ausência de evidência de autoridade.

## 5. Parecer dirigido P03 — Revogação, concorrência, último gestor e idempotência

**Responsáveis a nomear:** Dados/Backend e Produto; revisões DBA/Segurança.  
**Contratos a confrontar:** G-BB04/06, RV02-04, SEC-P02 G-ID05 e contratos de revogação de sessões (distintos dos de membership).

- Revisar ordem global de locks: usuários por UUID → organizações → memberships → papéis/grants → marcador idempotente → recursos; todos os canais de grant/revogação devem respeitar a mesma ordenação.
- Uma ação sensível e uma revogação devem competir por barreira comum; **reler após obtenção do lock em nova instrução**, não usar snapshot de decisão iniciado antes da espera. A revisão do usuário global também precisa bloquear ações com tenant diferente.
- Ponderar gargalo de lock da organização versus prova simples inicial; timeouts e até 3 tentativas são **hipóteses de laboratório**, não SLO operacional.
- Commit inconclusivo não implica rollback: marcador, unicidade, verificação no primário e repetição sob barreira, sem repetir efeito antigo; se incerto, negar/escalar.
- Capacidade do último gestor não se deduz do nome `owner`. Reingresso revogado, recuperação de gestor, delegação e efeitos externos são **exclusões** do primeiro laboratório e continuam bloqueados.
- A revogação de membership/role não equivale a invalidar sessão/MFA automaticamente sem contrato próprio e causalidade definida em SEC.

**Saída exigida:** tabela de ordem e pontos de lock/snapshot, matriz de alçadas e exclusões, ensaios de corrida bidirecionais para H08/C04/P04 e hipóteses de falha. **Status: SOLICITADO / NÃO EMITIDO.** Impedimento: reuse de `allow` antigo, privilégio de lock ampliado ou commit desconhecido repetido cegamente.

## 6. Parecer dirigido P04 — Plataforma, custos e baseline executável futura

**Responsáveis a nomear:** Arquitetura/Plataforma, DBA e responsável por custos.  
**Situação atual:** a decisão documental **PG18 + PDO direto D83/D84** é suficiente para delimitar a *proposta*; **não define uma infraestrutura autorizada**.

A proposta futura de ambiente precisa preencher, com fatos verificáveis e sem credenciais em documento público:
1. Executor localizado e autorizado (por exemplo CI temporário de repositório privado **apenas se** comprovadas quota, possibilidade de serviço isolado, retenção e limites), nunca VPS real sem aprovação distinta.
2. PostgreSQL 18: minor atual suportada, distribuição, arquitetura e **digest da imagem comprovado na data de execução**. Não inventar sha/digest.
3. PHP 8.3/8.4: patch exato, `pdo_pgsql`, libpq de build/runtime, versão composer.lock e compatibilidade PDO direta.
4. Rede isolada, sem portas públicas, contas efêmeras, papel runtime mínimo, ausência de segredos do CRM, volumes temporários.
5. **Teto de custo e quota concretos**, política de execução interrompida por teto, descarte de volumes e artefatos, responsável por manutenção. A afirmação “sem custo” exige evidência de quota efetiva e limites do plano.
6. Manifesto de testes por perfil D83/D84, sem PgBouncer. A falta de testes pooled permanece anotada.

**Saída exigida:** ADR de ambiente e orçamento, manifesto de versões/digests planejados ou protocolo de fixação imediatamente antes da autorização, plano de destruição. **Status: SOLICITADO / NÃO EMITIDO.** Impedimento: executor/custo/quota não conhecidos ou uso de ambiente/segredo real.

## 7. Parecer dirigido P05 — Privacidade, QA e cobertura de evidências

**Responsáveis a nomear:** Privacidade/Encarregado, QA e Segurança independente.  
**Diretriz:** dados 100% fictícios; nenhuma licença para armazenar PII real no laboratório. Prazos de 14/30 dias em RV02 são *sugestões técnicas*, sem homologação LGPD; histórico de identidade, backups e retenção real continuam sem política aprovada.

- QA rastreia os **34 cenários H01..12, C01..10, P01..12** do D06 com critérios de ausência de efeito, vazamento e controles positivos para não aceitar um sistema que nega todas as operações.
- **Perfis diretos D83/D84:** planejar todos os testes aplicáveis de origem, escopo, FK tenant, políticas RLS, revisão de grants, concorrência e reuso de conexão. Casos estritamente dependentes de **PgBouncer = ADIADOS / NÃO EXECUTADOS**; nenhuma aprovação de pooling por extrapolação.
- Evidências E1–E4 futuras sem segredos nem linhas privadas completas: manifesto de versões/roles, testes de efeito, ordem concorrente e evidências sanitizadas de restauração.
- Critérios de parada explícitos: origem arbitrária, bypass RLS/DDL, vazamento X→Y, grants antigos após revogação, commit inconclusivo sem reconciliação, logs com PII/segredo, resultados inconclusivos, ambiente/quota excedidos.
- Uma futura autorização de laboratório deve aprovar guarda curta de provas sintéticas, responsável, descarte, exceções de ensaios e revisores. Restauração de **produção** tem gate próprio.

**Saída exigida:** plano de testes por perfil, guarda das provas, checklist de limpeza e aprovação de privacidade para o laboratório **antes da execução**. **Status: SOLICITADO / NÃO EMITIDO.**

## 8. Quadro executivo e decisão de prontidão

| Item | Direção documental aprovada | Parecer independente / aceite | Execução | Estado |
|---|---|---|---|---|
| PG18, Laravel13, PHP8.3/8.4, PDO direto | DEC-05 | Manifesto/ambiente/custo pendentes | Nenhuma | **PLANEJADO, NÃO HOMOLOGADO** |
| Fonte de identidade Portal / fixture restrita | DEC-01..04 e SEC diretrizes, cada uma em seu escopo | Identidade + Segurança pendentes | Nenhuma | **BLOQUEADO PARA USO REAL** |
| Modelo UUID/FKs + roles/RLS | Diretriz BB01/BB05 e contratos candidatos | DBA + Segurança independentes pendentes | Nenhuma | **GATES ABERTOS** |
| Revogação/alçadas/último gestor | Diretrizes BB04/BBD02 e RV02 candidata | Dados + Backend + Produto + Segurança pendentes | Nenhuma | **GATES ABERTOS** |
| Retenção, QA e custos | Diretrizes de minimização e laboratório hipotético | Privacidade + QA + Plataforma pendentes | Nenhuma | **GATES ABERTOS** |
| PgBouncer/transaction pooling | **Adiamento documental expresso** | Nova decisão e ensaios em etapa posterior | Nenhuma | **EXCLUÍDO DO PRIMEIRO LABORATÓRIO** |
| H/C/P (34 cenários) | Planejados no D06 | Evidências e execução futuras | **0 de 34** | **NÃO EXECUTADOS** |
| Código, migrations, SQL e laboratório | **NÃO AUTORIZADOS** | Ordem futura separada | Nenhuma | **BLOQUEADO** |
| Merge PRs, `main`, VPS, produção | **NÃO AUTORIZADOS** | Gates independentes posteriores | Nenhuma | **BLOQUEADO** |

**Resultado desta consolidação:** **NO-GO para laboratório/código neste momento**, por ausência de pareceres independentes, ambiente/custo/quotas aceitos e autorização expressa de código/testes. Isto **não** reprova o desenho nem cancela a possibilidade do laboratório: é uma classificação de prontidão, sujeita à revisão quando as evidências surgirem.

## 9. Solicitação dirigida dos pareceres e próximo artefato necessário

Usar [Issue #26](https://github.com/rafhael100/imagine-portal/issues/26) como fila única de P01–P06, sem duplicar aprovações genéricas. Para cada parecer, registrar **pessoa, função, data, documento/commit SHA, âmbito de revisão, alternativa recomendada, ressalvas, testes necessários, decisão (ACEITO COM RESSALVAS / REVISAR / REJEITADO)** e eventual prova; não emitir aceite em nome do usuário ou de especialista ausente.

**Depois de obter pareceres reais:** consolidar um pacote final com SHA atualizado, versão fixada, ambiente/quotas/custo e descarte, contratos aprovados, 34 testes mapeados com exclusões de PgBouncer, pessoas responsáveis, critérios de parada e lista de arquivos/operações permitidas. **Somente esse pacote permitirá solicitar nova ordem expressa** para implementação sintética em branch isolada. A ordem de laboratório, homologação de resultado, merge em dev, `main` e deploy são atos independentes.

## 10. Restrições desta entrega

**NÃO AUTORIZADO NESTA D07:** SQL executável, migrations, código PHP funcional, tabelas, banco, driver/pool em execução, automações executoras, login/MFA, dados reais, tokens/segredos, novo servidor ou serviço pago, alteração `dev`/`main`, merge, VPS ou deploy. PRs #19, #24 e #25 seguem rascunhos independentes; Issue #17 e todos G-BB/G-ID/D02-SEC/D03 físico/P0/H/C/P permanecem abertos.
