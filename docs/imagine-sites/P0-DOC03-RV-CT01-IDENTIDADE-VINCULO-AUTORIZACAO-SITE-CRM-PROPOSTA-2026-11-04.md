# RV-CT01 — Identidade e autorização Site × CRM

**Estado: PROPOSTA PENDENTE DE APROVAÇÃO.** Apenas planejamento, 04/11/2026.

**Referências vigentes:** AUT-P01/P02/P03/P06, alternativas B; IN127-R01–R06 (R02=A; 432h); IN-122/123/124; [consolidação RV01](P0-DOC03-RV01-CONSOLIDACAO-AUT-P01-P10-2026-11-04.md).

## Alternativas

**A — Consulta central síncrona:** cada API consulta um único serviço em toda submissão. Vantagem: simplicidade; risco de dependência comum entre principal e contingência.

**B — Fonte oficial versionada, verificação por rota e bloqueio na mudança (RECOMENDADA, NÃO APROVADA):** registrar o vínculo empresa/site/formulário/CRM e direitos de captação; as duas APIs devem verificar autenticamente uma autorização vigente antes de confirmar cada recebimento. Quando a revisão ou a autorização for incerta, suspender novas recepções afetadas. A troca de CRM é coordenada e verificada. Mecanismos concretos dependem de escolha posterior.

**C — Aceite por informação antiga do formulário:** permitir captação com destino guardado na página ou cache sem verificar estado atual. Não recomendada.

## Regras fundamentais para a alternativa B

1. Identidades estáveis de empresa, site, formulário, contrato, conta CRM e revisão do vínculo; o visitante não escolhe o destino CRM.
2. Validação obrigatória no backend principal **e** de contingência, inclusive quando o site está em cache. A ausência de destino autorizado bloqueia formulários; não impede publicação estática permitida.
3. Revisão revogada, divergente ou não comprovada impede nova recepção. Falha de verificação não gera T0 ou resposta falsa de sucesso.
4. CRM temporariamente indisponível, mas oficialmente autorizado, permite recepção durável pela API operante conforme IN127 e IN-124.
5. Troca posterior de CRM por chamado e equipe Imagine: validar solicitante e contas, preparar revisão, impedir novas recepções pela versão antiga, ativar e verificar nova revisão. Não migrar dados nem redirecionar leads antigos.
6. Recebimentos anteriores mantêm destino, T0 e identidade originais. A entrega posterior ao CRM original depende de autorização específica AUT-P06 e do limite contínuo de 432 horas.
7. API principal e contingência têm persistência durável independente. O mesmo envio mantém identificação correlacionável para evitar duplicidade.
8. Separar direito de captar, disponibilidade CRM, contrato do site, benefício Básico cortesia e CRM pago. Agentes de IA não criam autorização de destino.
9. Registrar mudanças, revisões, decisões e resultados com proteção de dados; sem armazenar payload pessoal nos logs.

## Parâmetros ainda não definidos

Sem homologação de endpoints, esquemas, autenticação, tempo de atualização, sincronização da autorização, controle de concorrência, estados técnicos, idempotência entre APIs, retenção da auditoria, testes, custos ou infraestrutura. Uma eventual aprovação da alternativa B aprova somente a diretriz, **não** esses detalhes.

**Situação:** AUT-P01–P10 aprovadas B; RV-CT01 pendente; RV-CT02–08 pendentes; P0-DOC03 e RF-PEND-01 abertos; RF-PEND-04 não homologada; AGI-11-RB01 desabilitado. Sem programação, testes, implantação, compras ou alteração operacional.

**Pergunta de deliberação:** aprovar a **RV-CT01 pela alternativa B** como base documental para detalhamento técnico posterior?
