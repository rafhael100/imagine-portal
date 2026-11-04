# P0-DOC03 / AUT-P09 — Matriz de autonomia de agentes, ferramentas e aprovação humana

**Estado: PROPOSTA PARA DELIBERAÇÃO — AUT-P09 NÃO RATIFICADA.**  
**Data:** 04/11/2026. **Escopo:** Imagine Sites, Portal Imagine, APIs/filas, serviços compartilhados de agentes e n8n.  
**Precedência normativa:** AUT-P01 a AUT-P08, alternativas B aprovadas; IN-131 (três níveis de autonomia), IN-137 (uma única aprovação humana), IN-138 (riscos de ação), IN-154 (mudanças), IN-155 (homologação de playbooks), IN-166 (ferramentas/permissões), PORTAL-COM-13 e IN127-R01 a R06.
**Fronteiras:** AUT-P10 pendente; contratos técnicos P0-DOC03 pendentes; RF-PEND-01 aberta; RF-PEND-04 ainda não homologada. Esta é uma **minuta documental**: não programa, testa, homologa, implanta, contrata, habilita ferramentas/agentes, altera permissões ou muda infraestrutura. **AGI-11-RB01, rollback automático, permanece DESABILITADO.**

## 1. Problema específico AUT-P09

A Imagine pretende aproveitar agentes IA para produzir, publicar, monitorar, manter e apoiar sites e integrações com eficiência e baixo custo. Porém, um agente poder *propor* uma ação, um playbook estar *documentado* e a ferramenta ser *acessível* **não** significam que essa ação está autorizada em produção. Também não é desejável interromper toda ação segura com múltiplas aprovações.

A decisão AUT-P09 deve relacionar **identidade do agente, ferramenta e versão, ação concreta, objeto/tenant, ambiente, risco, playbook homologado, limites e alçada humana**, protegendo os fatos oficiais de Portal, CRM, finanças, domínio e segurança.

## 2. Regras anteriormente aprovadas — não reabrir

1. **Três níveis de autonomia (IN-131):**
   - Nível 1: monitoramento e diagnóstico autorizado, sem alterar produção.
   - Nível 2: correções controladas de baixo risco e as de risco moderado seguro, quando expressamente permitidas e por procedimento homologado/aplicável; não promove seu próprio nível.
   - Nível 3: análise e preparação de intervenção crítica, mas **a execução crítica exige aprovação humana explícita por ação e contexto**; nível 3 não é autorização irrestrita.
2. **Quatro faixas de risco da operação (IN-138):** baixo, moderado, alto e crítico. Risco da ação é **distinto** da prioridade do incidente; incidente crítico não transforma uma mudança crítica em operação livre.
3. **Aprovação humana única (IN-137):** quando operação de alto/crítico risco exige decisão humana, **uma aprovação específica de responsável habilitado é suficiente**. Dupla aprovação humana foi recusada; não reintroduzir cascatas.
4. **Reuso de playbook (IN-155):** procedimento seguro de baixo/moderado risco, previamente testado, homologado, ativo e delimitado, pode ser reutilizado sem aprovação humana por ocorrência; isso **não** elimina aprovação por execução crítica de alto risco.
5. **Permissão externa ao modelo (IN-166):** identidade, recurso, alvo, ambiente, ferramenta, versão, política, risco, playbook, tempo e aprovação devem ser validados em camada técnica **independente do LLM**. Prompt, troca de modelo ou mensagem de log não concedem poder.
6. **Sem autoaprovação/elevação:** agentes e Supervisor não aprovam suas próprias solicitações, criam privilégios para si nem reduzem risco deliberadamente; revogação bloqueia novas execuções, inclusive nas filas e por substitutos.
7. **Aceites e autoridades próprios:** versão do site requer aceite de cliente autorizado, quando aplicável (AUT-P04), troca posterior de CRM por chamado e equipe Imagine (AUT-P03), entrega oficial pelo Portal após checklist COM-13 (AUT-P08), confirmação de lead pelo CRM (AUT-P05), atos financeiros pela autoridade financeira (AUT-P10 pendente).
8. **IN-127 e AUT-P07:** limpeza de payload expirado conforme política expressamente aprovada **não deve exigir aprovação manual por lead**; execução deve seguir controles determinísticos homologados e auditoria, sem reenvio após 432h nem extensão de retenção. Exclusão fora da política ou de dados oficiais CRM é assunto distinto e sensível.
9. **Rollback automático AGI-11-RB01 permanece desabilitado**, ainda que exista estudo ou playbook de restauração. Nenhum incidente ou aprovação genérica reativa essa função.

