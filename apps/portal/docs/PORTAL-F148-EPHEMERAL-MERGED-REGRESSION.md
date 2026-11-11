# Portal F148 — Regressão da árvore Portal + SEO/GSC combinada, em runner efêmero

A F147 eliminou o conflito textual `Permission.php` entre o Portal e o snapshot SEO/GSC F142. No entanto, um `git merge-tree` sem conflito apenas prova que o Git consegue compor os arquivos — **não** que o Laravel ou o servidor Node funcionam com as duas linhas combinadas.

A F148 cria uma execução CI para verificar o código da **composição real desses dois commits**, sem realizar merge no GitHub:

1. Faz checkout do SHA exato de Portal desta revisão, busca para leitura a branch GSC e fixa o commit `0cc38441e0ce62c01b76a8caa9e3032d14dd0274`.
2. Executa `git merge-tree --write-tree --name-only --messages` e exige zero conflitos. Um status não limpo interrompe os testes com NO-GO.
3. Usa `git commit-tree` e `git worktree add --detach` para criar **somente dentro do runner** um commit de teste com dois pais. Nenhum nome de branch, tag ou ref remoto é atualizado; o token de checkout não fica persistido.
4. Confere as duas permissões independentes no enum, a presença do `AppServiceProvider` com fonte de autoridade `deny-all` e os lockfiles.
5. Instala as dependências Node pelo `pnpm-lock.yaml` com scripts desativados; executa `npm test` na árvore combinada.
6. Instala Laravel pelo `composer.lock` (`--no-scripts --no-plugins`) em PHP 8.4, gera chave descartável e executa `php artisan test` **na árvore integrada**, sem PostgreSQL real, OAuth, CRM, GSC, envio, cobrança ou credencial de cliente.
7. Registra hashes no resumo da CI para comparar a árvore avaliada com a candidata. A preparação e os testes não acessam servidores de produção, não fazem deploy, não geram migrações ou modificações persistentes.

A prova é **de integração de código e regressão offline**, não homologação. GSC permanece fixado em um snapshot antigo; novas mudanças de SEO exigem repetir o processo com um novo SHA e revisar os diffs. A integração operacional ainda exige revisão AppSec/DBA/LGPD independente, autoridade real de sessão, RLS, segurança de tokens, backup/migração e homologação da infraestrutura. **Sem merge em dev/main ou lançamento.**

## Defeito de compatibilidade encontrado pelo primeiro teste combinado

A execução original com o SHA GSC F142 chegou a rodar **478 testes Laravel**, dos quais **477 passaram e 1 falhou**: `PortalGscPermissionContractTest` usava a enumeração exata de cinco permissões GSC, incompatível com a permissão comercial adicional do Portal. Como resolução controlada, foi aberta a [PR GSC-F143 #353](https://github.com/rafhael100/imagine-portal/pull/353), que preserva os cinco códigos exigidos e admite **somente** o código extra `commercial.delivery-review.view`. A F148 foi atualizada para usar o SHA exato dessa PR, `0cc38441e0ce62c01b76a8caa9e3032d14dd0274`, e obrigatoriamente reexecutar os testes; a falha não é ignorada ou filtrada. Nenhum endpoint ou permissão real foi habilitado.
