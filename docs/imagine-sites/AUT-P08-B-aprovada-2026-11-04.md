# AUT-P08 — Alternativa B aprovada: estados oficiais de publicação e entrega

**Aprovação:** resposta "ok" à pergunta imediatamente anterior "Você aprova a AUT-P08 pela alternativa B?", em 04/11/2026. Aprovação funcional/normativa da [proposta AUT-P08](P0-DOC03-AUT-P08-ESTADOS-PUBLICACAO-RECUPERACAO-ENTREGA-PROPOSTA.md), não homologação de contratos de estados, endpoints, enumerações, testes ou implantação.

## Decisão aprovada

Adotar **estados separados, associados à autoridade competente e comprovados por evidências**, em vez de um estado único ou conclusão presumida pelo agente:

1. **Produção/aceite:** versão identificada por manifesto, com decisão expressa do cliente autenticado/autorizado quando exigida, conforme AUT-P04 B e PORTAL-D01.
2. **Publicação técnica:** distinguir comando solicitado, processamento, resultado inconclusivo, falha e versão **efetivamente implantada e verificada**. Resposta de CDN, deploy ou execução n8n isolada não confirma publicação correta.
3. **Estado operacional:** verificar disponibilidade, degradação, indisponibilidade, incidentes e recuperação separadamente do fato histórico da publicação. Falha na versão B não apaga uma versão A ainda comprovadamente servida.
4. **Entrega oficial:** o Portal registra evento **ENTREGUE** somente depois de o agente verificador autorizado comprovar checklist integral por produto/escopo, versão publicada correta, aceite exigido, requisitos de segurança/LGPD, evidências válidas e ausência de bloqueadores, conforme PORTAL-COM-13. **Não exigir clique administrativo universal** nem permitir que IA substitua aceite do cliente ou aprovação humana de ação crítica.
5. **Evento idempotente e REL-01:** a entrega oficialmente confirmada inicia o **Dia 0** da jornada de relacionamento. Reexecução do checklist, republicação, atualização ou recuperação não geram automaticamente outro evento de primeira entrega nem reiniciam a REL-01.
6. **Incidentes posteriores:** preservar a prova e data da entrega histórica; registrar estado operacional atual, incidente, reparo e recuperação verificada. Não apagar a entrega retroativamente, nem declarar recuperado só porque um comando terminou.
7. **Aceite e revisões:** mudança material do artefato requer nova avaliação e aceite quando aplicável; não usar a aprovação da versão anterior para comprovar versão nova.
8. **Site estático sem CRM:** pode ser publicado e entregue se contrato/aceite/checklist permitirem; formulários públicos permanecem inativos e bloqueados no backend até existir destino CRM autorizado (IN127-R06 e AUT-P01/02).
9. **Privacidade:** para sites simples elegíveis, preservar a regra **COM-15 seção 14**, com Política de Privacidade padrão revisada/adaptada e publicada sem clique individual prévio de aprovação de seu texto; isso não substitui o aceite do site nem os requisitos específicos de tratamento, consentimento ou revisão jurídica.
10. **Autoridades independentes:** contrato/pagamento, publicação técnica, saúde operacional, entrega oficial, autorização de captação e persistência de lead CRM têm suas próprias fontes; agentes e orquestradores não inventam efeitos ou confirmações.
11. **Falha fechada e auditoria:** ausência de evidência, inconsistência entre sistemas, falha de checklist ou autorização pendente impede declaração indevida de publicação, recuperação ou entrega. Registrar revisão, fonte, ator, momento, provas e decisões de exceção.
12. **Recuperação controlada:** observância de alçadas e playbooks homologados quando futuramente autorizados; **AGI-11-RB01, rollback automático, permanece DESABILITADO**.

Alternativa A (estado único) e C (IA/n8n autoridade única) não foram escolhidas.

## Delimitação técnica e comercial

Continuam pendentes os enums, esquema e contratos de eventos, mecanismos de prova em CDN/cache, verificadores, idempotência e concorrência, tempos/SLAs, critérios de saúde, política de reentrega e eventual correção da REL-01, integração com fontes financeiras, regras de contingência, auditoria e homologação. O registro não publica sites, não altera estado real de projetos nem habilita agentes.

**AUT-P01 a AUT-P08:** alternativas B aprovadas normativamente. **AUT-P09 e AUT-P10:** pendentes. **P0-DOC03 e RF-PEND-01:** abertos. **RF-PEND-04:** não homologada. **AGI-11-RB01:** desabilitado.

**Somente documentação.** Não autorizadas programação, testes, publicação real, implantação, homologação, contratação, modificação de infraestrutura ou execução de rollback.

Referências: [proposta AUT-P08](P0-DOC03-AUT-P08-ESTADOS-PUBLICACAO-RECUPERACAO-ENTREGA-PROPOSTA.md); [COM-13](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-13-entrega-automatica-checklist-qualidade-agente-ia.md); [REL-01](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-REL-01-jornada-pos-entrega-onboarding-30-dias.md); [COM-15](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-15-central-documentos-legais-consentimentos.md).
