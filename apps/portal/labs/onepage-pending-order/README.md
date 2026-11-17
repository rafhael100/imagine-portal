# Pedido Onepage pendente — laboratório do próprio comprador

Escopo aprovado em 17/11/2026: Somente comprador com vínculo ativo/MFA, cotação criada por ele e aceite expresso da oferta/contrato exatos. Não inclui operadores nem outro comprador do mesmo tenant. Nenhuma rota/provider operacional, checkout, cobrança, PSP, parcelamento, projeto Sites ou produção.

Base local documental 1b57e908c82a91a916b8b22dfb60f325105a44f8. Preservados integralmente os arquivos #922 (HEAD remota e1424b16821ade9c27059e5a560cd0b00948e7f9) e os ensaios anteriores. Novo diretório labs + teste offline. AGENTS relido. PORTAL-D01 é referência histórica de padrões de aceite, não autorização deste trabalho; PORTAL-D02 não define parcelamento. #744/#748 são referências de snapshot/idempotência, sem importar identidade ou catálogo antigo. Interface #759 de contrato autorizado continua default-deny: este pedido laboratorial NÃO a implementa.

## Implementação

`Adapter.php` aceita apenas referência da cotação, chave idempotente e confirmação das versões/digests exibidos pelo servidor. Lê envelope `portal.browser_auth` da sessão Laravel; ignora campos de ator/empresa do HTTP. Somente APP_ENV=testing + ONEPAGE_PENDING_ORDER_LAB=SYNTHETIC_ONLY e PDO com banco/login/porta/loopback exatos de #922. Perfil administrador ou privilegiado é recusado. Antes de qualquer writer, lê revisão própria e compara o objeto completo de aceite, com boolean true e revisão inteira; campos extras também recusados. A comparação PHP é defesa de UX: a função SQL repete tudo de forma independente.

`store.sql` é candidato exclusivamente laboratorial. Não foi executado. Exige preflight externo Guard do #922 e guarda SQL adicional antes de DDL. Não altera migrations, papéis ou grants de produto; usa os dois papéis sintéticos já existentes de #922 e concede apenas acesso aos NOVOS objetos do laboratório. Runtime permanece sem acesso direto às tabelas; owner interno recebe SELECT em termos e SELECT/INSERT em pedidos. RLS/FORCE RLS dessas tabelas protege o acesso ao owner interno; isolamento por comprador é feito nas funções, não se alega policy RLS por comprador.

`review_order` chama `onepage_buyer_lab.principal` existente: sessão business persistida, MFA, revisões, vínculo/organização/identidade ativos e não expirados/revogados. Exige **draft.company=organization_id E draft.buyer=identity_id**, além de member, Onepage sem canais e igualdade do snapshot dos termos com a cotação persistida. Não muda a leitura intraempresa de #922; a restrição individual aplica-se somente ao novo recorte de pedido aprovado.

`order_terms` recebe somente dados de preparação server-side sintéticos: quote_id, snapshot congelado, contract_ref lab, revisão, texto e termos. Sem writer runtime. Hashes SHA256 são calculados no servidor sobre JSONB::text UTF-8 (oferta/termos) e bytes UTF-8 do contrato; o cliente deve ecoar os digests apresentados, não serializar JSON por conta própria. Versão/digest exatos fazem parte do aceite. A imutabilidade é por triggers UPDATE/DELETE e ausência de grants de escrita runtime. Não existe catálogo contratual operacional ou fonte jurídica de termos neste patch.

`accept_order` deriva novamente review/ator/empresa, compara aceite completo e grava apenas pending_payment. Preserva quote/termos/texto, digests, contrato/revisão e instante do banco. accepted_at é timestamptz; resposta normalizada em UTC com microssegundos e Z. Replay idêntico retorna mesmo ID e instante, após revalidar autoridade. UNIQUE(company,buyer,request_hash) impede duplicidade da chave; UNIQUE(quote_id) impede criar outro pedido da mesma cotação com outra chave. Payload incompatível é recusado. A revalidação final de autoridade lança exceção se mudar, desfazendo a operação. Pedido e termos são imutáveis; não há transição a pago, admitido ou publicado.

Este recorte congela um conjunto de termos por cotação. Evolução material/reaceite não foi implementada: nunca atualizar o registro histórico ou reinterpretar o aceite antigo como aceite de nova revisão. O produtor versionado de contratos, ciclo de supersessão e mapeamento operacional seguem pendentes; não se inventa esse fluxo aqui.

## Prova offline executada

PHP 8.4.24 / PHPUnit 12.5.43. Na raiz apps/portal:

```sh
APP_KEY=base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE= \
 php vendor/bin/phpunit \
 --no-progress --do-not-cache-result tests/Feature/OnepagePendingOrderOfflineTest.php
```

