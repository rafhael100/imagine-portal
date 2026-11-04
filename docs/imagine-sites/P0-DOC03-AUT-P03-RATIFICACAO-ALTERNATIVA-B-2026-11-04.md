# AUT-P03 — Ratificação expressa da alternativa B: troca controlada de vínculo Site × CRM

**Estado:** RATIFICADA EXPRESSAMENTE pelo responsável do projeto Imagine em 04/11/2026.  
**Frente:** P0-DOC03 — contratos e autoridades Portal / Imagine Sites / CRM.  
**Escopo:** decisão normativa/documental. **Não** equivale a implementação, teste, homologação, implantação nem autorização de programação.  
**Precedência preservada:** AUT-P01 alternativa B, AUT-P02 alternativa B, IN127-R01 a R06, IN-118/119/120/121/122/123/124/149 e políticas de segurança pertinentes.  
**AUT-P04 a AUT-P10:** PENDENTES. **P0-DOC03 e RF-PEND-01:** ABERTOS. **RF-PEND-04:** sem homologação técnica. **AGI-11-RB01:** DESABILITADO.

## 1. Aprovação expressa recebida

> Aprovo a AUT-P03 pela alternativa B. Ratifico a troca controlada por chamado e equipe Imagine, com validação das contas, barreira segura nas duas APIs, nova revisão versionada, ativação e verificação do destino, sem redirecionamento de leads antigos nem migração automática de dados. Registre exclusivamente a documentação no GitHub, preserve IN127-R01 a R06 e as decisões anteriores, mantenha AUT-P04 a AUT-P10 pendentes, sem programação, testes, implantação ou homologação, e com AGI-11-RB01 desabilitado.

A manifestação ratifica a **alternativa B** apresentada na [minuta AUT-P03](P0-DOC03-AUT-P03-TROCA-CONTROLADA-VINCULO-CRM-PROPOSTA.md), sem transformar parâmetros de implementação ainda abertos em contratos técnicos homologados.

## 2. Regras normativas ratificadas

1. **Abertura de chamado:** a troca posterior de CRM vinculado exige solicitação registrada pelo Portal Imagine, associada ao site e ao destino pretendido. O cliente não altera diretamente a conta de destino.
2. **Execução autorizada:** somente equipe Imagine com permissão adequada executa a mudança, após validação da solicitação, titularidade e autorização das contas antiga e nova, e direito de receber leads.
3. **Serviço gratuito e delimitado:** a Imagine faz gratuitamente a desvinculação e a nova integração técnica. **Migração entre contas não está incluída**; cabe ao cliente usar exportação/importação e backups próprios do CRM, conforme permissões.
4. **Preparação e versão:** a troca cria uma nova revisão oficial e auditável do vínculo Site × CRM; não modificar o estado efetivo antes das validações requeridas.
5. **Barreira de revogação/troca:** impedir que novas submissões sejam aceitas pelas APIs principal ou contingente com revisão antiga quando não autorizada. Durante o corte, negar temporariamente novas aceitações afetadas ou aplicar mecanismo comprovadamente equivalente de segurança.
6. **Falha fechada:** se qualquer rota não puder comprovar a autorização e revisão aplicáveis, bloquear novas recepções naquela rota, sem T0 nem resposta falsa de sucesso. Indisponibilidade de sincronização não permite encaminhar à conta anterior por conveniência.
7. **Ativação confirmada:** habilitar a nova autorização de captação apenas quando o novo destino estiver validado, a revisão oficial vigente for reconhecida pelas rotas autorizadas e o estado for consistente. Não anunciar conclusão por simples gravação de configuração.
8. **Verificação pós-ativação:** conferir direcionamento efetivo para a conta correta e registrar evidências técnicas suficientes; critérios, ambientes e método de teste serão detalhados e homologados apenas em etapa posterior.
9. **Cortesia e contratos independentes:** reavaliar elegibilidade do benefício CRM Básico nas contas de origem e destino sem duplicidade, suspensão indevida de CRM pago ou cobrança automática.
10. **Comunicação e auditoria:** histórico vincula chamado, responsáveis, identidades e permissões verificadas, revisão anterior/nova, etapas, falhas, confirmação e comunicação ao cliente.
11. **Sem dupla escrita ou encaminhamento implícito:** novos leads destinam-se somente à conta CRM oficialmente autorizada, não às duas contas em paralelo nem a um canal alternativo.
12. **Recuperação controlada:** falha durante a troca não restaura automaticamente o vínculo antigo nem ativa o rollback automático AGI-11-RB01. Qualquer recuperação exige autorização válida e revisão segura; operações críticas seguem a matriz de alçadas já aprovada.

