# P0-DOC03 / AUT-P03 — Troca controlada da conta CRM vinculada a um Site Imagine

**STATUS: PROPOSTA PARA DELIBERAÇÃO — NENHUMA ALTERNATIVA RATIFICADA.**
**Escopo:** alteração posterior de vínculo entre Onepage/Landing Page e conta CRM Imagine, com separação entre recebimentos novos, operações antigas e cortesia comercial.
**Precedências ratificadas:** AUT-P01 B (autoridade versionada, duas APIs, barreira de revogação, falha fechada); AUT-P02 B (direito de captação distinto de cortesia, assinatura e conectividade); IN127-R01 a R06 (T0, prazo, descarte, auditoria e reconciliação).
**AUT-P04 a AUT-P10:** pendentes, não abrangidas nesta minuta. **P0-DOC03 e RF-PEND-01:** abertos; **RF-PEND-04:** sem homologação técnica.
**Restrições:** documentação apenas; nenhum código, teste, homologação, implantação, alteração de workflow ou infraestrutura. **AGI-11-RB01 desabilitado.**

## 1. Políticas já aprovadas que a AUT-P03 deve preservar

1. O cliente abre **chamado pelo Portal** para trocar o CRM vinculado; a equipe Imagine executa a mudança técnica gratuitamente, com conferência do destino, não sendo ação livre/instantânea de autosserviço.
2. O solicitante e as contas de origem e destino devem ter autorização/titularidade pertinentes verificadas. Uma conta inexistente, de outra empresa ou não autorizada jamais pode ser escolhida só pelo seu identificador.
3. A Imagine realiza gratuitamente somente **desvinculação e nova integração**. A **migração de dados históricos entre contas é responsabilidade do cliente**, por ferramentas próprias do CRM para backup, exportação e importação.
4. Um lead já **entregue** à conta antiga permanece lá, sujeito à política dessa conta; não se move ou exclui automaticamente na troca.
5. Uma submissão **aceita antes** da troca conserva T0, ID, autorização e **destino originalmente vinculado**. Não reencaminhar automaticamente ao novo CRM. Se o antigo destino perdeu autorização para receber, bloquear novas transmissões a ele e tratar a pendência sem alongar 432h, sem reidratar payload após descarte; detalhes especiais pertencem também à AUT-P06.
6. A conta CRM antiga e a nova têm elegibilidade/benefício Básico **reavaliados separadamente**, respeitando uma cortesia por conta e direitos de contratos pagos independentes. Nenhuma alteração de benefício é prova automática de recebimento de leads.
7. O frontend pode permanecer com página antiga em cache; autorização para novas submissões é validada **no backend das APIs principal e contingente** conforme AUT-P01 B e AUT-P02 B.
8. Mudança de vínculo não autoriza alteração contratual, cobrança, concessão de permissões ou movimentação de dados por agente sem alçada.

## 2. Alternativas para decisão

### A — Substituição direta do destino após validação do chamado

Após verificar a conta nova, o administrador altera o identificador de destino, e os serviços passam a processar novos envios.

**Vantagem:** sequência simples. **Risco:** atraso de propagação, uma API com versão antiga, envio para conta errada, corridas entre submissões e alteração de benefício. Sem uma barreira de revogação detalhada, é insuficiente para garantir AUT-P01 B. **Não recomendada.**

### B — Troca por fases com bloqueio seguro, ativação confirmada e trilha de auditoria (RECOMENDADA; NÃO RATIFICADA)

A mudança será **comando formal associado ao chamado**, executado por colaborador Imagine autorizado, envolvendo preparação, bloqueio seguro da revisão antiga, ativação da nova revisão e comprovação das duas rotas.

Fases propostas:

1. **Solicitação registrada:** chamado autenticado, site/formulários afetados, conta CRM atual e destino pretendido; motivo e evidência mínima.
2. **Validação do destino e das permissões:** confirmar existência e titularidade/autorizações exigidas nas contas antiga e nova, elegibilidade de contratos e direito de recebimento. Não ativar conta destino em estado inconclusivo.
3. **Preparação da revisão:** criar proposta versionada sem mudar o destino ativo, com validações, auditoria e plano de interrupção/recuperação. Distinguir dados já entregues, pendentes e futuros.
4. **Barreira de troca:** suspender temporariamente **novas aceitações** dos formulários afetados, ou usar mecanismo equivalente demonstravelmente seguro, até impedir que **ambas as APIs** aceitem com a autorização anterior. Rota sem confirmação ou com estado antigo deve falhar fechada.
5. **Ativação controlada:** aplicar a nova revisão oficial do vínculo com o novo destino validado e a autorização de captação da AUT-P02; habilitar novas aceitações **somente após** ambas as rotas aplicarem/confirmarem a revisão válida ou ficarem comprovadamente bloqueadas.
6. **Verificação de integração:** confirmar a consistência do estado das rotas e a capacidade de entregar novos envios ao destino, usando procedimento seguro/sintético identificado, sem criar lead comercial fictício nem declarar confirmação por mero HTTP 202. O detalhamento de testes pertence à fase posterior, não autorizado nesta etapa.
7. **Reavaliação independente da cortesia:** disparar verificação da elegibilidade das contas antiga e nova, sem duplicar cortesia, interromper CRM pago ou iniciar cobrança automaticamente.
8. **Encerramento e comunicação:** registrar quem aprovou/executou, revisão, instante de corte, resultado e exceções; informar pelo Portal ao cliente que a troca foi concluída ou não, sem anunciar sucesso antes da evidência.
9. **Falha e contingência:** manter formulários afetados bloqueados durante estados incertos; recuperar a configuração anterior **somente** se autorização antiga continuar vigente e nova revisão segura for formalmente aplicada, não por rollback implícito/automático. O rollback automático AGI-11-RB01 segue desabilitado.

