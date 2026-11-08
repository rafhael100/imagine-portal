# SEC-P02-R01-10-R06 — Escolha de fronteira de confiança e autoridade de dados

**Data:** 08/11/2026. **Vínculo:** Issue #48 (https://github.com/rafhael100/imagine-portal/issues/48).
**Status:** arquitetura CANDIDATA em revisão; nenhuma opção A, B ou C recebeu aprovação definitiva nem aceitação de risco.
**Autorização desta etapa:** apenas documentação, matriz de ameaças e critérios de prova. Nenhum SQL funcional, migration, banco, branch principal, merge, autenticação real ou deploy.
**Precedência:** SEC-P02-R02-DEC-01 (D01-B revogação hierárquica e D02-A reforçada como orientação); SEC-P02-R04-DEC-02 (R04-D01-A..D05-A); SEC-P02-R05-IMPL-01; PRs #19, #24, #33, #46 e laboratório #47, ainda sem merge.

## 1. Evidência e conclusão estrita

- O CI do PR #47 executou PostgreSQL 18.6, PHP 8.4.27/PDO_PGSQL em ambiente descartável: https://github.com/rafhael100/imagine-portal/actions/runs/42496521968
- LAB-T06 mostrou que role sem BYPASSRLS e com policy por tenant nega na ausência do contexto e respeita um contexto fornecido. Isso é uma propriedade útil e limitada.
- LAB-T07 mostrou que a MESMA role runtime pode definir a variável de sessão portal_lab.organization_id para o tenant B e ler o payload fictício B pela RLS. O resultado registrado foi KNOWN GAP R01-10.
- LAB-T04, T05, T08 e T09 documentam lock de conta, FK tenant, revisão epoch e privilégio restrito em cenários específicos. Não demonstram prova de origem, concorrência distribuída nem segurança contra SQL hostil.
- Consequência: escolher um nome de parâmetro menos óbvio, usar SET LOCAL, trocar a origem do valor para Laravel, aplicar FORCE RLS ou colocar assinatura na MESMA aplicação **não** torna não forjável o contexto para quem possui execução SQL arbitrária com a mesma role.
- O risco de SQL arbitrário/contexto falsificável e o risco de runtime comprometido **não estão aceitos pela Direção**. Um workflow verde NÃO fecha R01-10.

## 2. Modelo explícito de ameaças, com níveis distintos

| Nível | Atacante | O que deve ser demonstrado | Observação |
| --- | --- | --- | --- |
| T0 | Cliente/browser hostil, cabeçalhos e ID de tenant adulterados | Não define ator, empresa, papel ou grants; sessão autenticada no servidor | Ainda sem emissor real |
| T1 | Erro de query/filtro, SQLi parcial ou input não parametrizado | GRANT mínimo, validação de entradas, RLS por comando, constraints e consulta parametrizada | RLS pode conter algumas classes, não todas |
| T2 | Execução SQL arbitrária sob credencial da aplicação | Não permitir que SQL do invasor reatribua o contexto e leia B | LAB-T07 demonstra FALHA da alternativa atual baseada em GUC |
| T3 | Aplicação web ou job totalmente comprometido, capaz de chamar serviços ou usar sessão/token disponível | Redução do raio de explosão e autoridade externa que não aceite alegações de identidade/consentimento fabricadas | Serviço separado sozinho NÃO prova contenção se confia no web hostil |
| T4 | Comprometimento do serviço guardião, DBA/superuser/owner ou operador com credenciais totais | Procedimentos administrativos, segregação, auditoria, chaves e recuperação | Nem RLS nem um único serviço protegem contra autoridade total |

**Decisão pendente da Direção:** quais níveis T0..T4 são requisitos de contenção, quais são apenas risco operacional residual e em quais ações (consulta, mutação, exportação, pagamento, dados sensíveis). Sem isso não há arquitetura definitiva.

## 3. Alternativas e recomendação de engenharia

### A — Backend Portal confiável + PostgreSQL RLS por contexto

Desenho documental ratificado para aprofundamento. O autenticador próprio resolve ator, sessão, organização, membership e grants; runtime sem owner/BYPASSRLS, queries parametrizadas, credenciais segregadas, transactions curtas, FK composta, RLS como defesa em profundidade e locks P→A→S→O→M→R.