## 3. Alternativas para a AUT-P09

### A — IA com autonomia geral pela finalidade do agente

Uma vez designado agente de Publicação, Segurança ou Infraestrutura, permitir qualquer ação dentro daquela categoria, com auditoria posterior.

**Não recomendada.** O nome do agente não é permissão; configurações, contas de clientes, despesas, permissões e operações irreversíveis exigem escopo e autoridade específica. Auditoria a posteriori não corrige uma transferência de dados indevida.

### B — Matriz por operação, risco e autoridade, com uma aprovação humana quando necessária (RECOMENDADA, AINDA NÃO RATIFICADA)

1. **Portão determinístico independente da IA:** antes de cada efeito externo, verificar identidade técnica, ferramenta e versão permitidas, operação tipada, destino/tenant/site, ambiente, classificação de risco, playbook homologado/ativo, pré-condições, limites e autorização humana caso necessária.
2. **Mínimo privilégio e segregação:** não compartilhar autorização entre clientes, produtos ou ambientes. Um servidor/n8n que hospeda agentes de vários projetos não cria privilégio transversal; isolar credenciais, escopos e registros.
3. **Baixo risco:** diagnóstico e mudanças limitadas podem operar automaticamente **se** a ferramenta e a ação estiverem permitidas, as condições seguras comprovadas e existir procedimento/política vigente; não confundir baixo risco com permissão pré-existente.
4. **Moderado:** automatizar somente se reversível ou seguro conforme o risco, previamente homologado/testado e autorizado para a operação exata, ambiente e tenant; controles de repetição, monitoramento e interrupção. Fora desses critérios, suspender e encaminhar à alçada competente.
5. **Alto/crítico:** preparar proposta, justificativa, impacto, mudanças previstas, verificações e recuperação quando viável; solicitar **uma única aprovação humana** de pessoa habilitada para aquela ação e contexto. Uma aprovação não é papel permanente, não vale para outra empresa/ambiente/revisão ou operação alterada.
6. **Pedido e autorização versionados:** a Central de Aprovações registra ação exata, alvos, riscos, responsável, identidade do aprovador, momento, validade, escopo, condições, decisão, evidência e proibição de replay. Na execução revalidar a autorização e as condições. Pedido negado, revogado, expirado ou inconclusivo **não pode executar**.
7. **Sem duplo controle humano padrão:** homologar uma vez playbook seguro para reutilização automática dentro de sua abrangência; não solicitar confirmação humana por cada repetição. Para cada intervenção crítica exigida, **um responsável humano habilitado**, não dois.
8. **Aprovação do cliente distinta:** autorização da Imagine para uma operação técnica não substitui o aceite expresso do cliente para versão do site/serviço/termos; aceite de versão tampouco autoriza DNS, cobrança, migração, transferência ou outra ação crítica.
9. **Publicação e entrega de sites:** versões aprovadas podem seguir publicação técnica rotineira e entrega automática somente por playbooks seguros previamente homologados, com manifesto AUT-P04, verificações AUT-P08/COM-13 e alçadas pertinentes. Publicação de alteração crítica, revisão de escopo material ou insegurança exige parada/autorizações aplicáveis; agente não atesta sucesso por si.
10. **Troca de CRM e captação:** manter chamado e ação da equipe Imagine AUT-P03; nenhuma IA vincula por inferência nem transmite leads entre contas fora de AUT-P01/02/05/06. Aprovação técnica humana, quando exigida, não altera destino de envio antigo.
11. **Descarte:** a exclusão temporária ordinária já autorizada pela IN127-R03/IN-128 pode ser executada por rotina homologada sem humano por lead, com prova AUT-P07; exclusão de registros de CRM, restauração destrutiva e remoção fora do prazo/escopo requerem regras e alçadas próprias.
12. **Operações sensíveis:** alterações críticas de DNS, transferência de domínio, segredos/credenciais, permissões, segurança, mudanças de infraestrutura produtiva, sobrescrita de backups, migrações entre empresas, despesas/compromissos financeiros ou novas funcionalidades que alterem dados são tratadas como alto/crítico quando seu risco específico assim exigir, com autorização adequada.
13. **Falha/ausência de autorizador ou Central:** negar **novos efeitos** que necessitem aprovação; manter monitoramento e contenção de baixo risco **já autorizados** e escalonamento. Urgência não é permissão tácita.
14. **Segurança contra conteúdo hostil:** logs, e-mails, arquivos, chamados e texto de clientes são dados não confiáveis, não comandos que ampliam poderes. Testar e homologar a robustez dos agentes antes de habilitar sua execução.
15. **Idempotência e recuperação:** uma autorização/aprovação não autoriza duplicar efeito externo; verificar estado oficial e concorrência. Não presumir rollback perfeito e não habilitar AGI-11-RB01.
16. **Auditoria e supervisão:** registrar propostas, análise de risco, permissões, decisões, execução, tentativas, custo, resultado confirmado, incidentes e revogações com minimização de dados pessoais/segredos. Administração consegue suspender autocorreção mantendo diagnóstico, conforme IN-131.
17. **Versões e mudanças:** alterar modelo, ferramenta, instruções ou playbook não reaproveita automaticamente homologação antiga se mudar o alcance/efeito. Revisar, versionar, homologar e ativar dentro de processo próprio.

