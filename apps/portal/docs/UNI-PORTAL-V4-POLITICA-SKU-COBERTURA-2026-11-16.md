# PORTAL-UNI V4 — SKU, vigência e revogação (candidata não autorizadora)

**16/11/2026 — PR DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE.**

## Objetivo
Evoluir os preflights V2/V3 do Portal para impedir que apenas o campo `active` ou `eligibleUnderContract` seja tratado como prova comercial suficiente para a Universidade Imagine. A Universidade é um **benefício vinculado** a um serviço Imagine elegível realmente ativo, não um produto avulso.

## Quatro fontes que a integração futura precisa comprovar separadamente
1. **Contratos/serviços atuais, por organização** — identidade do serviço, produto, estado atual, revisões, vigência e fonte autenticada.
2. **Revogação/revisão independente** — veto e versão monotônica de cada serviço, atualizada quando suspender/cancelar o último serviço.
3. **Política comercial aprovada, versionada** — SKUs realmente elegíveis por produto, janela de efetividade e revisão aprovada por responsável comercial/jurídico.
4. **Cobertura de vigência por serviço** — ID do serviço e empresa, mesmo produto, SKU, revisão de contrato e política, início/fim do período reconhecido.

As quatro fontes **não estão conectadas**: interfaces atuais retornam `null` por padrão. Classes de snapshot são construíveis pelo chamador e, mesmo com todos os campos plausíveis, **não provam pagamento, origem oficial, assinatura ou direito de acesso**.

## Implementação V4
- `UniversityCommercialEligibilityPreflight` lê cada fonte uma única vez e reconcilia as revisões com a V3 usando snapshots congelados.
- Uma divergência de tenant, revisão, produto, SKU, janela de vigência ou status do último serviço cancela o lote inteiro: **fail-closed**.
- A fonte de cobertura deve apresentar exatamente um registro para cada serviço ativo qualificador; referências extras/duplicadas são recusadas.
- Política vazia é válida como cadastro diagnóstico, mas nenhum SKU pode qualificar. A Universidade não é aceita como serviço ou SKU.
- Cobertura válida por instantes no futuro ou vencida, políticas vencidas ou ainda não efetivas e fontes desatualizadas são recusadas.
- O ID genérico do Portal admite até 128 caracteres, mas a Universidade apenas 80: o preflight bloqueia ID incompatível sem truncá-lo, criando uma pendência explícita de integração.
- O resultado `UniversityCommercialEligibilityResult` retorna **sempre** `mayIssuePortalGrant() === false` e `provesPaidContract() === false`, inclusive no caso `candidate_pending_official_authority`.

## Critérios de homologação que NÃO estão implementados
- Aprovação da lista real de SKUs elegíveis e proprietário da autoridade comercial, sem presumir que todos os planos/créditos/trials qualificam.
- Determinar regra auditável de primeiro mês parcial, inadimplência, reembolso, grace period, suspensão e retorno de serviço; utilizar ledger oficial e revisões, não apenas o evento de um pagamento.
- Fonte autenticada backend-to-backend e atualmente verificável, comunicação privada com timeout, mTLS ou outro mecanismo aprovado e rotação de credenciais.
- Prova de sessão empresarial MFA, tenant e sujeito, permissão `university:view` restrita; `university:manage` somente à equipe editorial.
- Revisão independente AppSec/DBA/LGPD, teste de race/timeout, revogação simultânea, backup e rollback na VPS privada.

## Ensaios reprodutíveis (somente sintéticos)
```bash
cd apps/portal
vendor/bin/phpunit tests/Unit/UniversityCommercialEligibilityPreflightTest.php
vendor/bin/phpunit tests/Unit/UniversityRevocationPreflightTest.php
```

Os testes incluem um serviço ativo com cobertura, dois serviços com cancelamento parcial, cancelamento do último, trial/suspensão, SKU não aprovado, produto e revisão divergentes, política vencida, empresa cruzada, fonte fora do ar e leitura única de cada fonte. Nenhum deles cria grants operacionais.

## Dependências e rastreabilidade
- [Portal #886](https://github.com/rafhael100/imagine-portal/issues/886): fonte oficial e revogação, bloqueador P0.
- [Portal PR #882](https://github.com/rafhael100/imagine-portal/pull/882): base V3 com revogação.
- [Portal PR #902](https://github.com/rafhael100/imagine-portal/pull/902): V4 de cobertura e política.
- [Universidade PR #81](https://github.com/rafhael100/imagine-universidade/pull/81): consumidora com 10 fluxos de escrita e provider `UnavailablePortalAuthority` (deny-all).

**Decisão atual: HOLD / NO-GO.** Não alterar `OrganizationAuthorizationService`, migrações reais, perfis dos clientes, pagamento ou SSO para reconhecer essas candidatas como autorização.


## V4.1 — latência total, tempo final e interoperabilidade (16/11/2026)
- A leitura das quatro autoridades tem orçamento conjunto padrão de **1.500 ms** (faixa validada 1–5.000 ms). Ultrapassar o limite retorna `source_read_timeout` e **zero candidatos**. É um limite pós-resposta de domínio; não substitui os *network timeouts* de cada fornecedor futuro.
- Após ler as quatro fontes, a reconciliação usa um timestamp de fim de leitura conservador: o tempo monotônico transcorrido é arredondado para cima em segundos, evitando aceitar contrato, revisão, política ou cobertura que expirem nesse intervalo.
- Casos negativos próprios: expiração durante a leitura, timeout artificial na quarta fonte, indisponibilidade independente das quatro fontes e orçamento inválido.
- `UniversityInteropIdentifiers` centraliza contrato entre schemas: **80 bytes para `OrganizationId`** e **120 bytes para `PortalUserId`**, embora o Portal genérico aceite até 128 em ambos. IDs mais longos não podem ser truncados, remapeados, hasheados ou aceitos como alias. **Compatibilidade de formato não comprova SSO nem identidade confiável**.
- O preflight V4 usa a validação da organização; a validação de sujeito só poderá ser aplicada por um futuro emissor autenticado, ainda **não existente**. Nenhuma fonte ou issuer fica vinculado no `AppServiceProvider`.
- Revalidação do consumidor: Universidade [PR #81](https://github.com/rafhael100/imagine-universidade/pull/81) impõe limite próprio de **1.500 ms** na autoridade Portal e checa expiração no fim da chamada. O procedimento real de sessão/credenciais permanece bloqueado.
- QA: [Portal Laravel PHP 8.3/8.4 #43870685792](https://github.com/rafhael100/imagine-portal/actions/runs/43870685792) **success**, 664 testes e 10.161 asserções por versão; [Portal F64 privado #43870686138](https://github.com/rafhael100/imagine-portal/actions/runs/43870686138) **success**. Isso não é evidência de fonte comercial, autenticação operacional ou VPS real.

**V4.1 continua DRAFT / NO-GO**, dependente do owner de contrato, política de SKU aprovada, identidade empresarial, revogação autenticamente atual, AppSec/DBA/LGPD e homologação de produção.
