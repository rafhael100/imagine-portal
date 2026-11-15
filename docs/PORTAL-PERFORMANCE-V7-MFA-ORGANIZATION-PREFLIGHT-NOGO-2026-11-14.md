# Imagine Portal V7 — validação prévia de sessão empresarial, MFA e direito de gestão

**14/11/2026. Apenas CI, sem autenticação, contrato, consentimento nem PSP operacional.**

## Reaproveitamento da identidade e dos direitos existentes

O Portal já mantém as abstrações `PortalAuthorityBoundary`, `PortalSessionAuthority`, `PortalMembershipAuthority`, `PortalResourceAuthority`, `PortalOrganizationAuthority` e `OrganizationAuthorizationService`. A revisão sintética de V7 anteriormente assinava a proposta F419 e o plano por canal, mas não chamava essas verificações antes de preparar o envelope V7.

`PortalPerformanceV7AuthorityPreflightLab::review` exige agora, **antes de invocar o emissor V7 de código real**, que a decisão sintética do Portal aceite expressamente:

- sessão empresarial vinculada à organização selecionada, ainda dentro de validade, não revogada, com MFA fictício confirmado;
- revisões atuais da conta, vínculo, e organização; organização ativa;
- empresa proprietária do acordo/recurso correspondente;
- permissão explícita `services.manage`, não apenas `services.view`;
- identidade de organização, tenant e acordo esperados independentemente do resumo enviado pelo navegador;
- validade do HMAC F419 e orçamento/canais V7 conferidos pelo emissor existente.

Os testes simulam sessão válida e falhas de MFA, expiração, revogação de vínculo, organização suspensa, revisão desatualizada, ausência de permissão, sessão de convite, sessão de origem CRM, troca de empresa e assinatura adulterada.

## O que não significa

O objeto `PortalBoundaryVerdict` devolve `canAuthorizeHttp()=false` **mesmo quando os critérios sintéticos passam**. As interfaces usadas neste teste são implementações fictícias em memória, não as credenciais de clientes. O código não registra aceite de clientes, não emite poderes reais nem escreve no PostgreSQL, não chama o Performance, gateways, CRM ou Ads.

A revisão V7 contém sempre `real_iam_authenticated=false`, `real_customer_acceptance_proven=false`, `customer_consent_verified=false`, `billable=false`, `can_charge=false`, `can_activate=false`. Para homologação real serão necessários adapter PostgreSQL confiável de sessões e associação às organizações, MFA e revisões atuais, proteção de CSRF/sessão, aceitação explícita versionada, persistência de consentimento, trilha de auditoria, política de revogação, S2S autenticado com o Performance, revisões SEG/DBA/LGPD/Financeiro e aprovação expressa de deploy.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.**