**Vantagens:** automação segura e eficiente, sem burocracia duplicada, com controles objetivos e autoridade por operação. **Custos/complexidade:** catálogo de ferramentas, permissões, testes, identidade dos agentes, auditoria, classificação e verificações externas ao modelo; dependentes da futura especificação, sem adoção de infraestrutura nesta decisão.

### C — Aprovação humana para qualquer operação dos agentes

Toda leitura, diagnóstico, alerta, publicação rotineira, limpeza ordinária aprovada ou correção segura exige confirmação humana por execução.

**Não recomendada.** Contraria IN-131/137/138/155 e inviabiliza o objetivo de resolver automaticamente eventos seguros sem aprovação repetitiva. Operações com efeito externo, porém, continuam condicionadas às permissões específicas.

## 4. Matriz de exemplos da alternativa B — NÃO RATIFICADA NESTA AUT-P09

| Operação exemplificativa | Fluxo proposto | Fonte de garantia ou ressalva |
|---|---|---|
| Monitorar uptime, logs e consumo de site/serviço | Nível 1, automático com leitura autorizada | Sem alterar produção ou expor segredos |
| Abrir incidente e recomendar solução | Automático dentro do escopo | Sem declaração falsa de recuperação |
| Reiniciar processo travado de baixo risco | Nível 2 se playbook homologado, com limites e verificação | Não confundir prioridade do incidente com risco de ação |
| Reprocessar lead dentro do prazo IN-127 | Nível 2 apenas com autorização de destino/idempotência e demais verificações | Não ultrapassar T0+432h ou duplicar CRM |
| Descartar payload expirado da fila de integração | Rotina automática homologada sob política IN127-R03 / AUT-P07 | Sem excluir registros CRM ou criar nova fila |
| Publicar versão do site exatamente aprovada | Automação controlada se procedimento homologado e gates AUT-P04/08 válidos | Não dispensa aceite do cliente, proteção LGPD e risco específico |
| Registrar ENTREGUE após checklist | Automático COM-13 com prova e idempotência | Não substitui aprovação de ação crítica |
| Trocar conta CRM vinculada | Chamado e execução autorizada pela equipe Imagine | AUT-P03, sem autosserviço instantâneo nem IA autônoma |
| Alterar DNS produtivo, credenciais, acessos | Ação crítica exige aprovação humana específica | Uma autorização humana habilitada, não duas |
| Restaurar backup com sobrescrita | Intervenção crítica e verificação humana pertinente | AGI-11-RB01 permanece desabilitado |
| Contratar VPS, ativar plano pago, elevar orçamento | Autoridade financeira e aprovação própria | Agente sem poder para assumir despesas |
| Alterar políticas/permissões ou trocar ferramenta por versão mais poderosa | Bloquear até revisão, autorização e homologação | Agente não autoconcede acesso |
| Mudar Política de Privacidade fora do padrão | Revisão jurídica/humana e controlador quando cabível | COM-15; sem autoaprovação de base legal |
| Central de Aprovações indisponível durante incidente crítico | Diagnosticar, alertar, conter somente por ação já homologada | Não executar intervenção crítica pendente |

