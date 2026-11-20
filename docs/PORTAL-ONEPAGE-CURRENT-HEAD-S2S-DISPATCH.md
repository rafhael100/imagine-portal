# Onepage Portal ↔ Sites — dispatcher privado candidato (somente laboratório)

Data: 19/11/2026. Branch isolada sobre Portal #1001 (HEAD imutável `51cc13a3baf1ac7c95fce226378296d9a7d76022`) e Sites #552 (HEAD imutável `2d8f9cd639dde4ba016220383b6dfcbccc103a2c`). Sem modificações nas branches das equipes.

## Entrega

`OnepageCurrentHeadS2sDispatchCandidate::dispatch()` encadeia o parser canônico de cinco campos da requisição Sites e o compositor Portal de head corrente, passando **exclusivamente** o identificador de serviço entregue pelo host de transporte confiável, nunca uma identidade dentro do JSON do comprador. Validar estrutura de requisição não é validar autorização: o testemunho S2S e os leitores do Portal permanecem independentes e, por padrão, **negam** todas as solicitações.

Os testes cruzam os bytes realmente gerados pelo encoder Sites com o parser Portal, testemunho de serviço, cinco fontes sintéticas e a interpretação de resposta pelo cliente Sites. Rejeitam payload adulterado, campos extra, schema/produto trocados, tenant estrangeiro, identidade indevida, contrato revisto e revogação entre as duas leituras. Erros não expõem o motivo privado. O trabalho deixa o contrato pronto para integrar uma futura rota privada, mas não cria tal rota.

## Bloqueios de operação

- Não existe controller/rota HTTP, autenticação real de peer mTLS, authorization binding ao tenant ou infraestrutura S2S homologada. `verifiedPeerServiceRef` é **parâmetro confiado** e exigirá origem externa comprovada; qualquer uso vindo de header/body seria inseguro.
- Os leitores de briefing aprovado, última revisão, revogação e aceite são fontes sintéticas ou interfaces negadas por padrão, sem transação oficial/CAS, auditoria persistente nem bloqueio de replay.
- Teste com datasets sintéticos não valida autorização operacional, identidade do cliente nem atos de aprovação. Produto Landing Page deve ter contrato próprio, não se encaixa em `onepage`.
- O Portal conserva titularidade de pedido/contrato/aceite e evento ENTREGUE. Sites conserva produção técnica e publicação, sem conceder autorização automática.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-GO.** Dez gates operacionais bloqueados. Sem VPS, produção, main, Cloudflare, DNS, Asaas, custos ou credenciais. Próxima etapa: fonte oficial autenticada/transacional do briefing aprovado e versionado no Portal, com auditação e revogação.
