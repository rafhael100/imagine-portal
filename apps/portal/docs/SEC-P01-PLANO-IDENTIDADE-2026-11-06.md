# SEC-P01 - Contratos de ensaio de sessao e navegacao do Portal

**Data:** 06/11/2026. **Situacao:** recorte preliminar de codigo puro, para revisao. Sem login, sessao real, operacao do cliente ou homologacao de seguranca.

## Objetivo

O responsavel aprovou priorizar a identidade, a autenticacao e a navegacao real do Portal depois do painel demonstrativo P02-D. A primeira entrega implementa apenas verificacoes sinteticas, sem rotas e sem alterar os modelos existentes. A decisao de avancar no trabalho NAO homologa os gates D02-SEC01–06, D03 fisico/RLS e P0.

## Limites tecnicos

- DemoPortalSession e instanciavel livremente; o ator, a empresa e o MFA fornecidos nao tem emissor confiavel, assinatura, prova de posse ou verificacao de origem. Mesmo texto de ID de Portal e CRM nao estabelece a identidade do titular.
- DemoNavigationPolicy combina expiracao, estado de sessao, MFA ficticio e OrganizationAuthorizationService apenas em memoria. Seu resultado positivo e named wouldShowSyntheticPreview, nao e uma decisao operacional. DemoNavigationVerdict.canAuthorizeHttp() sempre devolve false.
- A janela de 600 segundos de frescor MFA e ilustrativa para PHPUnit, NAO politica de seguranca aprovada. As regras de timeout, MFA, step-up, contas, dispositivos, recuperacao e sessoes continuam por deliberar.
- Revogacao sintetica nao detecta mudancas concorrentes em PostgreSQL. O titular do recurso, a identidade Portal e o membership real futuramente deverao vir do servidor e de stores oficiais atualizados, nao do navegador.
- Nenhuma rota, middleware, login real, cookie, armazenamento, SQL, migration, Eloquent, API, autenticador, emissao de token, CAPTCHA, OTP, PSP, CRM, integracao externa ou deploy foi criado.

## Gates antes de uma area navegavel real

1. D02-SEC01: emissor de identidade Portal, registro e verificacao, credenciais, MFA, recuperacao, abuso, CSRF, rotação de sessoes, logout e auditoria minima.
2. D02-SEC02: memberships e recursos com ownership provados e autorizados por contexto, convites, delegacoes, revogacao e concorrencia.
3. D02-SEC03-06: contas de servico, limites de identidade Portal-CRM, future Performance/Universidade sem SSO implicito e UI acessivel.
4. D03 fisico/RLS, P0-DOC06: banco PostgreSQL, constraints e politicas multiempresa; PR #19 permanece documental, sem SQL executado ou homologacao.
5. Criterios de testes: session fixation, CSRF, brute force, enum de empresas, MFA bypass, rotacao de tokens, troca de empresa, cache velho, revogacao na mesma sessao, mesmo e-mail/ID entre Portal e CRM, mutacoes concorrentes, logout em outras abas/dispositivos.
6. Liberacao especifica de autenticacao real, navegacao e deploy apos aceite dos gates. Performance/Google/Meta e PSP sao fases independentes, igualmente bloqueadas.

**Proximo passo recomendado:** decidir parametros e fontes oficiais de identidade Portal e contratos de membership antes de implementar auth real. SEC-P01 nao autoriza merge, main ou VPS.
