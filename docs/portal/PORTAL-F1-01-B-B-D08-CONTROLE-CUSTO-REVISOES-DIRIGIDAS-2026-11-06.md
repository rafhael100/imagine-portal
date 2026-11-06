# F1-01-B-B-D08 — Controle do orçamento e revisões dirigidas

**Data:** 06/11/2026, America/Sao_Paulo. **Estado:** plano documental e análise assistida para revisão humana; laboratório não autorizado. Referências: PR #25, Issue #26, D06/RV02, D07 e DEC-05/06/07.

## 1. Avanço concreto e responsáveis

A instrução “ok vamos para essas etapas” permite avançar no controle de consumo e na preparação/coleta das revisões. Na resposta à pergunta de nomeação, o responsável declarou **“eu que farei todas as revisões”**. Fica designado o próprio responsável do projeto, identificado nesta conversa, para revisar **P01–P06**. A assinatura verificável poderá ser sua manifestação explícita nesta conversa ou comentário GitHub, referenciando o parecer e SHA examinados. Nome/conta pública devem acompanhar a publicação do aceite; não atribuir assinatura a outro profissional por inferência.

A nomeação não significa que os seis pareceres já foram lidos, aceitos ou tecnicamente homologados. Há um revisor humano acumulando as funções, não seis especialistas distintos. A análise abaixo é do Codex, autor da documentação: não é parecer humano independente. A aceitação de risco, competência e conclusão técnica cabem ao responsável designado; quando faltar evidência, manter ressalva/pendência explícita. Não inventar sign-off por título de função.

Baseline de trabalho: PR #25 em `cb36c0b488d8a03376876d53908f198f431582a5`, sem merge; PG18, PHP8.3/8.4 e PDO direto não persistente; PgBouncer adiado. PR #24 consultado em `e76a9089727d8ccefd492cd660014ce3b22dc5d8`: E02 e RV01 assistida lidos, ainda sem homologação real. Fontes distintas permanecem sem merge. Teto agregado de **R$50**, 30 minutos por perfil e provas sanitizadas por 30 dias já aprovados; não solicitar esses aceites novamente.

## 2. P04 — Proposta operacional de controle dos R$50

### 2.1. Tarifas e acesso efetivamente verificados