**6 testes, 29 assertions, OK (0.231s)**: aceite exato; ator/empresa não vêm do corpo; ausência/pendência de sessão para antes de SQL; dez formas de divergência/ausência de aceite e campo extra; negativa da fonte nativa para antes do writer; cada replay relê fonte; conflito/negação SQL propagados; administrador recusado. São PDO spies: não comprovam autorização SQL, MFA real, persistência, concorrência nem geração do instante. Lint dos arquivos PHP passou. Não se repetiram os 25 checks da composição anterior nem os 33 PG18/11 offline de #922.

## Casos PostgreSQL preparados, NÃO executados

`start-isolated.sh` é o NOVO runner do pedido: cria cluster novo e mantém o mesmo ciclo de vida até terminar `prepare.php` e `pg-cases.php`; EXIT só então encerra o cluster. Não chama nem altera o startup #922, que encerrava cedo. `prepare.php` reutiliza estaticamente a preparação atestada do #922 até a inserção da proof_key sintética, sem repetir os 33 cenários antigos ou semear suas identidades. Usa os mesmos Guard, nove migrations enumeradas e SQL existentes. `pg-cases.php` exige esse cluster descartável preparado, com as tabelas/funções nativas, proof_key sintética e logins runtime sintéticos existentes. Não inicia PostgreSQL, não cria a base nem executa migrations. Antes de schema/fixtures: verifica requisitos offline, configuração Laravel efetiva, identidade SQL + processo/cluster/pg_controldata e mantém callback antes das escritas; novas conexões passam por Guard::runtime do #922. Divergência, reconexão ou ausência da base necessária abortam. Hash/cifra das fixtures são verificados antes das escritas. Não executar neste executor com bloqueio de socket e não contornar transporte.

Casos escritos: dois compradores distintos na mesma empresa + outra empresa + manager + ausência de vínculo; senha/TOTP emitidos nativamente, sem semear sessão positiva; cotação própria persistida; termos sintéticos server-side; sem sessão/MFA pendente/quote alheia negados; versões/digests/aceite divergentes sem linhas gravadas; pendente sem PSP/Sites; UTC gerado pelo banco dentro da janela observada; mesmo ID/instante em nova conexão; nova chave não duplica cotação; payload alterado recusado; ator/empresa/contrato congelados; triggers de imutabilidade; sessão vencida (somente fixture negativa), vínculo suspenso e revogação; exatamente um pedido final. Esses resultados só poderão ser afirmados depois de executar SQL real. Concorrência e restart de servidor não são testados aqui.

## Revisão e limitações

Revisão estática retirou locks de leitura de draft/termos que exigiriam grants UPDATE desnecessários; runtime/owner não podem alterar essas linhas pelo caminho proposto. Locks da autoridade nativa #922 permanecem. Não se flexibilizaram grants existentes nem o default-deny operacional. SQL ainda não foi compilado/validado por servidor neste executor; qualquer erro deve ser corrigido somente em laboratório antes de publicação separadamente autorizada.

Dados de contrato, termos e preparação são fixtures; nenhuma política de parcelamento é escolhida (fixture payment_policy=undecided). A prova offline não conecta uma ordem real aos contratos #759, não fornece consentimento jurídico nem libera prévia. O instante está implementado e tem teste SQL preparado, mas a sua origem no banco ainda NÃO foi executada nesta rodada.

Próximo passo mínimo: revisão do patch e execução dos casos em PostgreSQL descartável permitido, preservando configuração efetiva e ateste antes de writes. Sem necessidade de segredo PSP. Só após essa evidência avaliar produtor contratual homologado e versão material/reaceite; nenhuma publicação está autorizada nesta rodada.

## Revisão do runner e provas (somente preparação local)

O workflow novo `.github/workflows/onepage-pending-order-pg-lab.yml` é candidato local, NÃO publicado/executado. Limita-se a pull_request draft do mesmo repositório, head `test/onepage-pending-order-pg-lab-2026-11-17`, base #922 `test/onepage-buyer-pg-lab-2026-11-16` na SHA e1424b16821ade9c27059e5a560cd0b00948e7f9. Permissão contents:read, sem secrets de negócio, credenciais do checkout não persistidas, runner padrão Ubuntu24.04 e timeout15min. Flags release/PSP disabled são verificadas; não existe código de liberação ou cobrança. Extração PG18 com índices/assinaturas APT oficiais e fingerprint fixado reutiliza o procedimento já comprovado, sem instalar pacotes no sistema. Nada foi baixado ou executado aqui.

Comando futuro para executor permitido (NÃO executado):

```sh
env -i PATH=/usr/bin:/bin ONEPAGE_ORDER_ALLOW_LOCAL_START=SYNTHETIC_ONLY \
 PG_BIN=/caminho/pg18/bin PHP_BIN=/caminho/php ONEPAGE_PG_RUNTIME_LIB=/caminho/pg18/lib \
 /bin/bash --noprofile --norc apps/portal/labs/onepage-pending-order/start-isolated.sh
```

