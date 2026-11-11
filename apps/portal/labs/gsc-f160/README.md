# GSC-F160 — Proveniência da RC32 e entradas maliciosas

A verificação do Portal F79 para SEO RC32 possui dois reforços na mesma PR de integração:

1. **Proveniência do código:** após o checkout de `rafhael100/imagine-seo-engine` no SHA `f46b65aaef9b10e32d02e415907cd916a43b548e`, o workflow confere o `git rev-parse HEAD` efetivo, recusa arquivos versionados alterados e exige os três módulos Node necessários. Um SHA incluído no JSON de teste não é prova suficiente de qual código foi executado.
2. **Fail closed do HTML:** o runner `check_f159_renderer_fail_closed.mjs` gera uma evidência sintética via Node RC32, verifica o HTML privado autorizado somente para o laboratório e recusa solicitações em produção, flags de laboratório ausentes, `--publish`, symlinks, campo de autorização forjado, termo extra privado, JSON inválido e arquivo acima de 64 KiB. Para todas as recusas, exige saída sem HTML e diagnóstico sem consultas, tokens ou paths fictícios.

Os arquivos de teste são criados fora do repositório, removidos ao final e nunca recebem dados reais. Não há operação OAuth/Google HTTP, route, grants, migrações, deploy ou merge em main/dev. Os testes comprovam comportamento sintético e restrições, **não** a identidade de usuários ou autorização de propriedade. AppSec/DBA/LGPD e homologação autenticada permanecem necessárias.
