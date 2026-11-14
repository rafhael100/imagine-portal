# Portal GSC RC — Consolidação integrada F104–F106 (14/11/2026)

**Status: candidata de revisão, NÃO autorizada para produção.**

## Escopo entregue

Esta revisão consolida 3 entregas anteriormente isoladas:

| Etapa | Resultado | PR original |
| --- | --- | --- |
| F104 | DTO diário GSC apenas com agregados, importação offline do SEO Engine e leitor PHP restrito a teste | [Portal #771](https://github.com/rafhael100/imagine-portal/pull/771) |
| F105 | Consulta de registro de grant sob PostgreSQL RLS e revogação/expiração, com saída sempre não-publicável | [Portal #773](https://github.com/rafhael100/imagine-portal/pull/773) |
| F106 | Vínculo versionado de UUID organização/identidade Portal ao tenant/ator GSC, grant e vínculo lidos na mesma transação, papel SQL reforçado | [Portal #777](https://github.com/rafhael100/imagine-portal/pull/777) |

A fonte SEO F104 está fixada em
`ec1739621b20860f729c59d9863ba1b341da43e8`.
A [SEO RC40 #150](https://github.com/rafhael100/imagine-seo-engine/pull/150)
agrega F103/F104 ao auditor multilíngue do site comercial.

## Evidências existentes das etapas

- [F104 Node/PHP CI #43618814683](https://github.com/rafhael100/imagine-portal/actions/runs/43618814683): três cenários sintéticos, 49 mutações de entrada rejeitadas.
- [F105 PostgreSQL CI #43620890767](https://github.com/rafhael100/imagine-portal/actions/runs/43620890767): três cenários, 36 mutações adversariais rejeitadas, RLS, revogação e rollback.
- [F106 PostgreSQL CI #43623957465](https://github.com/rafhael100/imagine-portal/actions/runs/43623957465): três cenários, 51 mutações adversariais rejeitadas, papéis restritos, vinculação UUID, revisões, revogação e rollback.
- Testes gerais Node22/24 e PHP8.3/8.4 das PRs individuais verdes.
- A presente candidata deve repetir a prova **na sua HEAD exata** usando
  `.github/workflows/portal-gsc-rc-f104-f106-ci.yml`.

Os cenários são inteiramente fictícios: domínio
`sc-domain:example.com`, organização/usuários UUID inventados,
nenhum dado real de usuário/consulta, nenhum token ou rede Google.

## Regras de segurança ainda negativas

- Uma exportação OpenSEO **não é prova de origem** do Google nem autoriza o
  SaaS. O projeto OpenSEO conectado à propriedade Imagine é um sistema separado.
- Um registro no banco de grant e um vínculo UUID **não são sessão autenticada**.
- A interface GSC F106 recebe um principal **candidato fornecido em laboratório**
  e verifica a consistência com o banco; isso não prova quem emitiu o principal.
- A classe recente de autenticação do Portal
  `PostgresPortalSessionPrincipalCandidateReader` está em **outra branch**,
  não foi integrada a esta candidata e sinaliza `authorizes_http=false`.
- A saída da RC mantém `sessionAuthenticated=false`,
  `googlePropertyVerifiedNow=false`,
  `customerDisplayAuthorized=false`,
  `productionReadAuthorized=false` e `publicationAuthorized=false`.
- Nenhuma rota HTTP, publicação SEO, migration na VPS, checkout
  real, DNS ou `main` é alterada.

## Gates para release operacional

- [ ] CI agregada em HEAD exata, rollback de migrations em banco descartável.
- [ ] Consolidação de branches de identidade do Portal **sem bypass** dos
  controles anti-replay, MFA, membership, organização e revisões;
  emissor servidor autenticado do principal, sem dados do navegador.
- [ ] Revisão independente AppSec/DBA/LGPD incluindo ROLE, GRANT, RLS,
  ownership de tabela/função e privilégio de execução.
- [ ] OAuth próprio readonly GSC, scope/property verificados pelo provedor,
  refresh-token vault, revogação, retenção e rastreabilidade de consentimento.
- [ ] Autorização separada de exibição em serviço cliente, API rate limits,
  roteamento protegido, logs sem PII.
- [ ] Homologação staging com backups, restore, rollback e testes
  cross-tenant/identidade/GSC; aceite formal do release.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
