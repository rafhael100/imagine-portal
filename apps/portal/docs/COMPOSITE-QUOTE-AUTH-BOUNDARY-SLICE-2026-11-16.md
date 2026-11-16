# Cotação composta: recorte Laravel de sessão e limite de autoridade

Estado: DRAFT / NO-GO. Não é jornada autenticada de cotação ponta a ponta.

## Proveniência e escopo

- Portal #915: `6ac1be6a543218c880ebd411b15c8c3a83dacb81` (também HEAD de #913 e pin F353).
- Comercial #442: `78c74b646cc9cf494464ba5aa85f198be1f0efef`.
- Branch exclusiva: `test/composite-quote-authenticated-slice-2026-11-16`, sobre #915.
- Revalidados PRs, busca por cotação/quote+auth e branches quote/authenticated; nenhum equivalente atual encontrado. Não há API de tarefas Codex externas nesta sessão; concorrência global não certificada.
- Portal #916 não incorporado. AGENTS de apps/portal lido; nenhum arquivo de skill em .agents/skills no checkout ou em /workspace/.agents.
- Alteração somente em teste existente e este documento. Nenhum controller, middleware, binding, catálogo, permissão, rota de aplicação ou workflow alterado.

## O que foi demonstrado

Três testes `composite_quote_slice` reutilizam o harness `PortalBrowserAuthTransportTest`: kernel/middleware HTTP Laravel reais, cookies protegidos e sessões criptografadas em arquivos temporários. O backend `BrowserIdentityTransportDouble` existente simula senha/MFA. Ele NÃO comprova credencial persistida, TOTP real, associação SQL ou autorização empresarial.

1. Transporte conclui senha e MFA sintéticos; GET session devolve authenticated=true. Separadamente, no processo do teste, `CompositeQuotePreview` recalcula uma seleção anônima existente. Nenhuma rota de cotação é registrada.
2. Sem sessão, com MFA pendente ou código de teste incorreto, a resposta HTTP é 401 com somente authentication_failed. Não há payload de cotação ou empresa.
3. Login para outra empresa sintética é recusado antes de chamar o adapter de identidade. Após MFA, a autoridade de negócio padrão continua negando tanto a empresa configurada quanto a outra; nenhuma propriedade de recurso ou concessão é inventada.

O resultado positivo de sessão NÃO é apresentado como autorização de empresa. A seleção não é transmitida por uma API Comercial→Portal: ela é o mesmo input fixado, recalculado localmente. Nenhum teste cria uma ponte de autorização para o cálculo.

## Um único caso reutilizado

Entrada: site=onepage, channels=[google_ads,meta_ads], tier=minimum.
A execução do `buildCommercialChoicePreview` no SHA Comercial acima produziu, em centavos:

- site 99000; ativação 98000; total 197000;
- parcelas meramente ilustrativas [65666,65666,65668];
- mensalidade 7990, primeiro vencimento D+90;
- mídia direta às plataformas 170000; gestão estimada pós-paga 37000.

O teste confere os valores pelo PHP Portal e a versão do catálogo; preserva false para checkout, cobrança, captura de cliente/tenant, aceite e cronograma. Não aprova ou redefine preços/políticas.

Comando de coleta no checkout Comercial fixado:

```sh
node --input-type=module -e 'import {buildCommercialChoicePreview} from "./src/lib/commercial-choice-preview.mjs"; console.log(JSON.stringify(buildCommercialChoicePreview({site:"onepage",channels:["google_ads","meta_ads"],tier:"minimum"}),null,2))'
```

## Ambiente e execução

Linux local; PHP 8.4.24; PHPUnit 12.5.43. Dependências locais reaproveitadas de portal-p20-isolated, após comparação byte a byte do composer.lock. Nenhum composer install externo. Chave APP_KEY apenas fixture sintética; não é credencial operacional. Nenhum .env real copiado.

Na raiz apps/portal, com PHP disponível no PATH:

```sh
APP_KEY=base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE= php vendor/bin/phpunit --filter='composite_quote_slice|test_revoked_backend_state_is_rechecked_and_durably_revoked|test_resolving_full_boundary_from_laravel_cannot_grant_any_access' --no-progress --do-not-cache-result tests/Feature
```

Resultado local: **5 tests, 241 assertions, OK**, incluindo os três testes novos (isoladamente: 3 tests, 168 assertions) e duas regressões benignas existentes de revogação e autoridade padrão. git diff --check passou. Log local: /workspace/scratch/c24b1cbd1d14/quote-auth-slice-tests.log.

## O que não rodou e por quê

Não rodaram PostgreSQL, migrations, fixtures SQL, criação/grant de roles, concorrência, a matriz de 48 escolhas, browser Chromium ou suites adversariais. Não houve PSP, chamada de negócio externa, pedido, cobrança, checkout, deploy ou merge.

Não se iniciou banco: o writer composto existente exige login postgres no banco sintético; não existe ligação autorizada entre a sessão de negócio e um writer composto de menor privilégio. Não promovemos o admin ao runtime. Logo, não há rascunho gravado ou relido nem prova RLS nova. Os gates de identidade do cluster/configuração Laravel/SQL não foram exercitados porque nenhuma conexão, migration ou fixture SQL foi tentada.

## Lacuna e próxima ação

Ainda falta provar identidade/MFA persistida e associação empresarial por fonte confiável no recorte de cotação, com autorização específica e leitura/gravação mínima segregada. A autoridade padrão nega tudo e o contrato BrowserAuthentication declara que autenticação não autoriza recurso de negócio. Reaproveitar apenas o booleano authenticated para liberar um rascunho contornaria esse limite.

Próxima ação: revisar um desenho explícito do vínculo sessão→empresa→permissão de cotação→writer/reader mínimo, sem usar services.view como autorização implícita de cotação. Uma futura prova SQL deve primeiro confirmar configuração Laravel efetiva e identidade de cluster/banco/login em loopback, abortando antes de migrations/fixtures em qualquer divergência. Qualquer alteração de bindings, permissões ou políticas exige escopo separado; não foi autorizada ou executada aqui.
