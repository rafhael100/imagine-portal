# PORTAL-F19 — Elegibilidade da Universidade como benefício, sem concessão de acesso

**Data: 09/11/2026 — somente domínio de exemplo em PHP/Laravel.**

## Regra de negócio preservada

A Universidade Imagine **não é produto avulso**. Sua futura habilitação
depende de uma empresa cliente com pelo menos um **serviço Imagine elegível
ativo**, comprovado por fonte confiável. Ao cancelar ou suspender o último
serviço elegível, a continuidade do benefício deverá ser reavaliada.
Isso não implica SSO com Imagine CRM, que mantém identidade e login próprios.

## Recorte de código

- `Domain/ServicePortfolio/BenefitServiceState.php` enum de quatro
  estados exclusivamente fictícios: preparação, ativo ilustrativo,
  suspenso e cancelado.
- `BenefitServiceFixture.php`: objeto readonly com referência
  `DEMO-SVC-*`, organização, produto, estado e flag de elegibilidade
  **fornecida artificialmente**, não avaliada por CRM/financeiro.
  A Universidade não pode figurar como serviço que concede acesso a si
  mesma. Nenhum estado prova elegibilidade real.
- `UniversityBenefitPreviewPolicy.php`: inspeção pura, somente em memória,
  de uma lista com no máximo 50 fixtures; exige mesma organização,
  referências únicas e valores de tipos conhecidos. Rejeita de forma
  atômica listas mistas, inválidas ou duplicadas. Apenas ilustra a condição
  "serviço ativo E declarado elegível no exemplo".
- `UniversityBenefitPreviewResult.php`: resultado readonly com motivo
  fechado; os métodos `mayGrantUniversityAccess`,
  `authorizesStandalonePurchase`, `provesCustomerEligibility` e
  `mayExposeCustomerData` são **sempre false**, inclusive em cenário
  hipotético favorável.
- `Presentation/DemoUniversityBenefitRequirements.php` e Blade de
  `portal.demo.available`: painel sem ação com os requisitos futuros
  e o aviso expresso de que não houve verificação ou habilitação.

## Limites técnicos obrigatórios

Uma organização passada como parâmetro não é prova de vínculo autenticado
ou propriedade do serviço. O booleano `declaredEligibleInFixture` é
inventado e manipulável em testes, não sinal do Portal, CRM ou financeiro.
`ActiveIllustration` não comprova contrato vigente, pagamento ou uso
permitido. Mesmo um resultado `illustrative_candidate` **não concede**
qualquer acesso, login, código, token, endpoint ou privilégio.

A elegibilidade operacional futura exigirá fonte confiável de serviço
e contrato elegível, vínculo seguro com organização e identidade,
revogação/alteração de status sob concorrência, consistência de regras,
auditoria, isolamento multiempresa e política de publicação revisada.
É possível que o catálogo futuro contenha diferentes produtos elegíveis:
esta PoC **não estabelece** que todos os serviços ou planos se qualificam.

## Testes

PHPUnit de tipos e limites; ausência de elegibilidade em preparação,
cancelamento e suspensão; perda do último candidato; organização
diferente nega sem vazamento parcial; referências duplicadas, empresa
ausente e listas inválidas; tentativas de tornar a Universidade o
próprio serviço qualificador; renderização HTML sem formulário, links
operacionais, banco, rotas ou sessão.

**Sem merge, deploy ou gastos.** Branch filha de PORTAL-F18 / PR #135,
com base em F17/F16/F15/F14. Laravel continua backend principal; Node
permanece ferramenta do CI, não serviço operacional do Portal.
Gates P0, SEC-R03, D02-SEC, D03-RLS e produção seguem abertos.
