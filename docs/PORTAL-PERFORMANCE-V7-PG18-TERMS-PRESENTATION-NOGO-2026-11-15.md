# Imagine Portal Performance V7 — recibo de apresentação dos termos em PostgreSQL18

**15/11/2026. Laboratório CI, sem interface de cliente, cobrança, deploy ou aprovação de produção.**

## Problema

O Portal F831 (PR #831) já possui eventos de aceite, revogação e reaceite vinculados ao ator da sessão de negócios com MFA e grant `services.manage`. Contudo um booleano `express=true` vindo de uma simulação **não demonstra que a versão exata dos termos tenha sido mostrada antes da ação**. A assinatura comercial HMAC também não é prova de apresentação nem de contrato.

## Fluxo F832 (somente PostgreSQL18 descartável)

O novo conjunto de funções `portal_v7_terms_register_presentation_lab`, `portal_v7_terms_accept_with_receipt_lab` e `portal_v7_terms_current_with_receipt_lab` exige:

- **Registro de apresentação antes do aceite:** recibo aleatório UUID, válido por até três minutos, associado ao `SHA256` da mesma sessão Portal de laboratório, identidade/organização derivadas da sessão SQL, empresa, acordo, competência, revisão do acordo, revisão anterior do consentimento, fingerprint de plano e versão exata dos termos.
- **Aceite explícito com vínculo verificável:** o recibo é confirmado somente na mesma sessão ainda válida, MFA atualizado, permissão `services.manage` vigente, mesmos termos/plano e revisão do estado atual. Um lock transacional por acordo/competência serializa aceites concorrentes. A função de aceite F831 e o vínculo imutável ao recibo são gravados na mesma transação de banco.
- **Consulta do estado vigente:** o último evento de aceite precisa estar `synthetic_accepted` **e** ligado ao recibo correspondente à sessão e à versão comercial correta. Um aceite legado sem recibo não passa por este predicado mais estrito.
- **Privilégios mínimos:** tabelas de apresentação e vínculo de evento em FORCE RLS, sem SELECT/INSERT/UPDATE/DELETE ao login de laboratório; somente funções SQL SECURITY DEFINER executáveis por role controlada. Nenhuma rota, chamada PSP, anúncio ou CRM é criada.
- **Testes adversariais:** tentativa sem recibo, ausência de confirmação expressa, desafio inválido, sessão/tenant alheios, termos ou valores alterados, revisão anterior obsoleta, duplo clique e replay, recibo expirado, revogação posterior, novo aceite com novo recibo, logout, alteração de versão dos termos, RLS, uso de perfil SQL sem permissão e modo produção desativado.

## Limite essencial

Esta prova registra apenas que **um script de laboratório solicitou um recibo de apresentação**. Não comprova HTML efetivamente renderizado no navegador, acesso de pessoa real, leitura dos termos, clique expresso autêntico nem validade jurídica. SHA256 da sessão equivale a credencial e não deve sair do ambiente protegido. HMAC comercial não incorpora recibo de IAM atestado nem confere autorização de execução. A janela de revogação entre verificação e ação permanece.

Para homologação operacional ainda são necessários: interface de apresentação de termos e checkbox com registro de versão e texto/hash, sessão real protegida por CSRF e cookies, MFA verificado, trilha de auditoria minimizada, canal S2S autenticado, política de revogação e trava de execução, revisão SEG/DBA/LGPD/Financeiro e ensaios PSP/Ads em sandbox.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE / NO-CRM-WRITE.**
