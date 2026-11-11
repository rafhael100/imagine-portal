# PORTAL-INT-F149 — Candidata integrada Portal + SEO/GSC, exclusivamente para revisão

A primeira execução da integração encontrou duas classes de problemas reais: conflito textual de `Permission.php`, resolvido na [F147 #350](https://github.com/rafhael100/imagine-portal/pull/350), e teste GSC que exigia cinco permissões exatas, resolvido com a extensão **restrita e documentada** na [GSC-F143 #353](https://github.com/rafhael100/imagine-portal/pull/353). A prova [F148 #352](https://github.com/rafhael100/imagine-portal/pull/352) executou testes Node e **478 testes Laravel / 7.631 asserções** na árvore combinada efêmera, após a correção.

Para permitir uma revisão de código **de verdade**, a integração combinada está agora representada em uma branch separada `review/portal-int-f149-portal-seo-merged-candidate-2026-11-11`. Esta branch **não é dev, não é main e não é release**.

## Proveniência imutável

- Parent Portal: `edc135126e40a1f7e3a9182a491d91eeaf4f4088`
- Parent SEO/GSC: `0cc38441e0ce62c01b76a8caa9e3032d14dd0274`
- Commit de integração **somente revisão**, com dois pais: `68268371d8fa89a3de3802407211be72935695c9`
- Árvore Git combinada: `13b17c7f7e1ca6d37aa21a5e9d054f85f5c689c8`

A árvore foi construída a partir da árvore Portal, sobrepondo **130 arquivos** efetivamente alterados somente pelo SEO/GSC em relação ao ancestral comum; o único arquivo alterado pelas duas linhas era `Permission.php` e recebeu a versão que preserva ambos os códigos de permissão **sem grant implícito**. O commit foi criado como objeto Git com os dois pais e publicado **apenas** em uma branch de revisão, sem mexer na linha GSC, dev ou main.

## Gate de CI

A CI específica F149 verifica o exato `git merge-tree` entre os dois pais, exige igualdade de SHA da árvore resultante e bloqueia mudanças adicionais de runtime sobre o commit combinado. Também exige as duas permissões no enum, o vínculo Laravel de autoridade `deny-all`, e executa testes Node24 + Laravel PHP8.4 diretamente da branch integrada. Isso é uma *prova de regressão do código agregado*, não uma decisão de integração operacional.

**Permanecem NO-GO:** revisão independente AppSec/DBA/LGPD, credenciais/identidade/MFA reais, RLS e migrações homologadas, tratamento de dados, autorização de cookies, Google Search Console efetivamente conectado, CRM operacional, ambiente VPS de homologação e aprovação específica para qualquer merge fora da branch de revisão. Nada foi implantado e não foram feitas novas despesas.