**Ganho:** baixa complexidade relativa; protege contra falhas comuns e clientes hostis quando o backend cumpre o contrato. **Limitação não negociável:** se a role puder executar SQL arbitrário capaz de atribuir GUC, o tenant pode ser falsificado. T2 não está contido; T3 tampouco. **Essa limitação NÃO foi aceita como risco de produção.**

### B — Autoridade independente de identidade, tenant e efeitos privilegiados

**Opção recomendada para detalhamento se a Direção exigir contenção de T2/T3:** separar aplicação web de um serviço autoritativo do Portal que: (1) possui o material de validação e revogação de sessão; (2) calcula tenant/grants a partir da autoridade própria; (3) executa somente operações de escopo fechado contra banco e credenciais que o web não possui; (4) aplica efeitos e auditoria junto à decisão, sob a ordem transacional de locks.

**Condições indispensáveis:**

- Web/worker público não obtém credencial SQL com SELECT/DML genérico nos dados protegidos, não recebe chaves de assinatura do serviço e não pode mudar roles ou policies; segregar redes, deploy, segredos, IAM e operações administrativas.
- O serviço não confia em actor, tenant, MFA, permissões, job-id ou cabeçalho vindos da borda. Autenticação de usuário e sessão deve ser comprovada por um canal/segredo verificável pela própria autoridade. Um bearer roubado ou um web hostil capaz de enviar ações legítimas em nome da vítima continua risco.
- Usar intents nomeados com recurso/tenant/ação no servidor e entitlement atual; não oferecer endpoint do tipo execute arbitrary SQL, SQL RPC genérico ou proxy SELECT livre. Escopo por capability, limites de leitura/exportação, paginação e quotas.
- Para ações sensíveis onde se exige prova forte de intenção humana independente da borda, estudar desafio vinculado à transação e confirmação com fator que a borda não consiga forjar (por exemplo, autenticação forte com origem e dados corretamente vinculados). Não presumir que autenticar sessão impede UI enganosa ou que um MFA genérico autoriza qualquer efeito.
- Revogação, role grants e efeitos devem compartilhar autoridade/coordenador transacional ou protocolo de fencing demonstrado; um serviço isolado que apenas responde YES/NO antes de um write independente mantém risco TOCTOU.
- Servidor autoridade comprometido, credenciais DB vazadas ou cliente final induzido a aprovar ação maliciosa são riscos adicionais a testar. Não afirmar contenção absoluta de T3/T4.

**Custo/contrapartida:** mais componentes, latência, operações, disponibilidade e superfície de API. Nenhuma infraestrutura/cobrança autorizada nesta fase.

### C — Isolamento por tenant com bancos/roles independentes

Banco e credencial específicos por tenant podem limitar dano de uma role isolada, **desde que a seleção de tenant e entrega de credencial não sejam controladas pela borda hostil**. Caso contrário, T2/T3 conseguem usar outro contexto ou pedir nova credencial. Há custo de operação, migrações e escalabilidade. É uma camada ou alternativa a estudar, não solução automaticamente superior.

**Recomendação para decisão:** não marcar A como capaz de conter T2. Se o requisito incluir T2/T3, priorizar uma variante de B com redução de capacidades da borda, possivelmente complementada por C para tenants de maior risco. Se somente T0/T1 forem aceitos, A pode continuar como candidata, mas exigir aprovação expressa do risco residual e pareceres independentes. Não alterar a escolha normativa por inferência.

## 4. Mapa de autoridade obrigatório antes de qualquer implementação

