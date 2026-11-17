# Comprador Onepage — implementação e harness candidatos

DRAFT / NO-GO. Somente laboratório. A aprovação do comprador da própria empresa permanece válida; nada neste pacote aprova publicação, checkout, PSP ou operação real.

## Proveniência e autorização

Aprovação lida: Sentinel_615ec81cf9a08191a068d82aaefbe6a2 em resposta ao pedido Sentinel_f799710c80b081919daee7590c06b220: comprador autenticado com MFA/vínculo ativo pode criar/retomar cotação Onepage da própria empresa; operador em nome alheio excluído.

Base #921: `8ecbf63093a6d9dc155f0d70cc246fb0014f74f7`. Candidato inicial local: `a7f9c4211166405b3885f652b0d1ffd22e8d7de2`. Branch exclusiva: `test/onepage-buyer-pg-lab-2026-11-16`. O patch consolidado aplica-se sobre #921 e inclui candidato inicial e harness; não aplicar ambos os patches.

AGENTS apps/portal foi relido; nenhuma skill .agents/skills relevante disponível. Nenhum workflow, migration existente, provider/rota ou regra real foi modificado. Nenhuma consulta externa/publicação nesta continuação.

Referências históricas já lidas, usadas somente para cenários:
- Portal #695 `839beae5d1b6ee6b9ae12c4fa313d9d67a8a9c0e`: compradores separados e persistência.
- Portal #701 `0826dbf5aa3e38a1a81b339003bfec54fbf5d206`: manter seleção server-side.
- Comercial #382 `0aec075d668b4e983ec93537564be298528a2733`: handoff e isolamento. Preços/pedidos/PSP/autoridade não copiados.
- P20: princípio de validar configuração Laravel + identidade SQL/cluster local antes de migrations, recusar reconexão/configuração divergente e nunca tratar saída incompleta como sucesso. Nenhum código de autoridade P20 foi portado.

## Conteúdo e revisão estática

BuyerQuoteAdapter não registrado recebe Request Laravel, usa exclusivamente portal.browser_auth server-side e exige phase=business. Campos identity/organization enviados no corpo são ignorados. Criação só Onepage sem canais, com preço recalculado por CompositeQuotePreview atual. Retomada usa ID e escopo obtido pela autoridade nativa, nunca empresa fornecida pelo cliente.

store.sql é candidato EXCLUSIVAMENTE descartável. Runtime NOLOGIN por padrão, EXECUTE somente em command, sem acesso direto a tabelas de rascunho/identidade. Owner separado NOLOGIN sem superuser/BYPASSRLS; SELECT/INSERT restritos à tabela de drafts, RLS ENABLE/FORCE. A policy interna é do owner de função; o isolamento por empresa é aplicado dentro de SECURITY DEFINER. Não confundir isso com uma policy RLS por tenant já demonstrada.

principal reutiliza portal_browser_principal_candidate e bloqueia linhas nativas de sessão, usuário, vínculo e empresa. MFA/status/revisões/prazos vêm da autoridade nativa existente. O filtro conservador role=member delimita os compradores das fixtures; manager não recebe cotação. Não é nova regra operacional para todos os clientes.

command revalida antes/depois, deriva empresa/identidade no servidor, usa UNIQUE(company,request_hash), recusa conflito de seleção/cotação e retorna o mesmo draft em repetição idêntica. ID alheio deve retornar denied. Uma mudança de autoridade no final lança exceção e deve desfazer a chamada inteira. Resume devolve o snapshot histórico, não reprecifica silenciosamente; nova criação sob a mesma chave com catálogo/quote diferente deve dar conflict.

