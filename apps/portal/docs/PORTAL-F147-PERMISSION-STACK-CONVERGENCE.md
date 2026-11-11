# Portal F147 — Resolução de conflito Portal × SEO/GSC sem conceder permissões

A [F146 #347](https://github.com/rafhael100/imagine-portal/pull/347) comparou o Portal com o commit SEO/GSC `500c7cbd2379a1869a52a8ad3133af5c62de830a` (F142/#344) por `git merge-tree`. A execução original encontrou **um único caminho compartilhado**: `apps/portal/app/Domain/Authorization/Permission.php`; a simulação apontou conflito nessa adição concorrente ao enum.

A análise do ancestral comum `b4092bb5f4c072dc6348ca2f9ad99769587b13c2` revelou que a cadeia Portal tinha adicionado `ViewCommercialDeliveryReview = 'commercial.delivery-review.view'`, enquanto a cadeia GSC adicionou `ConnectGoogleSearchConsole = 'seo.gsc.connect'` no mesmo local. Ambas eram **somente vocabulário**, sem GRANTs ou endpoints operacionais.

F147 coloca `ViewCommercialDeliveryReview` em um trecho independente no começo do enum e mantém o acréscimo da permissão GSC igual ao trecho da branch SEO. O resultado é um contrato com **ambas** as permissões e sem nenhuma concessão automática. Não modifica `AppServiceProvider`, que continua vinculado às fontes de autoridade `deny-all`, e não habilita OAuth ou revisão real.

## Provas acrescentadas

- PHPUnit exige valores distintos, nenhum wildcard e nenhuma permissão de outros produtos como CRM/Universidade.
- Com uma associação sintética ativa que só possui `ViewServices`, as duas permissões são **negadas**; contexto de outra empresa também é negado. Esses testes são contrato de domínio, não autenticação de produção.
- O workflow F146 agora verifica a branch F147 e ativa `PORTAL_REQUIRE_CLEAN_MERGE=true`. Diferente da F146 diagnóstica, um conflito de Git na F147 **faz a CI falhar**. O script continua arquivando a evidência antes de falhar.
- Nenhuma branch do SEO/GSC é alterada. O SHA GSC permanece fixado para reproduzir a evidência; ao atualizar a cadeia SEO, é necessária nova simulação.

**NO-GO:** um resultado Git limpo **não autoriza** mesclar branches, conectar OAuth/CRM, publicar nem alterar a `main`. Revisão independente de AppSec/DBA/LGPD [#315](https://github.com/rafhael100/imagine-portal/issues/315), revisão SEO [#251](https://github.com/rafhael100/imagine-portal/issues/251), homologação e gate da integração [#341](https://github.com/rafhael100/imagine-portal/issues/341) continuam exigidos.