| Objeto/operação | Writer/guardião proposto | Proibido ao runtime de borda | Evidência que falta |
| --- | --- | --- | --- |
| Identidade humana Portal | Emissor autenticador próprio do Portal | Criar ator por ID/email/CNPJ de terceiro | Prova de origem, canal e bootstrap |
| Credencial, desafio, MFA | Serviço autenticador e auditoria de fator | Emitir ou marcar MFA como válido por DTO/cookie CRM | Fatores, replay, recovery, abuse controls |
| Sessão/revogação | Autoridade de sessão com epoch e barreiras | Declarar status/revision por header e cache | Carga, concorrência, restore, clock, rotação |
| Organização e membership | Resolvedor oficial Portal | Resolver grants por argumentos não verificados | Último gestor, reingresso, estado organizacional |
| Recurso/entitlement | Registro oficial do tenant | Alterar owner tenant ou entitlement livre | FK composta, isolamento e política granulares |
| Execução de efeitos | Operador autoritativo com política e transação | Executar DML genérico, alterar policy ou contexto de principal | Locks/fencing, idempotência e auditoria |
| SQL owner/migrador | Identidades técnicas off-path e segregadas | BYPASSRLS, OWNER, CREATE, SET ROLE privilegiado, SECURITY DEFINER genérico | Inventário real GRANT/EXECUTE/roles/deploy |
| Auditoria e backup/restore | Autoridade separada com gestão e reconciliação | Corrigir audit trail ou ressuscitar sessões | Proveniência, retenção, autorizações, recovery |

**Ponto de atenção:** remover SELECT/DML direto da aplicação é necessário na hipótese B, mas NÃO é suficiente se APIs do guardião aceitam identidade falsa ou se permitem escalada de acesso. Os endpoints deverão ter política de origem e intent verificada.

## 5. Contratos de segurança e revisão (propostos, NÃO implementados)

1. A cada requisição, o serviço autoritativo reconstrói ator, sessão, tenant, vínculo e capacidade de fontes próprias. Rejeita dúvida e falha, inclusive store indisponível.
2. As operações de leitura e escrita têm parâmetros limitados e schema autorizado; consulta por ator arbitrário, SQL livre e mudança de tenant sem comprovação são proibidos.
3. Efeitos com impacto concorrente revalidam sessão/role/grants antes do efeito sob a autoridade de revogação. Preservar hierarquia P→A→S→O→M→R; ordem de locks e prova de linearização ainda pendentes de DBA.
4. Roles de runtime não possuem owners privilegiados; fazer inventário de schema CREATE, objeto owner, membership transitiva, SET ROLE, BYPASSRLS, views, definer functions, EXECUTE a PUBLIC, procedures, triggers, COPY, search_path e migrations.
5. RLS continua relevante dentro do guardião como barreira adicional e deve ser revisada por DML; WITH CHECK/USING, permissive OR, joins e erros FK não devem criar oráculos.
6. Auditoria registra origem e resultado com IDs de correlação sem segredos; erro de autorização não revela existência de outras empresas. Logs e backups mantêm retenção aprovada, não inventar prazos.
7. Jobs e automações possuem identidades técnicas de mínimo privilégio e não reutilizam sessão humana nem credencial SQL administrativa.
8. Apenas instâncias com identidade de serviço verificável acessam a autoridade. Certificado, token ou assinatura emitida/guardada pelo runtime web hostil não demonstra identidade humana, tenant ou vontade legítima.

## 6. Critérios de GO/NO-GO para futura aprovação

**NO-GO automático:** (a) qualquer role de borda consegue ler B a partir do tenant A por SQL; (b) API da autoridade aceita actor/tenant forjado; (c) política/grant pode ser modificado fora das barreiras; (d) teste positivo depende de fake da sessão; (e) segredo administrativo disponível na aplicação; (f) ausência de análise do risco T2/T3; (g) falta de revisão AppSec/DBA.

**Provas que devem ser demonstradas em futuro laboratório** estão detalhadas no arquivo SEC-P02-R01-10-R06-MATRIZ-DE-PROVAS-2026-11-08.md. Testes continuam não executados até novo escopo/aceite.

## 7. Governança

Este documento não introduz código, roles SQL reais, migrations, serviços, conectores, infraestrutura nem login. Não fecha R01-10 ou os demais P0 R01-01/02/03/09. G-ID, G-BB, D02-SEC, D03/RLS e release permanecem abertos. Não altera PRs #19/#24/#33/#46/#47 nem integra esta documentação à dev por silêncio.

**Decisão a solicitar:** o responsável deve escolher qual hipótese de ameaça deseja exigir (T0/T1 vs T2/T3) e autorizar separadamente a arquitetura e o laboratório negativo que a comprovará. Uma aprovação genérica de documentação não é aceite de risco.