# Portal SEO F130 — inventário completo de diff para revisão de release

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continua a [RC47 #873](https://github.com/rafhael100/imagine-portal/pull/873).

## Motivação

A RC47 acumula centenas de commits e alterações em muitos módulos do Portal. A consulta do GitHub Compare exibiu 300 entradas de arquivos, o que pode refletir o limite da API e **não comprova que o inventário esteja completo**. Para aprovar uma mudança dessa dimensão, é necessário inspecionar o diff de verdade e registrar uma revisão por responsável, não concluir que uma CI verde equivale a um aceite técnico ou operacional.

## Implementação

O workflow `.github/workflows/portal-seo-f130-full-diff-review.yml`:

1. Faz checkout **sem credenciais persistidas** do commit `head.sha` do PR e baixa o histórico Git completo em um runner descartável.
2. Verifica que `head.sha` realmente é o checkout, que `base.sha` pertence à história ancestral do PR e que ambos são SHA-1 hexadecimais completos.
3. Executa `git diff --name-status --no-renames -z BASE HEAD` diretamente no repositório local; a lista **não depende do limite de 300 arquivos** da API Compare.
4. Analisa todos os caminhos UTF-8 válidos, categoriza o risco e marca como prioridade bancos/migrations, autenticação e tenant, OAuth/tokens, rotas HTTP, infraestrutura, CI/release, código não classificado e alterações de configuração.
5. Escreve um relatório JSON no diretório temporário do runner, com paths, status, riscos, hashes exatos do diff e contagens. Também escreve um checklist no **resumo do job GitHub**.
6. Publica como artefato temporário o JSON contendo apenas nomes de arquivos já presentes no repositório (retido por sete dias). Não publica conteúdo, tokens, relatórios de clientes ou credenciais.

Os testes geram mais de 350 caminhos artificiais para confirmar que a ferramenta não trunca o resultado em 300 entradas; rejeitam caminhos manipulados, status inesperados, SHA inválida, inputs duplicados e execução fora do GitHub Actions.

## Sem confundir inventário com aprovação

Cada arquivo vem com `independentlyReviewed=false`; os checkboxes do checklist aparecem **desmarcados**. O JSON obriga:

- `independentReviewPerformed=false`
- `mainMergeAuthorized=false`
- `productionDeploymentAuthorized=false`
- `customerDataAccessAuthorized=false`
- `releaseStatus=HOLD_INDEPENDENT_CODE_SECURITY_AND_OPERATIONS_REVIEW`

O agrupamento de riscos é apenas heurístico e não substitui inspeção de código, tests de segurança ou auditoria humana. Arquivos não classificados recebem risco alto; **um relatório técnico válido nunca autoriza publicação**.

## Critérios para futura liberação

Os responsáveis precisam revisar cada caminho e o diff correspondente, validar migrations/rollback, auth/MFA/tenant, KMS/chaves, trilhas de auditoria, fluxos Google, IBGE real, LGPD/AppSec/DBA, VPS staging e deploy/reversão. O GitHub Actions F130 não faz merge nem concede deploy, mesmo se todos os testes anteriores da RC47 continuarem verdes.