Correções defensivas locais nesta revisão:
1. Ordem de campos do input não altera a seleção: canonicalização antes de recálculo/armazenamento.
2. Guard exige argumento exato do diretório no processo postgres, executável postgres e pg_controldata irmão. Antes aceitava correspondência parcial de cmdline.
3. Harness verifica também o banco de manutenção antes de CREATE DATABASE, não apenas o banco final.
4. Mesmo PDO/configuração/identidade são preservados durante preparação; conexão Laravel não pode reconectar. beforeExecuting valida configuração/identidade antes de cada comando Laravel, usando consultas PDO diretas para não recursar.
5. Comparação de quote JSONB no harness ignora somente ordem das chaves, preservando comparação estrita dos valores/tipos. Checagem booleana do perfil SQL aceita somente representações explícitas de verdadeiro/falso.

Riscos/limites revisados: SECURITY DEFINER, privilégios/locks/RLS e transações SQL ainda NÃO executados; sintaxe PHP não valida PL/pgSQL. A função SQL confia no serviço PHP autorizado para calcular a quote; não é API para navegador. Sem evidência concorrente nova nem teste de revogação intercalada no meio da transação. Sem auditoria operacional, TLS, recuperação pós-restart de servidor ou auth HTTP/browser ponta a ponta neste recorte. Trust auth é apenas do cluster novo em loopback de um executor isolado; nunca reutilizar em host compartilhado/produção.

## Harness proposto, não executado com PostgreSQL

start-isolated.sh exige opt-in, PG_BIN absoluto e cluster mktemp novo. Não reutiliza cluster anterior. Mantém loopback/porta55439/socket Unix, sem alternativa de transporte. Startup que falha encerra; cleanup só tenta parar o cluster criado por esta execução. Evidência e dados sintéticos ficam no diretório temporário para revisão.

harness.php carrega Laravel efetivo e valida ambiente testing, default pgsql, host literal127.0.0.1, porta55439, bootstrap onepage_lab_bootstrap, banco exato, ausência de URL/read/write/options alternativos. Confronta SQL com data_directory, postmaster.pid, processo local, início e system_identifier de pg_controldata, antes de qualquer schema/fixture. A primeira etapa permite somente postgres como banco de manutenção. Se portal_onepage_buyer_ci já existir, aborta em vez de reaproveitar. Depois da criação intencional, muda para esse banco e refaz toda a prova. Aplicações/configuração cacheadas divergentes falham fechadas.

A preparação usa SOMENTE nove migrations nativas existentes explicitamente listadas no harness, portal_browser_auth.sql e portal_browser_principal_candidate.sql, seguidas de store.sql. Não executa uma suíte de migrations inteira. Só habilita LOGIN dos dois papéis sintéticos nesse cluster. Fixtures não são carregadas até os guards estarem válidos.

Positivos de identidade usam PostgresPortalBrowserAuthentication real: senha hash, segredo TOTP sintético cifrado, pending e business emitidos pelo código nativo. Não se semeia sessão positiva autenticada. Request carrega envelope em sessão Laravel server-side separada por comprador. Há uma fixture de sessão vencida somente para teste negativo; ela não é autoridade positiva.

Cenários escritos, ainda NÃO rodados no banco:
- banco novo vazio; ausência de sessão e vínculo negada;
- senha/TOTP nativos de A/B e manager; pending-MFA recusado;
- manager fora do recorte;
- criar quote própria e confrontar o catálogo atual;
- repetir chave/seleção e obter mesmo ID;
- descartar adaptador/conexão e reler por nova conexão real;
- B não retoma ID de A; chave igual em B gera outro ID;
- mesma chave com seleção diferente retorna conflict;
- exatamente duas linhas, sem duplicação após negativas;
- sessão vencida, revogação nativa e vínculo suspenso negados;
- catálogo de privilégios comprova perfil mínimo e ausência de permissões de tabela/coluna diretas;
- FORCE RLS presente.

Nenhum cenário tenta exploração. A releitura em nova conexão não é um restart de servidor. A suíte concorrente F353 não é repetida.

## Comandos propostos para executor isolado/CI permitido — NÃO executados aqui

Pré-requisitos: Linux com /proc, PostgreSQL18/pgcrypto e binários da mesma instalação; PHP8.4 com PDO PostgreSQL/sodium/Argon2id/mbstring, proc_open e locale C e dependências Composer já disponíveis. Porta55439 livre. Sem rede externa ou instalação automática.