Fontes oficiais consultadas em 06/11/2026: [tarifas de runners](https://docs.github.com/en/billing/reference/actions-runner-pricing), [cobrança de Actions/armazenamento](https://docs.github.com/en/billing/concepts/product-billing/github-actions), [budgets e bloqueio de consumo](https://docs.github.com/en/billing/how-tos/set-up-budgets). Na consulta: Linux padrão x64 2-core custa **USD0,006/minuto**, com arredondamento por job; artefatos excedentes **USD0,25/GiB-mês**. Revalidar tarifas antes de qualquer execução futura; não assumir quota grátis.

Leituras autenticadas de permissões do Actions e de uso de billing do proprietário foram tentadas via `gh api`; ambas retornaram **HTTP403 Resource not accessible by integration**. Não houve alteração de credencial, helper, orçamento ou configuração para contornar a restrição. Acesso Git privado funciona, mas não comprova acesso a billing. Não se confirmou plano, quota restante, runner efetivo, crédito ou custo histórico.

O responsável deverá fornecer/registrar os números de Billing & licensing/Usage e Budgets & alerts da conta: produto/runner, crédito/quota restante, consumo atribuível à etapa até a data e controle de gastos disponível. Não precisa compartilhar cartão, token, DSN ou credencial. Sem esses fatos, o plano permanece estimativa, não orçamento executável homologado.

### 2.2. Envelope conservador proposto, não executado

| Componente futuro | Quantidade máxima proposta | Custo de referência antes de crédito |
|---|---|---|
| Laboratório D83/D84 | 2 perfis × até 3 execuções totais por perfil × 30min = **180min** | USD1,08 |
| CI existente induzido por alterações | Até 3 atualizações: Node push/PR até 40min + PHP PR até 30min por atualização = **210min** | USD1,26 |
| Artefatos finais, soma da etapa | **100MiB agregados**, 30 dias; sem cache pago/imagem customizada | Aproximadamente USD0,028 com margem para duração do mês |
| Total de referência | Até **390min + artefatos** | Até **USD2,368** no cenário acima |

A projeção do CI usa os workflows presentes na base: Node22/24 em push e PR, timeout10min; PHP8.3/8.4 no PR quando caminhos aplicáveis, timeout15min. Não alterar/desabilitar esses workflows nesta entrega nem presumir que não cobram. Jobs adicionais, re-runs, falhas e commits além do envelope devem ser lançados no controle e exigem recalcular a reserva. Três execuções incluem primeira tentativa e repetições, não três retries adicionais. Campanha paralela de outro projeto não pode consumir a mesma reserva sem discriminação; não bloquear orçamento compartilhado para resolver este laboratório.

**Cálculo em reais:** custo reservado = USD projetado × câmbio conservador aceito pelo responsável × fator de encargos/margem + consumo histórico atribuível ainda não contabilizado. Exemplo de sensibilidade, **não cotação cambial**: com R$10/USD e fator1,25, USD2,368 representa **R$29,60**, deixando R$20,40 do teto para histórico/incerteza. O exemplo não substitui taxa/encargos efetivos; crédito incluso pode reduzir cobrança, mas não é descontado sem comprovação. Se os custos anteriores ou o câmbio/encargos esgotarem a margem, reduzir o envelope ou parar antes de iniciar.

A guarda de 30 dias é contada do resultado final. Artefatos intermediários devem ser consolidados e eliminados segundo política; se permanecerem armazenados por tempo extra, acrescentar GiB-hora ao cálculo. Apagar artefato não elimina cobrança já acumulada. Não enviar dump, payload, vendor, imagem de banco ou PII como prova; manifestos e relatórios compactos devem caber no limite agregado. Nada foi instalado/criado no Actions por este plano.

### 2.3. Livro de controle e admissão de execução

Campos obrigatórios do livro, a preencher pelo responsável antes de autorizar laboratório: início/fim da etapa; saldo inicial/histórico; fonte e data de tarifa/câmbio/encargos; ID/SHA/run-attempt de cada job; runner; minutos arredondados; artefatos em bytes e expiração; USD realizado/estimado; R$ comprometido; crédito comprovado; responsável e saldo restante. **Estado inicial atual: consumo histórico desconhecido, não zero certificado.**

Antes de cada job/campanha futura, reservar o pior custo de todos os jobs disparados e da guarda até expiração. Não admitir nova execução se **consumo já comprometido + reserva da próxima execução + armazenamento futuro + margem** ultrapassar R$50. A contabilidade é por etapa e atravessa ciclos mensais; reset do budget GitHub não reinicia o teto aprovado. Não confiar só em dashboard com atraso de atualização.

Controles propostos: registrar aviso interno ao comprometer R$25; impedir novos jobs ao chegar a R$40 comprometidos, reservando ao menos R$10 para encerramento/armazenamento/atrasos. O responsável pode ajustar esses limiares conservadores dentro do teto, com justificativa. O limite vinculante continua **R$50**, não o limiar de aviso.

Limitar a uma campanha de laboratório por vez e sem reexecução automática de workflow completo; tentativas transacionais internas contam dentro dos 30min do job. Cancelar no timeout, sem repetir cegamente. As configurações são propostas para futura implementação autorizada; nenhum YAML foi escrito. Alterar runner/tarifa exige recálculo antes da execução.

GitHub pode oferecer budget com **Stop usage when budget limit is reached** e alertas; alertas sozinhos não bloqueiam uso. Verificar escopo e moeda antes de configurar: budget do repositório pode atingir CI paralelo, e budget mensal em USD não implementa sozinho teto de etapa em reais. Não alterar budget global ou política compartilhada sem avaliar impactos e obter autorização específica para essa mudança. Se indisponível, usar admissão manual com reserva pessimista e executor limitado; sem capacidade de manter controle confiável, não executar.

**Pendências P04 objetivas:** dados de billing/quota/consumo anterior; runner e disponibilidade; câmbio/encargos adotados; minor/digests/patches PHP/libpq fixados; aceite humano desta estimativa e procedimento. Nomeação do revisor está resolvida, mas o parecer P04 não foi emitido. Teto R$50 não precisa ser aprovado de novo.

## 3. P01 — Análise assistida e primeiro parecer para o responsável

Fontes: D06 seções G-BB03/04, RV02-03, D07/P01; PR #24 E02 e SEC-P02-RV01 em SHA acima. **Conclusão assistida:** contrato coerente para preparar laboratório com autoridade exclusivamente fictícia; insuficiente para autenticação/uso real. A análise humana P01 deve confirmar as condições abaixo, sem alegar teste executado.

| Entrada | Autoridade no laboratório proposto | Verificação e estado negado | Prova futura |
|---|---|---|---|
| Ator | Fixture local restrita ao harness, identificada explicitamente como sintética Portal | Origem CRM, ID textual igual, headers/objetos do cliente e emissor ausente não autenticam | H02/C03/P01; AUTH-01 de E02 |
| Estado/revisão | Registro sintético do primário para usuário, sem sessão real | Suspenso/revogado/revisão inválida impede efeito; fixture Demo não se torna middleware | H03/C05; AUTH-02 só no aspecto simulado, login/MFA real permanece fora |
| Empresa escolhida | Intenção de contexto, verificada contra vínculo canônico local | Empresa fora do vínculo/suspensa/inexistente nega sem enumeração | H01/P01; AUTH-03 |
| Membership | Par canônico usuário/empresa com estado, vigência e revisão | Convite/suspensão/revogação/expiração/duplicata não autorizam | H03/H04/P02; AUTH-04 |
| Papéis/grants | Projeção de papéis locais versionados, fonte única | Sem grant direto de payload, wildcard, staff/owner implícito ou autoelevação | C01/C02/P03; AUTH-05 |
| Dono do recurso | Chave composta no recurso privado fictício | Recurso/relacionamento estrangeiro não muda tenant nem retorna dados | H04/H05/P06 |
| Frescor | Primário sob barreira e releitura após lock | Decisão/cache antigo não permite novo efeito | H08/C04/P04; AUTH-06 |

**Condições específicas do parecer humano P01:**

1. Autoridade simulada é aceita **somente no laboratório sem endpoints/login/MFA**, com injeção restrita ao executor e proibição de promoção para tráfego real.
2. Bootstrap devolve apenas referências próprias mínimas; tenant de URL/header não concede acesso; identidade humana real Portal/CRM permanece separada.
3. RLS/contexto local não são prova de login nem garantia contra runtime com SQL arbitrário. O modelo de ameaça do laboratório considera runtime controlado; risco de runtime hostil permanece bloqueador para uso real.
4. A capacidade de lock/avaliação não pode aceitar tenant/ator arbitrários nem servir de bypass. Isso exige revisão P02/P03 e testes posteriores; P01 não os substitui.
5. Autenticador real, sessão/MFA, primeiro gestor/reingresso/recuperação e integrações ficam fora do recorte; negativos devem comprovar ausência de autorização implícita.

**Parecer P01 — aceite documental com condições registrado em 06/11/2026.** Revisor: responsável do projeto, autodesignado nesta conversa para P01–P06. Manifestação verificável: respondeu **“ok”** à pergunta expressa “Você aceita esse contrato P01 para o laboratório fictício, com essas limitações?”, após apresentação de identidade simulada restrita ao executor, independência Portal/CRM, fontes oficiais servidoras e contexto SQL sem prova de autenticação. Material apresentado: D08 em `5c2939449288f141a5761a2f2d51e60f2953f78d`, seção3, com D06/RV02 e PR24 referenciados. Conclusão: **aceito para planejamento do laboratório sintético, condicionado a P02/P03 e aos testes futuros**. Riscos residuais: origem real não implementada, runtime controlado assumido, bypass/RLS e concorrência ainda sem prova. Não é assinatura por especialista ausente, homologação de identidade real, encerramento de G-BB03 ou autorização de laboratório. Mantêm-se H02/C03/P01 e demais negativas previstas, todos não executados.

## 4. P02–P06 — Pacote dirigido ao mesmo revisor

| Parecer | Material concreto para examinar | Perguntas de decisão / saída esperada | Situação |
|---|---|---|---|
| P02 — RLS/DBA/Segurança | D06 G-BB02/05, RV02-03 e grafo de elegibilidade | Runtime sem owner/BYPASSRLS/DDL; FK tenant; policies linha antiga/nova; funções/locks sem oracles e sem recursão. Registrar ressalvas por caminho indireto e capacidades ainda sem desenho suficiente | Requisitos aceitos documentalmente pelo responsável; provas pendentes |
| P03 — Dados/Backend/Produto | D06 catálogo e RV02-04 | Validar mandato/último gestor, ordem de locks completa, snapshot após espera, limite/retry, marcador e commit inconclusivo. Não aceitar “RLS resolve concorrência” como conclusão | Requisitos aceitos documentalmente pelo responsável; implementação e testes pendentes |
| P04 — Plataforma/custo | Seção2 deste D08 + manifesto da baseline a completar | Preencher billing/quota, runner, patches/digests, estimativa e histórico; aceitar ou corrigir reservas/limites. HTTP403 não prova quota zero nem saldo disponível | Revisor designado; fatos e parecer pendentes |
| P05 — Privacidade/QA | RV02-05/06, D06 34 linhas H/C/P, DEC-06 | Provas sintéticas 30 dias já aceitas; conferir descarte e limite de artefatos; controles positivos/negativos por D83/D84; pooled adiado; uso real segue sem política homologada | Revisor designado; parecer pendente |
| P06 — Governança | DEC-05/06/07, D07 e este D08 | Registrar que o responsável acumula as revisões; não representar equipe de especialistas ausente. Vincular cada aceite a SHA/escopo/risco residual. Avaliação conjunta não dispensa evidência de cada P01–P05 | Nomeação registrada; prontidão ainda NO-GO |

**Parecer P02 — aceite documental dos requisitos registrado em 06/11/2026.** Revisor: responsável do projeto, autodesignado para P01–P06. Respondeu **“si aprovado”** à pergunta “Você aceita esses requisitos P02 para o laboratório fictício, mantendo a comprovação por testes pendente?”. Material de referência: D08 em `4bc5028b30ac23f9309025bc5f5a93b5468a0c20`, seção4; D06 G-BB02/05 e RV02-03. Requisitos apresentados e aceitos: runtime sem proprietário/superusuário/BYPASSRLS/DDL; relações privadas com FKs compostas por organização; políticas verificando linha antiga/nova; avaliador privado mínimo sem recursão, consulta arbitrária ou exposição de terceiros; provas futuras sob credencial runtime limitada, inclusive funções/views e caminhos indiretos. **Conclusão: requisitos documentais P02 aceitos para laboratório fictício; implementação concreta, grants/funções e segurança operacional ainda não homologados.** Permanecem pendentes P03 e testes H04/H05/H06/C06/C07/C09/P02/P06/P08, sem execução. Não é parecer assinado por DBA/Segurança ausentes, encerramento de G-BB02/05 ou autorização de instalar/implementar/executar laboratório.

**Parecer P03 — aceite documental dos requisitos registrado em 06/11/2026.** Revisor: responsável do projeto, autodesignado para P01–P06. Manifestação: **“ok aprovado”** após apresentação dos cinco requisitos P03 e pergunta específica sobre seu aceite para laboratório fictício, mantendo implementação e testes sujeitos à autorização posterior. Referência: D08 em `29e4b5cc327211626f77d710a08f8d02ad758139`, seção4, D06 G-BB04/06 e RV02-04. Foram aceitos: barreira transacional comum a efeito sensível/revogação, sem novos efeitos com grant antigo após commit da revogação; locks em ordem fixa e releitura após espera; retries limitados com decisão nova; commit inconclusivo tratado por marcador idempotente antes de repetir; proibição de autoelevação e remoção concorrente do último gestor, com reingresso/recuperação fora do recorte inicial. **Conclusão: requisitos documentais P03 aceitos, sem homologar algoritmo físico, snapshots, função de lock, retries ou garantias operacionais.** Mantêm-se as provas H08/C04/P04 e demais negativas de concorrência, todos não executados; desenho detalhado e execução continuam pendentes. Este aceite não é assinatura de profissionais ausentes, encerramento de G-BB04/06 ou autorização de código/SQL/laboratório.

Sequência proposta: P01 → P02/P03 em conjunto → completar fatos/aceitar P04 → P05 → consolidar P06. Não criar uma nova DEC para cada conversa que apenas confirma encaminhamento; registrar o aceite técnico efetivo quando o responsável analisar um parecer. Parecer documental pode ser aceito com ressalvas verificáveis, mas não converte cenários não executados em garantia de comportamento.

Formato para cada manifestação futura: parecer Pxx; responsável identificado; data; SHA examinado; condições aceitas ou devolvidas; risco residual e âmbito (laboratório sintético/uso real); conclusão; provas futuras obrigatórias. A autodesignação não é a assinatura antecipada desses campos. Não exigir nomes de outros revisores novamente enquanto a indicação do responsável continuar vigente.

## 5. Situação dos gates e execução

G-BB01/06: baseline PG18/PDO direto decidida, patch/manifesto e prova de transação ainda pendentes. G-BB02/05: requisitos P02 aceitos documentalmente pelo responsável; desenho concreto de privilégios/avaliador e evidências seguem pendentes. G-BB03: contrato P01 aceito documentalmente com condições pelo responsável; autenticação real e evidências continuam pendentes. G-BB04/06: requisitos P03 aceitos documentalmente pelo responsável; implementação do algoritmo e provas de concorrência ainda pendentes. G-BB07: retenção de provas sintéticas aprovada, política real/restore e P05 pendentes. G-BB08: plano de 34 cenários preservado, **0 executados**. Nenhum gate fechado/homologado por nomeação ou por teto financeiro.

Não há autorização atual para criar código/SQL/migration, executar PG/testes RLS ou provisionar laboratório. Depois dos pareceres e fatos faltantes, o responsável decide autorização **separada** de código/execução com SHA, branch, arquivos/operacões, ambiente, orçamento e parada. Merge e deploy permanecem decisões distintas. PR #25 continua draft, PRs #19/#24 e Issue #17 preservados; sem edição de dev/main/VPS/CRM.

Validação desta entrega: leitura das fontes e tarifas oficiais; inspeção de workflows existentes sem alteração; tentativas read-only de permissões/billing sanitizadas; conferência estática de fórmulas/escopo Markdown. Não foi iniciado manualmente nenhum job nem instalado banco/pooler. Workflows já existentes podem reagir automaticamente ao commit/PR: esses jobs não são laboratório PG e seu custo histórico não foi auditado nesta sessão; deve entrar na reconciliação de consumo da etapa, sem declarar gasto zero.