**Disponibilidade:** poderá haver uma breve interrupção de novas captações na troca; a especificação futura deve definir limites, comunicação e mitigação. **É proibido mascarar indisponibilidade com envio para destino não autorizado.**

### C — Dupla escrita provisória nos dois CRMs durante transição

Enviar novos formulários a ambas as contas por um período para evitar interrupção.

**Não recomendada:** amplia acesso a dados pessoais, duplica registros e contradiz a autorização específica de destino; incompatível sem nova fundamentação e consentimentos expressos. Não pressupor que a troca do vínculo autorize dupla escrita.

## 3. Identidade da versão e corte temporal

- A autoridade AUT-P01 atribui revisão monotônica do vínculo. **Submissões novas** só podem ser aceitas sob revisão oficialmente autorizada.
- **Antes do corte:** submissões já aceitas têm destino original e T0 preservados.
- **Durante barreira:** formulário pode permanecer visualizável, mas backend não recebe novas submissões afetadas até autorização inequívoca; exibir erro/estado apropriado, nunca confirmação falsa.
- **Depois do corte:** somente novo destino autorizado recebe submissões novas. Uma página em cache não pode impor o CRM antigo.
- Em caso de corrida entre recepção durável e troca, o registro persistido deve comprovar a revisão efetivamente validada no instante de aceite; a regra de serialização/ordenação será especificada antes da implementação.
- Se o destino antigo mantiver autoridade para receber submissões antigas já autorizadas, eventual entrega continua sujeita a IN-127. Se revogada, não executar nova entrega sem base válida; não redirecionar ao novo destino. Preservar estado inconclusivo quando aplicável e excluir payload no prazo IN-127 com as ressalvas ratificadas.

## 4. Estado do chamado — nomes candidatos (NÃO homologados)

`solicitado` → `em_validacao` → `preparado` → `bloqueio_em_andamento` → `bloqueio_confirmado` → `ativando_novo_vinculo` → `verificacao` → `concluido`.

Saídas excepcionais propostas: `rejeitado`, `aguardando_autorizacao`, `falha_segura`, `intervencao_humana`. Esses nomes são **candidatos documentais**: não constituem enums, esquemas ou APIs aprovados.

## 5. Critérios para futura especificação e homologação

- Evidência de titularidade e autorização em ambas as contas; princípios de mínimo privilégio e autenticação.
- Referência imutável ao chamado, site, formulários, antigo/novo `crm_account_id`, versão anterior/posterior, executante e prova das barreiras.
- Comando idempotente e concorrência segura quando houver duas solicitações de troca, cancelamento ou suspensão simultâneos.
- Bloqueio comprovado nas duas rotas, sem aceitar com revisão anterior após revogação; falha fechada quando autorização não puder ser comprovada.
- Nenhuma alteração/migração de leads já entregues; pendências mantêm T0, destino e prazos originais.
- Reavaliação correta das duas cortesias, respeitando contratos CRM pagos.
- Diferenciar ativação de configuração, efetividade de captação e confirmação oficial de entrega.
- Plano de aviso ao cliente, recuperação segura e auditoria, sem invocar rollback automático AGI-11-RB01.
- Custos, tempo de bloqueio, estratégia de atomicidade/compensação, TTL/frescor e transportes/eventos ficam para contrato técnico posterior e nova aprovação.

## 6. Fontes verificadas

- [IN-122 — múltiplos sites, contas e cortesia](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-122-multiplos-sites-contas-crm-cortesias.md).
- [IN-123 — painel e chamados de integração](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-123-painel-integracoes-sites-crm.md).
- [IN-124 — integração e mudança de vínculo](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-124-integracao-inteligente-formularios-leads.md).
- [IN-149 — confirmação e idempotência](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-149-protocolo-entrega-confirmacao-leads.md).
- [IN127-R01 a R06 — ratificações](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).
- [AUT-P01 alternativa B — ratificada](P0-DOC03-AUT-P01-RATIFICACAO-ALTERNATIVA-B-2026-11-03.md).
- [AUT-P02 alternativa B — aprovada](AUT-P02-aprovacao-alternativa-B-2026-11-04.md).

## 7. Decisão solicitada — SOMENTE AUT-P03

**Recomendação:** escolher **Alternativa B**, troca por fases com validação de ambas as contas, barreira de revogação nas duas APIs, mudança versionada, ativação confirmada, verificação, reavaliação de cortesia e falha fechada. Sem migração automática, dupla escrita ou redirecionamento de envios antigos.

**Status:** AUT-P03 apresentada como PROPOSTA, não ratificada. AUT-P01 B e AUT-P02 B permanecem aprovadas. AUT-P04 a AUT-P10 continuam pendentes. Nenhuma execução real autorizada.