```sh
# Na raiz apps/portal, não criar ou alterar workflow:
ONEPAGE_ALLOW_LOCAL_START=SYNTHETIC_ONLY \
PG_BIN=/usr/lib/postgresql/18/bin PHP_BIN=/usr/bin/php \
bash labs/onepage-buyer/start-isolated.sh
```

O script testa PRIMEIRO DB_HOST=localhost. Resultado obrigatório: exit1 com DISPOSABLE_CONFIG_REQUIRED, antes de PDO/CREATE DATABASE/schema/fixtures. Se aceitar, aborta. Depois executa o caso válido. Único sucesso aceito: JSON final status=PASS com lista de cenários. Exceção/ausência de resultado/exit não-zero é falha, não evidência positiva.

## Evidência histórica do candidato 5cec188

Executor local: PHP8.4.24, PHPUnit12.5.43. Comando executado em apps/portal:

```sh
APP_KEY=base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE= php vendor/bin/phpunit --no-progress --do-not-cache-result tests/Feature/OnepageBuyerOfflineTest.php
```

**8 testes,34 assertions,OK**,0.239s. PDO spy testa fluxo de controle e não simula prova de persistência. Adicionados os casos de ordem das propriedades e do modo de manutenção restrito. PHP lint dos cinco arquivos PHP alterados/novos OK; bash -n do startup OK; git diff --check OK.

Executado também harness.php com APP_ENV=testing, flag DISPOSABLE_ONLY, DB_DATABASE=postgres, DB_HOST=localhost e caminhos inexistentes de cluster/control. Saída real: `HARNESS_FAILED: DISPOSABLE_CONFIG_REQUIRED`, exit1, antes de SQL. Isso testa a recusa, não a identidade positiva.

Não se iniciou PostgreSQL nesta continuação. A tentativa anterior parou em `could not create Unix socket ... Operation not permitted`, seguida de shutdown; não se contornou. O cluster antigo permanece parado. Nenhuma nova migration/fixture/schema/grant aplicada. Nenhum teste #921/48 escolhas/concorrência/PSP/HTTP externo rodou.

## Inventário EXATO a revisar para eventual publicação futura

1. apps/portal/labs/onepage-buyer/BuyerQuoteAdapter.php
2. apps/portal/labs/onepage-buyer/Guard.php
3. apps/portal/labs/onepage-buyer/README.md
4. apps/portal/labs/onepage-buyer/check.php
5. apps/portal/labs/onepage-buyer/store.sql
6. apps/portal/labs/onepage-buyer/harness.php
7. apps/portal/labs/onepage-buyer/start-isolated.sh
8. apps/portal/tests/Feature/OnepageBuyerOfflineTest.php

Excluídos: vendor, clusters, logs locais, .env, chaves geradas, workflows e arquivos operacionais. A negativa anterior de publicação permanece; não houve nova tentativa de push/conector/upload.

Próximo passo mínimo: revisão deste patch e execução do comando proposto em executor com PostgreSQL permitido. O comprador já foi aprovado; não precisa de nova decisão comercial. Persistência/MFA/SQL só poderão ser declarados aprovados após a execução real e inspeção do resultado.


## Correção defensiva local sobre 5cec188 — 17/11/2026

Guard, adapter e store usam `host(inet_server_addr())`: a conversão anterior para text incluía a máscara /32. A comparação continua estritamente com 127.0.0.1; nenhum outro loopback, nome DNS ou endereço é aceito. O único diff de store.sql nesta correção é essa expressão. Não se alteraram grants, ACL, RLS ou a política de acesso; não se presume que ACL esteja quebrada.

O preflight offline exige Linux, /proc, proc_open, PDO pgsql, sodium, Argon2id e LC_ALL=C. PHP pode inicializar LC_CTYPE=C.UTF-8 apesar da variável C: somente com LC_ALL=C explícito normalizamos o locale PHP para C e verificamos o resultado. O startup verifica requisitos antes de mktemp/initdb. Hash Argon2id e cifra TOTP gerados são verificados pelas primitivas nativas antes de qualquer conexão/preparação de schema/fixture; null, senha divergente ou cifra que não abre corretamente abortam.