Marcadores obrigatórios: `PASS ORDER_INVALID_CONFIG_DENIED_BEFORE_SQL`, `PASS ORDER_BASE_PREPARATION_ATTESTED`, `PASS ORDER_PREFLIGHT_LARAVEL_SQL_ATTESTED`, `PASS ORDER_CASE: ...` para cada prova e `PASS ORDER_RUNNER_COMPLETE_NO_RELEASE`. Nenhum marcador de SQL foi produzido nesta revisão. Configuração inválida deve terminar antes de abrir SQL; callbacks reatestam identidade antes das escritas. Falha em qualquer etapa encerra e executa cleanup, sem alternativa de transporte.

Revogação é independente da suspensão: sessão A deve estar ativa no SQL e no verificador nativo e o replay autorizado antes da chamada; depois exige status=revoked, revoked_at presente, sessão inativa, negativas de replay/nova chave e snapshot completo inalterado. A suspensão usa outro comprador com sua própria cotação/review autorizada antes da mudança. Retorno true isolado de revoke não constitui prova.

Imutabilidade exige SQLSTATE P0001 + mensagem exata LAB_ORDER_AND_TERMS_IMMUTABLE + mesmo snapshot antes/depois. Permissão negada, erro SQL genérico, mensagem parecida ou snapshot alterado falham. Predicados puros dessas provas têm testes offline adicionais; não substituem execução no PostgreSQL.

Inventário consolidado para eventual revisão de publicação: Adapter.php, store.sql, pg-cases.php, ProofChecks.php, prepare.php, start-isolated.sh, README.md em labs/onepage-pending-order; tests/Feature/OnepagePendingOrderOfflineTest.php; .github/workflows/onepage-pending-order-pg-lab.yml. Nenhum arquivo original #922 foi editado. A aprovação desta preparação não autoriza publicação ou execução CI.

Validação desta revisão: PHP8.4.24/PHPUnit12.5.43, **8 testes/39 assertions OK (0.144s)**; quatro PHP lint, bash -n do runner, YAML e blocos shell do workflow aprovados; comparação da preparação com #922 (só marcadores adicionais), scan de metadados do candidato e git diff --check. Cleanup falha com exit não zero se não conseguir parar o cluster. Nenhum SQL/startup/CI executado.

## Fechamento do preflight de processo

O comando futuro acima e o workflow iniciam Bash com env -i: BASH_ENV/ENV não chegam ao interpretador. O launcher recusa PG*, PHP*, APP_*, DB_*, dotenv/config-cache e bibliotecas herdadas antes de chamar PHP; o worker também recusa variáveis fora da allowlist mínima antes do primeiro PHP. A biblioteca explicitamente selecionada da extração autenticada é preservada para libpq/liburing. HOME novo não herda pgpass/service. PHP roda com -n e extensões enumeradas da instalação escolhida, sem ini/scan/auto-prepend. ProcessPreflight verifica configuração PHP, allowlist de ambiente e ausência de dotenv/cache antes de autoload/Laravel; Guard original continua verificando requisitos offline, configuração efetiva e identidade SQL antes de writes. APP_ENV, chave e conexão bootstrap são definidos com valores sintéticos no worker. Não executar os entrypoints PHP diretamente com configuração pessoal.

`test-launcher.py` usa executáveis sentinela benignos em diretório temporário: nenhuma execução real de PHP, PDO ou PG. Testa entradas ambientais impróprias no launcher/worker e dotenv/cache presentes ou symlinks pendentes, exigindo recusa antes de invocar sentinelas. Estes testes não comprovam conexão SQL ou persistência. O workflow é apenas candidato local em runner efêmero novo; nenhuma CI foi disparada.

Inventário atual: `.github/workflows/onepage-pending-order-pg-lab.yml`; `apps/portal/tests/Feature/OnepagePendingOrderOfflineTest.php`; `apps/portal/labs/onepage-pending-order/{Adapter.php,ProofChecks.php,ProcessPreflight.php,README.md,pg-cases.php,prepare.php,run-clean.sh,start-isolated.sh,store.sql,test-launcher.py}`. Os arquivos originais #922 permanecem intactos.

Validação offline do fechamento: 38 recusas do launcher/worker com sentinelas, preflight PHP limpo positivo e cinco recusas ambientais diretas antes de Laravel, PHPUnit 8 testes/39 assertions (0.156s), cinco PHP lint, bash -n dos dois scripts, YAML/blocos shell e diff --check aprovados. O wrapper PHP local que injeta ini foi corretamente recusado por CLEAN_PHP_REQUIRED; a prova positiva usou o binário extraído diretamente, com -n e extensões enumeradas. Isso não valida o startup no futuro runner nem a persistência: nenhum PostgreSQL/SQL/CI ou remoto foi executado nesta rodada.
