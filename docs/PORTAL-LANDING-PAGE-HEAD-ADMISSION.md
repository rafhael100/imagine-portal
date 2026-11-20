# Landing Page — admissão S2S preliminar (somente draft)

Dependências: Sites #541/#542, Sites #553 e Portal #1004, com código base pinado no commit 38564372940e6ca827d5559be9fc4537c52d1216 e encoder Sites 26c64e4046a7e6e286676d1620c9af8a20d721aa.

A fronteira LandingPageCurrentHeadAdmissionCandidate interpreta o pedido canônico separado do Onepage. Antes de qualquer acesso privado valida o formato e uma referência de serviço, cuja identidade **deve** vir de mTLS verificado fora da aplicação. Usa testemunha separada para Landing Page e a fonte de contrato autorizada do Portal; verifica tenant, pedido e tipo de produto, relendo testemunha e contrato para identificar mudanças. O padrão é recusar. O retorno tem apenas seletores e referência/revisão de contrato, não briefing nem permissão de publicação.

**Limites:** uma string de serviço não autentica mTLS; os mocks de testes não são fontes oficiais. Duas leituras não são transação/CAS. Falta a fonte oficial persistente e auditável de briefings Landing Page, confirmação autenticada de aprovação vigente e revogação, ACL vinculada a site e tenant, transporte privado, replay, revisão formal, artefato, publicação técnica homologada e aceite. A identidade visual Imagine não é alterada. Os dez gates operacionais continuam bloqueados, inclusive autorização comercial e pagamento.

**Política:** sem merge, deploy, alteração de main, VPS, produção, Cloudflare, DNS, credenciais, Asaas ou contratação de fornecedores. Os testes offline fornecem apenas evidência de contrato candidato; não representam entrega operacional.