Toda nova conexão runtime do harness passa por barreiras de identidade do bootstrap antes/depois da abertura e do ateste. A conexão informa banco, login/ator sintéticos, IP, porta, início do servidor, perfil sem privilégios, PID, porta cliente e início do backend. O bootstrap já confrontado com processo local/pg_controldata observa esse mesmo backend em pg_stat_activity, incluindo nonce aleatório em application_name. Isso liga a conexão ao cluster verificado sem conceder ao runtime leitura de pg_control_system nem tabelas de negócio. Falha de observabilidade também aborta; não há fallback ou grant corretivo. O ateste usa consultas e configuração de sessão, sem escrita de dados. Essa composição ainda depende de prova SQL real; os testes offline validam apenas decisões sobre evidências sintéticas.

### Lacuna de visibilidade intraempresa — nenhuma política alterada

O limite aprovado é a própria empresa. O store mantém escopo por company; buyer é proveniência e não filtro de propriedade. A chave idempotente também é por empresa. Logo o código atual não oferece isolamento individual entre dois compradores da mesma empresa. Esta entrega tampouco autoriza compartilhamento operacional entre eles. Antes de uso operacional, decidir explicitamente visibilidade e colisão de chaves entre compradores da mesma empresa e acrescentar o respectivo cenário de aceitação. Não se adicionou filtro por comprador nem se declarou uma nova política neste patch.

### Evidência offline desta correção

PHP8.4.24 / PHPUnit12.5.43, sem instalação ou rede. Comandos efetivamente executados a partir de apps/portal (php = wrapper local p20-tools/bin/php):

```sh
LC_ALL=C APP_KEY=base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE= php vendor/bin/phpunit --no-progress --do-not-cache-result tests/Feature/OnepageBuyerOfflineTest.php
# OK: 11 tests, 64 assertions; 0.953s
for f in labs/onepage-buyer/*.php tests/Feature/OnepageBuyerOfflineTest.php; do php -l "$f"; done
bash -n labs/onepage-buyer/start-isolated.sh
```

Cinco PHP lint OK; bash -n OK. Guard::offline executado com LC_ALL=C: OK. A primeira execução detectou locale misto e foi corrigida conforme descrito acima. Testes adicionados: requisitos ausentes; validação criptográfica dos materiais sintéticos; divergências benignas no ateste de backend/cluster/login/porta. Nenhuma prova de persistência por spy.

Negativa real offline repetida com APP_ENV=testing, ONEPAGE_BUYER_PG_LAB=DISPOSABLE_ONLY, DB_CONNECTION=pgsql, DB_HOST=localhost, DB_PORT=55439, DB_DATABASE=postgres, DB_USERNAME=onepage_lab_bootstrap, DB_URL vazio, LC_ALL=C e caminhos inexistentes para cluster/control: `HARNESS_FAILED: DISPOSABLE_CONFIG_REQUIRED`, exit1, antes de abrir PDO. Configuração efetiva válida e identidade SQL positiva continuam não executadas.

Não executados: startup, SQL, migrations, fixtures persistidas, MFA com banco, releitura PostgreSQL, testes de ACL/RLS/ateste com servidor real, PSP ou workflow. Os cenários SQL enumerados acima permanecem pendentes, inclusive o novo ateste em cada conexão. O comando proposto para executor permitido permanece na seção própria; não foi executado aqui. Nenhuma nova publicação foi tentada; aprovação pendente de conteúdo anterior não se aplica automaticamente a este conteúdo. O inventário de oito arquivos acima é o inventário consolidado exato desde #921. Próximo passo: revisar este novo patch e, em executor autorizado com sockets, executar o harness isolado; manter separada a decisão intraempresa antes de qualquer operação real.
