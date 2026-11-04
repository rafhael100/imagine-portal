# AUT-P09 — Alternativa B aprovada: autonomia por operação e risco

**Aprovação:** resposta contextual **“ok”** à pergunta direta “Você aprova a AUT-P09 pela alternativa B?”, em 04/11/2026. A aprovação normativa refere-se à [proposta AUT-P09](P0-DOC03-AUT-P09-MATRIZ-AUTONOMIA-AGENTES-APROVACAO-HUMANA-PROPOSTA.md); não homologa contratos técnicos, ferramentas, agentes nem playbooks.

## Decisão aprovada — alternativa B

Adotar **matriz de autonomia por identidade do agente, ferramenta/versão, operação concreta, serviço/empresa, ambiente, classe de risco, procedimento homologado, condições verificadas e alçada**, com autorização de execução em camada técnica independente da IA.

1. **Três níveis (IN-131):** nível 1 monitora/diagnostica sem alterar produção; nível 2 executa somente correções seguras autorizadas; nível 3 prepara operações críticas, mas não as executa sem a autorização humana exigida.
2. **Baixo/moderado risco:** ações seguras podem ser automáticas somente com permissões válidas, política e playbook testado, homologado e ativo, sob escopo e limites específicos. Playbook reutilizável de baixo/moderado risco não exige aprovação humana individual a cada execução.
3. **Alto/crítico risco:** quando a intervenção exige autorização humana, basta **UMA ÚNICA aprovação expressa de responsável habilitado** para a ação, alvo, ambiente, condições e prazo delimitados. Não criar segunda aprovação obrigatória nem autorização genérica permanente.
4. **Portão externo ao modelo:** verificar antes de cada efeito externo as credenciais, escopo, versões, risco, política, homologação, concorrência, idempotência e eventual aprovação; instruções no prompt, documentos, logs ou tickets não concedem permissão.
5. **Sem autoaprovação ou autoelevação:** agentes, incluindo Supervisor, não alteram a própria classe de risco, permissões, aprovações, políticas ou ferramentas de forma a contornar o portão. Suspensão/revogação impede novas operações.
6. **Autoridades distintas:** aceite da versão do site é do cliente autorizado; entrega oficial depende do checklist e Portal; troca de CRM segue chamado e equipe Imagine; resultado final de lead pertence ao CRM; despesas e contratos pertencem às autoridades financeiras. Nenhum desses direitos decorre de uma aprovação técnica genérica.
7. **Operações sensíveis:** DNS/credenciais/permissões, infraestrutura produtiva crítica, migração ou compartilhamento entre empresas, restauração destrutiva e compromissos financeiros seguem risco, alçada e autorização específica. Operações seguras rotineiras não exigem burocracia extra.
8. **IN-127:** reprocessamento de lead só sob regras de destino/autorização, idempotência e limite contínuo de 432h. Descarte temporário regular já aprovado poderá ser automatizado por procedimento seguro homologado, sem aprovação humana por lead, com evidências AUT-P07.
9. **Falha fechada:** na ausência de permissão, homologação válida, condições ou aprovação exigida, não executar efeito externo; permitir monitoramento, alerta e contenção segura previamente autorizados.
10. **Auditoria:** registrar contexto, risco, autorização, playbook/versão, execução, resultado verificado, falhas e revogações, com isolamento multiempresa, mínimo privilégio e proteção de dados/segredos.
11. **Rollback:** **AGI-11-RB01 continua DESABILITADO**; esta aprovação não autoriza ativá-lo ou presumir restauração/reversão automática.

**Alternativas não adotadas:** A — autonomia genérica por nome/especialidade do agente; C — exigir aprovação humana em toda leitura ou operação segura, inclusive as homologadas.

## Limitações

Permanecem pendentes os contratos de autorização, classificação exata por ação/ambiente, catálogo de ferramentas, identidades, políticas de acesso, testes, homologação de playbooks, endpoints, custos, retenção de logs, mecanismos de revogação e implantação. **Esta é exclusivamente uma decisão de documentação.**

**Estado consolidado:** AUT-P01 a AUT-P09 aprovadas pela alternativa B; **AUT-P10 PENDENTE**. **P0-DOC03 e RF-PEND-01 ABERTOS; RF-PEND-04 NÃO HOMOLOGADA; AGI-11-RB01 DESABILITADO.** Nenhuma programação, testes, implantação, homologação, contratação, alteração de permissões ou operação real foi autorizada.

Referências: [proposta AUT-P09](P0-DOC03-AUT-P09-MATRIZ-AUTONOMIA-AGENTES-APROVACAO-HUMANA-PROPOSTA.md); [IN-131](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-131-niveis-autonomia-agentes-tecnicos.md); [IN-137](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-137-central-aprovacoes-tecnicas.md); [IN-138](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-138-matriz-riscos-aprovacoes-agentes.md); [IN-155](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-155-homologacao-inteligente-procedimentos-agentes.md); [IN-166](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-166-gestao-inteligente-ferramentas-permissoes-agentes.md).
