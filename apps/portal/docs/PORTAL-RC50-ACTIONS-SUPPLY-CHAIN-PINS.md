# Portal RC50 — fixação de GitHub Actions por SHA verificável

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Endurecimento de supply chain de CI somente na branch de revisão, sem deploy, infraestrutura ou credenciais reais.

## Origem dos pins

Em 15/11/2026, os registros públicos oficiais GitHub foram consultados para fixar as versões usadas nos workflows. O tag `shivammathur/setup-php@v2` era um **tag anotado e assinado** (`d52fc211a0436f8839a587b3b5b8efddab4744ea`) apontando para o commit:

- PHP action v2 / release 2.40.0: `eb7c497e18156a6bbabfef1d3a82760b9eda3962`
- actions/checkout v4: `11d5960a326750d5838078e36cf38b85af677262`
- actions/setup-node v4: `49933ea5288caeca8642d1e84afbd3f7d6820020`
- actions/upload-artifact v5: `330a01c490aca151604b8cf639adc76d48f6c5d4`
- actions/checkout v7: `3d3c42e5aac5ba805825da76410c181273ba90b1` (já usado pelos checks funcionais)

As 21 definições de workflow previamente com referências móveis foram reescritas em um único commit Git, preservando as versões maiores existentes e substituindo apenas o seletor da action por um SHA específico. Não significa que os mantenedores das actions deixam de atualizar dependências ou que vulnerabilidades futuras não possam surgir; upgrades de SHA exigem nova revisão e CI.

## Gate automatizado adicional

- `apps/portal/tools/audit-workflow-action-pins.mjs`: varre **todos** os arquivos YAML em `.github/workflows`, bloqueando referências externas que não terminem em SHA de 40 caracteres e imagens Docker sem `sha256`. Aceita ações e workflows locais vinculados à mesma revisão do checkout.
- `apps/portal/labs/seo-rc50-actions/action-pins.test.mjs`: casos positivos, rejeição de tags/branches `v4`, `v7`, `v2`, `main`, imagens `latest` e tentativa de caminho local escapando do repositório.
- `.github/workflows/portal-rc50-immutable-action-pins-ci.yml`: roda Node 22, somente `contents: read`, sem segredos, escrita ou publicações.

## Limites

O gate analisa referências `uses:` como código estático. Não substitui análise das permissões de cada workflow, segurança das ações individuais, execução com dependências Python/PHP/Node resolvidas, SBOM, auditoria de Composer e revisão de logs/segredos.

Permanecem obrigatórios os gates AppSec/DBA/LGPD, homologação de staging e aprovação humana do diff histórico de mais de 400 commits.