## 3. Leads anteriores à troca — proteção integral da IN-127

- **Leads entregues anteriormente:** permanecem na conta CRM original, sem migração ou exclusão automática por alteração do vínculo.
- **Submissões já aceitas e ainda pendentes:** preservam `submission_id`, instante T0 original, versão de autorização e destino original; **nunca redirecionar automaticamente** ao novo CRM.
- Quando o antigo destino segue autorizado para aquela operação originalmente recebida, a entrega/reconciliação continua condicionada às regras IN127-R01–R06.
- Quando houver revogação ou incerteza sobre a autorização antiga, nenhuma nova transmissão pode ocorrer sem autorização válida. Isso **não** autoriza reiniciar os prazos nem deslocar leads para nova conta: encerrar conforme limite contínuo de **432h desde T0**, preservando regras de descarte, evidência mínima e resultados inconclusivos.
- A redação contratual detalhada para submissões pendentes e transições permanece vinculada à **AUT-P06 ainda pendente**; a aprovação da AUT-P03 não encerra essa matéria.

## 4. Ordem lógica ratificada

**Chamado formal → validações de identidade/contas e direitos → preparação versionada → barreira segura nas duas APIs → ativação da revisão nova → verificação do destino → reavaliação de benefícios → conclusão auditável e comunicação.**

Isso é uma **sequência normativa**, não um workflow executado ou um contrato de API já escolhido.

## 5. Detalhamento técnico explicitamente pendente

- Estados/enums definitivos, contratos de comandos e eventos, serviços responsáveis por transições, assinatura/autenticação, limites de frescor e versão.
- Atomicidade, concorrência, compensação, sincronização, tratamento de indisponibilidade parcial, tempos de indisponibilidade e notificações.
- Critérios de confirmação da integração, testes sintéticos, prova de bloqueio das rotas e evidências de homologação.
- Interação com cancelamentos, suspeitas de fraude, revogações de titularidade e autorização de submissões antigas.
- Contratos AUT-P04 a AUT-P10, ainda não ratificados, e os portões P0/RF relacionados.

## 6. Situação consolidada

| Item | Situação |
|---|---|
| AUT-P01 | **Ratificada, alternativa B** |
| AUT-P02 | **Aprovada, alternativa B** |
| AUT-P03 | **Ratificada expressamente, alternativa B** |
| AUT-P04 a AUT-P10 | **PENDENTES** |
| P0-DOC03 | **ABERTO**; restam contratos e autoridades |
| RF-PEND-01 | **ABERTA**; conferência integral não concluída |
| RF-PEND-04 | **Sem homologação técnica** |
| Programação, testes e implantação | **NÃO autorizados** |
| AGI-11-RB01 | **DESABILITADO** |

## 7. Fontes e rastreabilidade

- [AUT-P03 — proposta histórica e alternativas A/B/C](P0-DOC03-AUT-P03-TROCA-CONTROLADA-VINCULO-CRM-PROPOSTA.md).
- [AUT-P01 — ratificação B](P0-DOC03-AUT-P01-RATIFICACAO-ALTERNATIVA-B-2026-11-03.md).
- [AUT-P02 — aprovação B](AUT-P02-aprovacao-alternativa-B-2026-11-04.md).
- [IN127-R01–R06](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).
- [IN-122 — múltiplos sites e contas](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-122-multiplos-sites-contas-crm-cortesias.md); [IN-123 — painel e chamados](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-123-painel-integracoes-sites-crm.md); [IN-124 — formulários](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-124-integracao-inteligente-formularios-leads.md).

**Esta aprovação é exclusivamente documental e não encerra as pendências técnicas de AUT-P01/AUT-P02/AUT-P03.**