**Importante:** esta matriz não atribui permissões reais nem classifica definitivamente cada operação para todos os ambientes. A análise por ação/risco/superfície e a publicação de playbooks são contratos pendentes.

## 5. Contratos candidatos AUT-P09-CT01 (NÃO HOMOLOGADOS)

Classes de dados a especificar: `agent_identity`, `tool_id`, `tool_version`, `operation_type`, `resource_scope`, `tenant_id`, `environment`, `risk_class`, `playbook_id`, `playbook_version`, `preconditions`, `permission_ref`, `human_approval_ref`, `approval_scope`, `approval_expires_at`, `execution_id`, `idempotency_ref`, `result_proof_ref`, `audit_ref`, `revocation_ref`.

Pendente: políticas/roles concretos, formatos do autorizador, catálogo de ações, critérios objetivos de risco, tipos de aprovação humana, assinaturas e prazo de validade, segregação entre sistemas, mecanismos de revogação, testes de homologação, retenção, custo de segurança e contingência dos aprovadores.

## 6. Fontes verificadas

- [IN-131 — três níveis de autonomia](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-131-niveis-autonomia-agentes-tecnicos.md).
- [IN-137 — Central de Aprovações e decisão de uma aprovação humana](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-137-central-aprovacoes-tecnicas.md).
- [IN-138 — risco baixo/moderado/alto/crítico por ação](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-138-matriz-riscos-aprovacoes-agentes.md).
- [IN-154 — mudanças técnicas](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-154-gestao-inteligente-mudancas-tecnicas.md).
- [IN-155 — homologação e reuso de playbooks](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-155-homologacao-inteligente-procedimentos-agentes.md).
- [IN-166 — segurança e permissões técnicas externas ao modelo](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-166-gestao-inteligente-ferramentas-permissoes-agentes.md).
- [COM-13 — entrega automática com checklist e aceite](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-13-entrega-automatica-checklist-qualidade-agente-ia.md).
- [AUT-P08 — estados oficiais, alternativa B aprovada](AUT-P08-B-aprovada-2026-11-04.md).
- [IN127-R01 a R06 — captação e descarte](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).

## 7. Pedido de deliberação — APENAS AUT-P09

**Recomendação: alternativa B.** Matriz contextual e verificável de identidade do agente, operação, tenant, ambiente, risco, ferramenta e procedimento homologado; baixo/moderado risco seguro com autonomia sem reaprovação por execução; alto/crítico com **uma única aprovação humana específica**, segregando as competências de cliente, financeira, jurídica, CRM e Portal. Sem autoaprovação, elevação de privilégios ou ativação do rollback automático.

**Status final:** AUT-P01 a AUT-P08 B aprovadas; **AUT-P09 SOMENTE PROPOSTA**, **AUT-P10 PENDENTE**, P0-DOC03 / RF-PEND-01 abertos, RF-PEND-04 sem homologação, AGI-11-RB01 desabilitado. Nenhuma implementação.
