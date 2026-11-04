# AUT-P01 — Ratificação expressa: Alternativa B (vínculo Site × CRM)

**Status:** **RATIFICADA EXPRESSAMENTE PELO RESPONSÁVEL** no planejamento Imagine Sites.  
**Data de referência:** 03/11/2026.  
**Âmbito:** P0-DOC03 — autoridade do vínculo Site × CRM, Captação principal e contingência.  
**Natureza:** aprovação **normativa/arquitetural**, somente documentação. Não aprova programação, testes, implantação, fornecedores nem operação real.  
**P0-DOC03:** ABERTO. **AUT-P02 a AUT-P10:** PENDENTES, sem ratificação por arrastamento.  
**RF-PEND-01:** ABERTA. **RF-PEND-04:** homologação técnica NÃO realizada. **AGI-11-RB01:** rollback automático DESABILITADO.

## 1. Manifestação expressa de ratificação

> Ratifico expressamente a AUT-P01 pela alternativa B, com registro oficial versionado do vínculo Site × CRM, validação autenticada por ambas as APIs, proteção contra estados antigos, barreira de revogação e bloqueio seguro quando não for possível comprovar autorização vigente. Manter os parâmetros e contratos detalhados sujeitos a especificação e aprovação próprias. Preserve IN127-R01 a R06, mantenha demais AUT-P02 a AUT-P10 pendentes e avance somente para AUT-P02. Atualize exclusivamente a documentação no GitHub, sem programação, testes, implantação ou homologação, e mantenha AGI-11-RB01 desabilitado.

A mensagem acima é o teor da aprovação recebida, sem conversão em aprovação de outras decisões.

## 2. Decisão consolidada — Alternativa B

1. **Registro oficial do vínculo:** manter vínculo Site × CRM em autoridade própria versionada, com identificação segura e auditável da empresa, do site/formulário, da conta CRM de destino, do escopo de autorização e da revisão vigente.
2. **Ambas as APIs:** Captação principal e API de contingência somente poderão aceitar novas submissões após validar **autenticamente** a autorização relevante, com verificação obrigatória no backend.
3. **Estados antigos:** rejeitar provas de autorização revogadas, versões antigas, fora de ordem, sem integridade ou sem validade verificável. Frontend, formulário em cache e identificador enviado pelo visitante não estabelecem autorização.
4. **Barreira de revogação:** troca, suspensão que revogue autorização e revogação devem impedir novas aceitações indevidas **nas duas rotas**, com confirmação segura do bloqueio ou retirada/falha fechada da rota que não puder comprovar o estado aplicável.
5. **Falha fechada:** quando a autorização vigente não puder ser comprovada, o backend **não aceita o formulário como submissão durável válida**, não inicia T0 e não mostra confirmação falsa. A impossibilidade de verificar autorização nunca autoriza encaminhamento implícito ao Portal, e-mail ou outra conta CRM.
6. **Indisponibilidade distinta:** falha temporária do **CRM de entrega**, enquanto o destino permanece oficialmente autorizado e a autorização é validável, não equivale à revogação e deve ser tratada conforme IN127-R01 a R05, usando recepção durável e fila conforme políticas.
7. **Sem migração ou redirecionamento automático:** mudança de vínculo depende de chamado e ação autorizada Imagine; não migrar ou redirecionar silenciosamente leads pendentes/entregues entre contas.
8. **Agentes e n8n:** podem orquestrar etapas permitidas, mas não criar, atestar ou ampliar autorizações de destino por inferência.

## 3. Escopo de autorização e dependências

- A escolha da alternativa B **não define automaticamente** tecnologias concretas, número de serviços, bancos, protocolo de assinatura, TTL, valores de frescor, esquema de eventos, limites de propagação, retries, interface administrativa, física de servidores nem orçamento. Tudo isso exige especificação e aprovação próprias.
- O registro versionado da autoridade deve ser confrontado com o contrato de existência/titularidade da conta no CRM, as permissões da empresa, o estado do site, a situação financeira e as decisões AUT-P02/AUT-P03 seguintes.
- O mecanismo não pode alegar **revogação instantânea garantida** se alguma API isolada ainda puder aceitar com snapshot antigo. A regra ratificada é **bloqueio seguro/provável** quando não for possível demonstrar a autorização vigente.
- Identidade, versionamento, idempotência e concorrência técnica exigem fechamento de contratos e homologação futura.
- Os requisitos IN127-R01 a R06 permanecem intactos, inclusive **T0 imutável, janelas de 72h + 360h = 432h, descarte de payload, auditoria mínima de até 90×24h, reconciliação e site estático sem CRM com formulários bloqueados**.
- As políticas comerciais de cortesia, contas CRM pagas e suspensão de site **não são modificadas pela AUT-P01**. Seu relacionamento e direito de captação são precisamente o objeto **pendente** da AUT-P02.

## 4. Alternativas encerradas nesta matéria

| Alternativa | Estado |
|---|---|
| **A — Consulta online a cada submissão** | Não escolhida |
| **B — Registro oficial versionado e validação autenticada local em ambas as rotas com proteção contra obsolescência/revogação** | **RATIFICADA** |
| **C — Destino informado pelo frontend** | Não escolhida; inadequada frente a IN127-R06 |

## 5. Pendências mantidas

| Código | Situação após ratificação |
|---|---|
| **AUT-P01** | **DECISÃO NORMATIVA FECHADA pela alternativa B**; contratos detalhados e homologação NÃO encerrados |
| **AUT-P02** | **Próxima matéria para deliberação**, sem aprovação automática |
| AUT-P03 a AUT-P10 | Pendentes, não antecipar |
| P0-DOC03 | Aberto, pois há outras autoridades e contratos pendentes |
| RF-PEND-01 | Aberta, fontes e precedências ainda em conferência |
| RF-PEND-04 | Norma IN-127 aprovada, homologação técnica pendente |

## 6. Rastreabilidade

- [AUT-P01 — proposta histórica com A/B/C](P0-DOC03-AUT-P01-CONTRATO-VINCULO-SITE-CRM-PROPOSTA.md).
- [P0-DOC03 — matriz de autoridades](P0-DOC03-AUTORIDADES-PORTAL-SITES-CRM-MATRIZ-2026-11-03.md).
- [IN127-R01 a R06 — registro ratificado CRM Imagine](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).
- [IN-122](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-122-multiplos-sites-contas-crm-cortesias.md) e [IN-124](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-124-integracao-inteligente-formularios-leads.md).

**Registro definitivo de aprovação normativa; não comprova implementação nem encerra os portões restantes.**
