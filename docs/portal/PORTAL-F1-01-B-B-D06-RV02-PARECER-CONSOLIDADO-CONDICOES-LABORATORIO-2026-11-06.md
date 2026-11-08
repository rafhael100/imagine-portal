# F1-01-B-B-D06-RV02 — Parecer técnico consolidado e condições para laboratório PostgreSQL

> **Decisão posterior DEC-05 (06/11/2026):** [PostgreSQL 18 + PDO direto sem persistência, PHP 8.3 e 8.4, sem PgBouncer no primeiro laboratório](PORTAL-F1-01-B-B-DEC-05-BASELINE-DOCUMENTAL-PG18-PDO-DIRETO-2026-11-06.md) foram **ratificados exclusivamente como baseline documental preliminar**. As células E83/E84/N84 e testes de transaction pooling citados nesta RV02 ficam **ADIADOS** para fase separada, não aprovados/homologados; permanece obrigatória a prova de troca de tenant no perfil direto D83/D84 após autorização futura específica. Nenhum laboratório, SQL, migration, merge ou deploy autorizado.

**Data:** 06/11/2026, America/Sao_Paulo. **Estado:** revisão documental com ressalvas; não homologado para código, laboratório ou produção. **Autorização:** revisão pontual do responsável, incluindo atualização do D06, complemento RV02 e comentário no PR #25. Nenhum SQL executável, migration, instalação/configuração de pooler, conexão PG ou teste de banco foi criado/executado.

## 1. Controle da revisão e precedência

- Acesso Git privado autenticado confirmado por leitura de refs. HEAD inicial do [PR #25](https://github.com/rafhael100/imagine-portal/pull/25): `4cd28fcc6cec9724141db625d8d1d63c25bd9435`; aberto, draft e não integrado.
- Trabalho exclusivamente na branch `docs/portal-f1-01-b-b-d06-gates-2026-11-06`. Árvore local limpa antes das edições, sem sobrescrever trabalho paralelo. Dev continua em `0aef6838b0452fcbb195fcad2e50aed748ec41c5`; `main` em `50b683a499e674641df5749051f3d05f7ac3f627`.
- [PR #19](https://github.com/rafhael100/imagine-portal/pull/19): aberto/draft/não integrado, branch de planejamento em `75b32cef4afa0e7eae83e3b30fdc13c571a767d4`. D01–D05 e DEC-01–04 lidos integralmente, sem alteração de conteúdo desde a referência do D06; não existem na árvore dev por inferência. Os dois PRs continuam distintos.
- [Parecer RV01 publicado no PR #25](https://github.com/rafhael100/imagine-portal/pull/25#issuecomment-6687391113) lido integralmente. As seis ressalvas são respondidas pelas seções RV02-01–06 abaixo. O CI Node citado nesse parecer não valida PG/RLS.
- [Issue #17](https://github.com/rafhael100/imagine-portal/issues/17) permanece aberta. DEC-01–04 ratificam apenas diretrizes preliminares A: schema compartilhado/FKs/RLS, vínculo canônico, papéis versionados/projeção unidirecional, barreira de revogação, avaliador privado mínimo revisado, contexto transacional, autoridade Portal, privacidade por finalidade e laboratório futuro sob autorização separada. Não se solicita ratificação genérica dessas escolhas novamente.

Este complemento prevalece nos esclarecimentos pontuais de versão/compatibilidade e algoritmo; não apaga a proposta inicial D06, preservada no commit acima. PG17 era recomendação, não decisão ratificada. Os requisitos de segurança continuam os mesmos; nenhum parecer humano ausente foi suprido por esta revisão do autor.

## 2. RV02-01 — Comparação PostgreSQL 17 e 18

Fontes oficiais consultadas em 06/11/2026: [política PostgreSQL](https://www.postgresql.org/support/versioning/), [release PG18](https://www.postgresql.org/docs/18/release-18.html), [Laravel 13/database](https://laravel.com/docs/13.x/database), [Laravel 13/releases](https://laravel.com/docs/13.x/releases), [driver PHP](https://www.php.net/manual/en/ref.pdo-pgsql.php) e [imagens oficiais PostgreSQL](https://github.com/docker-library/docs/blob/master/postgres/README.md). Disponibilidade documental não é manifesto de imagem puxada nem ensaio local.

| Critério | PostgreSQL 17 | PostgreSQL 18 | Consequência para o Portal |
|---|---|---|---|
| Suporte comunitário | Até 08/11/2029 | Até 14/11/2030 | 18 oferece cerca de um ano adicional; ambas suportadas na consulta |
| Correções de segurança | Minor da major suportada; atualizar conforme advisories/release notes | Mesma disciplina | Não fixar imagem antiga indefinidamente nem promover atualização sem ensaio |
| Laravel 13 | Coberto pelo suporte PostgreSQL documentado | Coberto pelo suporte PostgreSQL documentado | Lock local `laravel/framework v13.40.0`; nenhuma incompatibilidade local demonstrada nem combinação homologada |
| PHP 8.3/8.4 | Conexão por PDO_PGSQL/libpq; não depende do número do PHP coincidir com PG | Mesmo caminho | Testar cada PHP separadamente; requisito de pool/prepares é de cliente, não da major do servidor |
| PDO/pdo_pgsql/libpq | Cliente compatível e patchado, build/ABI a registrar | Idem; servidor 18 não obriga usar uma libpq chamada 18 | Baseline candidata libpq 17 corrigida em ambos os PHPs; não deduzir libpq pela tag do banco |
| RLS/FKs/locks | Atende aos mecanismos do modelo proposto | Atende aos mesmos mecanismos | PG18 não elimina TOCTOU, bypass por role privilegiada ou bootstrap inseguro |
| Recursos novos | Sem as novidades PG18 | AIO, UUIDv7 e mudanças de upgrade, entre outras | Nenhum recurso novo é necessário para isolamento; UUIDv4 local continua recomendado, sem trocar por UUIDv7 automaticamente |
| Imagens oficiais | Família 17, variantes Debian/Alpine | Família 18, variantes Debian/Alpine | Fixar minor + variante + arquitetura + digest; sem `latest`/tag flutuante como prova |
| Layout da imagem | Layout de dados anterior ao 18 | PGDATA versionado e ponto de volume alterado na imagem oficial | Não reutilizar montagem de 17 em 18 sem revisão; laboratório descartável novo, sem copiar volumes |
| PgBouncer | Condicionado à matriz cliente/pool, não ao PG17 por si | Mesma condição | Nenhuma evidência de estabilidade operacional comparativa foi produzida |
| Upgrade/manutenção | Necessita major upgrade mais cedo | Horizonte de suporte maior | Plano de upgrade inclui revisão das notas, ferramentas compatíveis e restauração; não simples troca de tag |
| Custo | Sem licença comercial obrigatória no plano comunitário; operação/CI custam recursos | Mesmo modelo, custo operacional a medir | Sem benchmark ou orçamento aprovado; não alegar economia de CPU/memória de uma versão |

**Recomendação revisada:** PG18 para o primeiro laboratório futuro, por se tratar de projeto novo sem migração de dados e pelo suporte mais longo, com custo de validar o layout da imagem e a mesma bateria de segurança. Não há evidência objetiva de que escolher PG17 torne este desenho mais seguro ou estável. PG17 permanece fallback aceitável se Plataforma demonstrar limitação de imagem/driver/ambiente ou custo. Escolher 17 exigiria registrar essa justificativa e aceite específico, sem reabrir a direção arquitetural.

**Decisão concreta para avaliação:** aceitar a major PG18 proposta, ou justificar PG17; fixar minor corrigida, variante Debian, arquitetura e digest no manifesto antes da execução autorizada. Não inventar digest nesta documentação. A lista oficial consultada oferece famílias 17 e 18 com tags minor; a resolução do artefato exato permanece pendente. Revisão anual de suporte e preparação do upgrade pelo menos seis meses antes do EOL são propostas de manutenção. **Dono:** Arquitetura/Plataforma; revisão Backend/DBA. **Estado:** recomendação documentada, versão não aprovada/homologada.

## 3. RV02-02 — PHP, libpq, PDO e PgBouncer

### 3.1. Semântica e legenda

**D = compatibilidade documentada upstream**, **N = não suportado no perfil definido**, **V = combinação ainda não verificada neste projeto**. Uma célula D continua V para homologação local. Não presumir PHP8.3 a partir de PHP8.4.

[PDO_PGSQL](https://www.php.net/manual/en/ref.pdo-pgsql.php) exige libpq; PHP8.4 tem mínimo documentado libpq10. Isso não satisfaz automaticamente o requisito mais restrito de prepared statements PDO com pool transacional. [FAQ PgBouncer](https://www.pgbouncer.org/faq.html) condiciona esse caminho a PHP8.4+ e libpq17, ou desativação de prepares no cliente nas combinações anteriores. PgBouncer 1.21 introduziu tracking dos preparados em protocolo; um build corrigido e fixado deve ser escolhido, não usar uma versão antiga só por atender ao piso. [Configuração PgBouncer](https://www.pgbouncer.org/config.html).

Os três modos não são equivalentes:

- **Nativo nomeado:** `PDO::ATTR_EMULATE_PREPARES=false` e `PDO::PGSQL_ATTR_DISABLE_PREPARES=false`; cria prepared statement nomeado no servidor. No perfil pooled precisa tracking compatível.
- **Emulado:** `PDO::ATTR_EMULATE_PREPARES=true`; PDO faz tratamento de parâmetros no cliente sem server-side named prepares. Continuar usando parâmetros/validações, nunca interpolação manual. Conferir parsing e tipos por PHP; não presumir semântica idêntica entre versões.
- **Parametrizado sem nome:** `PDO::ATTR_EMULATE_PREPARES=false` e `PDO::PGSQL_ATTR_DISABLE_PREPARES=true`; alternativa do driver para enviar consulta/parâmetros sem preparar nome separadamente. Não é emulação. Fica perfil experimental separado até prova local e aceite DBA.

A constante com prefixo `PDO::PGSQL_...` serve ao código que precisa abranger PHP8.3/8.4; a classe `Pdo\Pgsql` só existe a partir de 8.4. A nomenclatura e o comportamento das opções devem ser conferidos no driver efetivo; nenhum código é criado aqui. [Manual Pdo\Pgsql](https://www.php.net/manual/en/class.pdo-pgsql.php), [atributos PDO](https://www.php.net/manual/en/pdo.setattribute.php).

### 3.2. Matriz candidata de perfis

Em todas as linhas, servidor **PG18 proposto ou PG17 fallback**, `pdo_pgsql` correspondente ao mesmo build/ABI de PHP, PDO não persistente, transação explícita e primário. Emulador não resolve origem do ator nem isolamento por si.

| Perfil | PHP / libpq | Caminho e prepared statements | Situação upstream / local | Restrição e prova futura |
|---|---|---|---|---|
| D83 | PHP8.3 + libpq17 corrigida | Direto; nativo nomeado | D no modelo PDO direto / V neste projeto | Sem PgBouncer; testar criação/destruição/reexecução de statements e bindings |
| D84 | PHP8.4 + libpq17 corrigida | Direto; nativo nomeado | D / V | Célula independente de D83; registrar build de extensão/libpq |
| E83 | PHP8.3 + libpq17 corrigida | Transaction pooling; emulado; `max_prepared_statements=0` explicitado como perfil sem tracking | Mitigação documentada FAQ / V | Não enviar named prepares; testar Unicode, aspas, null/bool/números, placeholders e erros; sem SQL dinâmico cliente |
| E84 | PHP8.4 + libpq17 corrigida | Mesmo perfil emulado, sem tracking | Mitigação documentada / V | Controle de comparação independente; não inferir sucesso de E83 |
| N84 | PHP8.4 + libpq17 corrigida | Transaction pooling; nativo nomeado; PgBouncer corrigido 1.21+ com `max_prepared_statements=100` como valor candidato | D condicionado / V | Testar fechar statement, troca de backend e invalidação; 100 é hipótese de capacidade, não tuning aprovado |
| N83 | PHP8.3, mesmo que libpq17 | Transaction pooling; nativo nomeado/tracking | N pelo requisito PHP do FAQ | Não aprovar nem tentar resolver apenas aumentando `max_prepared_statements` |
| N84-old | PHP8.4 + libpq10–16 | Transaction pooling; nativo nomeado/tracking | N pelo requisito libpq do perfil | Mínimo de instalação PHP não equivale a requisito de pooling; atualizar cliente ou escolher emulação |
| NoTrack | PHP8.4 + libpq17 | Transaction pooling; nomeados com PgBouncer sem tracking ou anterior a 1.21 | N para reutilização entre backends | Não confundir conexão ocasional funcional com suporte ao perfil |
| U83/U84 | PHP8.3 e PHP8.4 + libpq17, separadamente | Transaction pooling; parâmetros sem nome, disable-prepares true e emulação false | Mecanismo descrito pelo driver / V end-to-end | Perfil experimental; não substituir E83/N84 sem evidência própria |
| OtherPQ | PHP8.3/8.4 com libpq18 ou outra versão não fixada | Direto/pool conforme modo | V para matriz efetiva | Não inferir build/patch ou tratamento de fechamento a partir do servidor18; fixar/verificar versão cliente |
| Statement | Qualquer PHP/libpq | Statement pooling | N para contrato Portal | Não preserva transação com múltiplas instruções; excluído |

Baseline de laboratório proposta: D83 e D84 obrigatórios como controles; E83 para o PHP8.3 pooled e N84 para PHP8.4 pooled, com perfis de pool separados para não misturar tracking=0/100. E84 pode ser controle adicional; U83/U84 não são substitutos homologados. Um perfil não aprovado fica desabilitado; decidir não usar pool em primeiro laboratório exige exclusão explícita e mantém gate pooled aberto. Não reduzir secretamente a matriz para facilitar resultado.

### 3.3. Configurações candidatas e restrições

| Aspecto | Contrato para execução futura autorizada | Evidência/risco |
|---|---|---|
| Driver efetivo | Registrar PHP patch/ABI, `pdo_pgsql`, libpq de compilação e runtime, arquitetura/OS | Extensão compilada com headers novos mas biblioteca antiga não é baseline comprovada; bloquear manifestos ambíguos |
| PgBouncer | Fixar release corrigida 1.21+ e artefato; `pool_mode=transaction`; runtime sem login de administração do pool | Piso de recurso não é recomendação de versão desatualizada; build exato segue pendente |
| PDO | Não persistente; exceções de erro; modo de prepares explicitado por perfil e verificado no handle real | Laravel pode fornecer defaults; não assumir que variável de ambiente foi aplicada |
| Limites de laboratório | Até dois backends runtime candidatos para forçar reuso; ao menos dois clientes concorrentes e terceiro para prova de reatribuição | Dimensionamento proposto, sujeito à quota; afinidade deve ser comprovada por correlação/backend |
| Contexto | Local à transação, mesma conexão antes de leitura privada; nunca SET de sessão para identidade | Commit/rollback encerra unidade; savepoint anterior pode reverter contexto; sem estado global no worker |
| Recursos de sessão | Sem prepared SQL manual, temp persistente, LISTEN, lock advisory de sessão ou cursor após commit | Tracking em protocolo não torna PREPARE/DEALLOCATE SQL compatíveis com transaction pooling |
| TLS/topologia | Rede isolada; fora de socket/local descartável, validar certificado/hostname das duas pernas cliente→pool e pool→PG | Não copiar `sslmode=prefer` do scaffold como baseline real |
| Falhas | Reconexão/retry sempre nova unidade de autorização; conexão incerta descartada | Reset de pool não substitui rollback; não depender de `server_reset_query` como limpeza de tenant em transaction pooling |

Provas mínimas por D83/D84/E83/N84: bindings e tipos acima; lifecycle de statement e erro; três clientes alternando X/Y, commit/rollback/timeout/cancelamento; correlação da mesma conexão dentro da transação e reatribuição só depois; nenhum contexto herdado. Identificar biblioteca cliente sem imprimir DSN/credenciais. H07/C08/P07 recebem esses parâmetros, sem renumeração e **sem execução agora**.

## 4. RV02-03 — Contrato revisado de identidade e RLS

### 4.1. Fronteiras obrigatórias

Identidade humana só do Portal; CRM conserva seus autenticadores, cookies, sessões, credenciais e banco. ID externo, e-mail/CNPJ ou mesmo texto de ID não conferem acesso. `DemoPortalSession` permanece não confiável; não é adaptador de identidade real. Front-end escolhe intenção de empresa; ator, empresa, vínculo canônico, grants atuais e dono do recurso são resolvidos por autoridades locais oficiais. Bootstrap só retorna referências mínimas das empresas elegíveis do próprio ator comprovado, sem enumerar membros/recursos de terceiros.

Contexto SQL é transporte de valores, não assinatura nem prova de autenticação. Uma aplicação comprometida com a credencial compartilhada pode forjar variáveis: o desenho não resiste automaticamente a runtime hostil. Não afirmar que a função resolve esse problema lendo o mesmo contexto que o atacante altera. Laboratório pode simular emissor local por fixture restrita ao executor, sem endpoints; uso real exige contrato do autenticador Portal e aceite do modelo de ameaça.

### 4.2. Avaliador privado e alternativas delimitadas

Manter a diretriz de avaliador mínimo; retorna somente booleano para capacidade fechada e contexto corrente, sem parâmetros que permitam consulta de alvo/tenant arbitrários. Leitura técnica de metadados se limita a elegibilidade; owner sem login e grants exatos, sem BYPASSRLS genérico. Runtime não lê diretamente esses metadados nem altera roles/grants por DML livre. Operações de escrita e locks são capacidades internas distintas, com alçada e barreira obrigatórias.

Grafo a revisar: recurso privado → policy de tenant/capacidade → avaliador → metadados privados → política técnica restrita ao leitor do avaliador. Essa última política não chama o avaliador novamente. Bootstrap é caminho separado, restrito ao próprio ator. Nomes qualificados e busca fixa em schemas protegidos, sem resolução por `public`/temp ou SQL dinâmico; sem EXECUTE público desnecessário; sem marcação indevida leakproof/immutable. Listar tabelas, views, triggers, funções, partições e herança de roles, inclusive acesso direto às partições e caminhos de exportação.

Alternativas técnicas, sem reabrir aprovações A: (a) desenho direto mínimo de elegibilidade conforme diretriz; (b) projeção privada transacional, somente se análise provar custo/recursão impeditivos, sem segunda fonte de grants e com aprovação específica; (c) se resistência a runtime comprometido for requisito, autoridade de contexto independente ou separação de credenciais/execução por domínio de confiança. A alternativa (c) é mudança substancial, não promessa de proteção já fornecida por RLS/contexto. Nenhuma alternativa autoriza SSO/CRM.

Predicado tenant restritivo e conjunto permissivo mínimo por comando; avaliar linha antiga e nova nas alterações e organização imutável. Runtime sem superuser, owner, BYPASSRLS, DDL/TRUNCATE, replicação, assunção de roles elevadas ou criação em schema confiável. Owner/superuser e constraints têm limites conhecidos que RLS não neutraliza; erros externos são minimizados para evitar enumeração. [RLS oficial PG18](https://www.postgresql.org/docs/18/ddl-rowsecurity.html).

| Parecer faltante | Perguntas objetivas / saída exigida | Critério que bloqueia |
|---|---|---|
| Identidade | Qual emissor local, como se comprova ator/revisão, como bootstrap restringe o próprio ator, como fixture não vira autenticador | Origem arbitrária ou fixture aceita em tráfego real |
| DBA | Grafo sem recursão; owners/grants/assinaturas; leitura técnica mínima; locks sem UPDATE genérico; composição de policies | Runtime/role indireta alcança bypass, função/oracle ou metadados alheios |
| Segurança independente | Ameaça aceita sobre runtime, SQL injection/contexto, erro lateral e dependências de bootstrap | Alegar que contexto autentica pessoa ou que RLS contém runtime comprometido sem mecanismo externo |

Saídas são pareceres assinados por pessoas nomeadas, referenciando SHA e limitações. Este documento não afirma que foram obtidos. Provas futuras H02/H05/H06/C03/C06/C07/P01/P06/P08 continuam necessárias.

## 5. RV02-04 — Algoritmo de revogação e concorrência

### 5.1. Unidade de efeito local candidata

1. A autoridade Portal verifica ator e intenção; identifica referências candidatas do concedente/alvo, sem retornar dados privados. Define comando, ação, empresa e ID idempotente. IDs/papéis do cliente nunca determinam alçada. O conjunto de usuários envolvidos deve ser completo antes de iniciar locks.
2. Abrir transação explícita READ COMMITTED no primário. Estabelecer apenas contexto bootstrap restrito, insuficiente para liberar leitura de negócio. A operação privada de barreira deve validar origem/escopo e obter locks, sem dar ao runtime UPDATE genérico de usuários/organizações ou aceitar empresa arbitrária.
3. Ordem de aquisição para todos os caminhos: **usuários envolvidos por UUID → organizações por UUID → memberships → papéis/atribuições → marcador idempotente → recurso**, com ordem determinística dentro de cada conjunto. Lock exclusivo sobre organização é o ponto conservador de serialização de mudanças/efeitos naquele tenant; barreira do usuário cobre suspensão/revogação global. Nenhum caminho adquire usuário novo após organização. Se descobrir novo alvo/tenant, abortar e recalcular conjunto, não adquirir fora de ordem.
4. Após espera/locks, fazer **nova leitura em instrução posterior** de usuário, organização, vínculo, política, grants, revisões e recurso. READ COMMITTED permite snapshot atualizado por instrução; não confiar no snapshot de consulta/função que começou antes da espera. Uma função única que bloqueie e avalie com snapshot inadequado não satisfaz o contrato. DBA deve verificar a implementação futura desse limite entre instruções e política RLS.
5. Revalidar origem/estado e revisão esperada do comando; qualquer alteração de mandato não contemplada exige negar ou reconstruir decisão integral, nunca conservar um `allow`. Conferir validade com relógio atual após espera. Estabelecer contexto privado com referências/revisões validadas para esta unidade. RLS continua avaliando escopo/grant, sem substituir a barreira.
6. Com mesmos locks, conferir marcador: comando já concluído e fingerprint igual não causa novo efeito; fingerprint diferente na mesma chave gera conflito; resposta privada de operação antiga também exige autorização atual. Se a autorização foi revogada, não executar nem revelar resultado de negócio por idempotência.
7. Produzir efeito fictício + nova revisão + auditoria obrigatória + marcador na **mesma transação**, com última verificação temporal antes do efeito. Sem I/O externo, streaming ou job real dentro do lock. Concessão, alteração de política, suspensão, remoção de papel e último gestor seguem a mesma ordem/barreira. Mudança de catálogo global não ocorre no laboratório; não criar caminho de invalidação global que ignore barreiras.
8. Commit confirmado encerra a unidade. Revogação que obtém barreira primeiro conclui antes e impede efeito antigo; ação que obtém primeiro conclui antes do commit da revogação. Limite temporal de concessão é aferido antes do efeito, não promessa de que um commit nunca ultrapasse um instante de expiração durante espera interna; definir semântica mais estrita antes de uso que a exija. Nenhum byte já entregue é recuperado por revogação.

A função de locks e o avaliador precisam de revisão conjunta: se o mecanismo permite escolher livremente ator/tenant, ou se a política autoriza com revisão anterior após a espera, não há evidência suficiente para programar esse contrato. READ COMMITTED isoladamente não impede TOCTOU. REPEATABLE READ/SERIALIZABLE não devem ser usados como “correção automática”; qualquer troca requer projeto e nova bateria. [Isolamento oficial](https://www.postgresql.org/docs/18/transaction-iso.html), [contexto local e savepoints](https://www.postgresql.org/docs/18/sql-set.html).

### 5.2. Falhas e repetição

| Evento | Regra candidata e prova futura |
|---|---|
| Deadlock `40P01` / serialização `40001` | Rollback integral; nova transação/contexto/decisão, até 3 tentativas totais no deadline; backoff sem manter locks; nunca repetir `allow` |
| Lock indisponível/timeout `55P03` | Abortar; não tratar conflito como autorização. Retry apenas se explicitamente classificado e dentro do mesmo limite; negar após esgotar |
| Statement timeout/cancelamento `57014` | Rollback/descarte se incerto; sem retry automático genérico de cancelamento solicitado pelo usuário |
| Falha de rede/classe `08` em commit | Resultado inconclusivo; não prometer rollback. Conexão descartada; consultar marcador e efeito no primário, nova unidade e alçada |
| Marcador ausente após commit perdido | Ausência isolada não prova abort: transação original pode estar em curso. Nova tentativa com mesma chave só após readquirir mesma barreira e resolver conflito de unicidade/estado, sob prazo; se inconclusivo, bloquear e escalar |
| Marcador concluído e comando igual | Não reaplicar efeito; consulta minimizada e autorizada do resultado. Não reiniciar janela de replay |
| Savepoint/rollback parcial | Primeiro recorte aborta unidade inteira; não confiar em locks/contexto que podem ter sido desfeitos |
| Falha de auditoria | Grant/revogação/efeito e marcador não fazem commit; evento separado pode registrar tentativa/falha sem alegar sucesso |

Parâmetros apenas candidatos de laboratório: lock 1s, statement 3s, ociosidade em transação 5s, deadline de aplicação 10s e até 3 tentativas totais, contabilizando espera/reconexão. Deadline de aplicação não garante cancelamento automático do commit no servidor; requer cancelamento/descartes e reconciliação quando inconclusivo. Tempo de conexão/fila do pool e das duas pernas deve ser limitado por configuração futura revisada. Não usar PDO timeout genérico como garantia de timeout de query. Não estabelecer SLO operacional com esses números.

Direto e transaction pooling têm a mesma unidade semântica: mesma conexão backend até término; depois outra transação pode ir para outro backend. Nunca reabrir silenciosamente e continuar do meio. Troca X→Y encerra unidade/objetos anteriores; nova autoridade, vínculo e contexto; sem atualizar organização do recurso. Réplicas e cache positivo não autorizam efeitos sensíveis; jobs/exportações futuras revalidam por unidade no primário. Todo canal de mudança de grants deve participar da barreira, inclusive manutenção controlada.

### 5.3. Negativas coordenadas com duas ou mais transações — não executadas

| Família existente | Arranjo futuro | Prova objetiva mínima |
|---|---|---|
| H08/C04/P04 | T1 ação segura barreira; T2 revoga e espera; depois inverter ordem | Ordem por eventos/commit, não sleeps; ação antes da revogação ou nega depois; zero efeito antigo pós-commit da revogação |
| H08/C01/P05 | T1 altera papel/grant; T2 usa revisão antiga | Releitura após lock nega/reavalia; revisão e auditoria atômicas |
| H10/C02/P05 | Dois gestores tentam remover um ao outro simultaneamente | Serialização; nenhuma combinação confirmada deixa zero gestores elegíveis |
| H03/H08/P04 | Concessão expira enquanto T2 aguarda lock de T1 | Instante atual pós-espera impede efeito; snapshot de início não autoriza |
| H07/C08/P07 | T1 lock prolongado provoca timeout em T2; repetir com deadlock intencional no harness | Rollback completo, limite de tentativas, conexão limpa; teste de falha não vira ordem de locks do produto |
| H08/H09/C04/P09 | Resposta de commit perdida; terceiro cliente consulta e repete chave | Uma mutação/evento de sucesso; marcador consistente; ausência temporária não autoriza duplicidade |
| H07/C08/P07 | Três clientes alternam X/Y em poucos backends após commit, erro, rollback e cancelamento | Backend é afim dentro da unidade; nenhum contexto/permissão de X em Y; rodar por perfil PHP |
| H09/C05/P09 | Sessão/job/cache com grants antigos e exportação por lotes | Nenhuma próxima unidade com revisão antiga; sem promessa retroativa sobre bytes prévios |

DBA/Dados validam mecanismo de lock/snapshot e pontos de ordenação; Backend valida retries/marcador; Segurança revisa TOCTOU e falsificação; Plataforma revisa pool/conexão. Ainda faltam esses pareceres, não apenas benchmarks.

## 6. RV02-05 — Finalidade, retenção e restauração

Nenhum prazo legal é fixado por esta revisão. **S = sugestão técnica sem aprovação; P = prazo/termo pendente de Privacidade/Produto**. Todos os dados de laboratório são fictícios; IDs reais opacos continuam potencialmente pessoais. Não aplicar o prazo de leads à identidade.

| Classe / finalidade | Prazo/termo candidato | Acesso | Descarte e decisão pendente |
|---|---|---|---|
| Logs técnicos | S: 14 dias a partir do evento, não da última leitura | Operação autorizada, mínimo de diagnóstico | Expurgo por idade; sem sessão/token/payload/IP por padrão; Privacidade aprova finalidade/prazo antes de coleta real |
| Tentativas negadas | S: 30 dias da tentativa; agregar quando suficiente | Segurança/antiabuso, segregado por tenant comprovado | Não registrar identidade/empresa forjada como fato; retenção/limite de volume e incidentes sujeitos a aceite |
| Histórico de vínculos/permissões | P: termo inicial e prazo por ciclo/contestação ainda não aprovados | Segurança/Produto sob finalidade e alçada; sem diretório global | Canonicalidade não exige guardar PII para sempre; pseudonimização/expurgo sem perder integridade somente após política |
| Eventos de auditoria | P para concessões/revogações/ações; S para prova sanitizada de laboratório: 30 dias do resultado final | `audit.view` apenas no tenant; revisores de QA mínimos | Append-only no runtime; manutenção de retenção segregada; hold só com fundamento, responsável e reavaliação; não reiniciar prazo pela consulta |
| Marcadores idempotentes | P produção: horizonte replay ≤ guarda do marcador; laboratório: duração integral do ensaio e reconciliação | Operação técnica restrita ao mesmo tenant/ator/ação | Só expurgar quando não houver transação incerta/replay admissível. Após descarte, chaves antigas não viram comandos novos aceitos |
| Backups | P: RPO/RTO, agenda e guarda por classe; nenhum valor aprovado | Operador segregado, criptografia, acesso/restore auditados | Expirar cópias/replicas/snapshots segundo política; sem promessa de apagar seletivamente backup imutável sem mecanismo definido |
| Fonte de revogações para restore | P: cobrir todos os snapshots que ainda podem ser reabertos e a lacuna até reconciliação | Segurança/Plataforma, domínio de falha independente | Sem fonte completa/confiável, manter acesso fechado; retenção/integridade/custo precisam parecer |

Proteções comuns: sem senha/hash/token, dado de cliente, e-mail/CNPJ/CPF real ou conteúdo contratual em Git/log; timestamp de servidor e correlação; eventos de mudança obrigatórios atômicos; eventos de tentativa distinguem resultado; evidências sanitizadas sem exportar linhas privadas. Bases legais, atendimento a direitos, anonimização e exceções por obrigação/hold são atribuição de Privacidade/Encarregado, não decididas por Codex.

**Restauração antirressurreição, proposta:** (1) restaurar isolado com ingressos, workers, jobs e exports bloqueados; (2) conferir schema, versão, role memberships, RLS, catálogo e parâmetros de segurança, não somente conteúdo de tabelas; (3) identificar instante/revisão do snapshot e provar completude da fonte de mudanças até o ponto de reabertura; (4) reconciliar revogações de usuário/empresa/vínculo/papel/grant e comandos inconclusivos, sem restaurar allow/cache/sessão antigos como prova; (5) invalidar sessões/contextos e exigir nova autoridade atual; (6) rodar H12/C09/P11 com negação de grant revogado; (7) DBA/Segurança autorizam estado técnico e responsável decide reabertura separadamente. Se fonte de eventos tiver lacuna, não reabrir por “melhor esforço”: invalidar acessos e exigir reconcessão controlada, ainda sem processo real aprovado. Bytes já divulgados ou grants em sistema externo não são revertidos pelo restore Portal.

**Pendências:** Privacidade/Encarregado aprovar finalidades/prazos/termos/acessos; Produto/Dados definir história necessária; Plataforma/Segurança escolher fonte de revogações, integridade/continuidade, RPO/RTO/guarda/custos e procedimento de reabertura. AGI-11-RB01 permanece desabilitado. Esses pareceres não foram obtidos e nenhum backup foi acessado/restaurado.

## 7. RV02-06 — Consolidação dos oito gates

Evidências E1–E4 mantêm a definição do D06: inventário de versões/grants/policies, asserções de linhas/efeitos, ordem transacional coordenada, logs/restore sanitizados. **Todos os 34 IDs H01–12/C01–10/P01–12 continuam não executados**, com linhas individuais preservadas no D06; os complementos desta RV02 detalham as famílias, não as substituem. Controles positivos são obrigatórios para descartar falso sucesso de uma implementação que apenas nega tudo.

| Gate | Contrato documentado / decisão específica pendente | Risco crítico / dependências | Dono e revisor propostos | Evidência mínima e condição antes do laboratório | Estado real |
|---|---|---|---|---|---|
| G-BB01 | Comparação 17/18, PG18 recomendado; fixar major/minor/digests, build libpq e perfis PHP/pool, custos | Matriz nativa pooled incompatível; depende 06/08 | Arquitetura/Plataforma; Backend/DBA | Parecer de baseline e manifesto planejado; E1/testes só na fase autorizada | Documentado; aceite/ambiente pendentes |
| G-BB02 | UUIDv4, ERD/FKs/índices e marcador privado mantidos; finalizar limites/tipos | FK/owner permite tenant cruzado; depende 03/04/05/07 | Dados; DBA/Segurança | Revisão do inventário incluindo auxiliar idempotente, H04/C09/P02 futuros | Documentado; revisão física pendente |
| G-BB03 | Origem Portal/CRM, bootstrap e fixture separados; aceitar emissor/ameaça | Contexto forjado confundido com autenticação; depende 02/05/06 | Identidade; Segurança | Parecer de fonte por entrada; H02/C03/P01 planejados | Documentado; autoridade real bloqueada |
| G-BB04 | Catálogo/alçadas, último gestor e reingresso bloqueado; validar mandato/roles | Autoelevação/último gestor/corrida; depende 02/03/06/07 | Produto/Dados; Segurança | Aceite da matriz e exclusões; C01/C02/P03/P05 futuros | Documentado; política/recuperação pendentes |
| G-BB05 | Roles/policies/avaliador mínimo e grafo sem ciclo; revisar função e lock | Bypass/oracle/recursão; depende 02/03/04/06 | DBA; Segurança independente | Parecer assinado de grants, grafo e trust boundary; E1/H05/H06/C06/C07/P06/P08 futuros | Documentado; parecer independente ausente |
| G-BB06 | Ordem completa, releitura pós-lock, marcador e perfis; aprovar algoritmo/limites | Snapshot antigo, deadlock e commit duplicado; depende 01/03/04/05/07 | Backend/Dados/Plataforma; DBA/Segurança | Revisão da barreira e plano de injeção de falhas; E3/H07–09/C04/C05/C08/P04/P07/P09 futuros | Documentado; nenhuma garantia ensaiada |
| G-BB07 | Classes, prazos S/P e restore reconciliado; aceitar política sintética e excluir dados reais | PII/guarda indefinida/ressurreição; depende 02/04/06/08 | Privacidade/Plataforma; Segurança | Aceite descarte/provas do laboratório; política real segue bloqueada; H11/H12/C10/P10/P11/P12 futuros | Documentado; retenção/restore pendentes |
| G-BB08 | Matriz 34 IDs + complementos e manifestos por perfil; nomear QA e revisores | CI Node/mocks ou perfil errado confundidos com prova; depende todos | QA; DBA/Segurança independentes | Plano rastreável com controles positivos, zero vazamento/efeito indevido, tratamento explícito de perfil excluído | Documentado; não executado/não homologado |

Dependências cíclicas aqui representam contratos a revisar conjuntamente, não exigência de executar banco antes de autorizar código. O pacote documental deve ficar consistente entre 03/05/06 antes da decisão de laboratório; provas operacionais são saídas da fase posterior autorizada, não condição para violar a proibição atual de testes.

## 8. Pacote da próxima decisão — pedido proposto, não submetido como implementação

**Recorte mínimo futuro:** duas empresas fictícias, dois usuários Portal simulados, mesma string CRM para negativa de origem, vínculos/papéis/capacidades limitados, um recurso privado, runtime mínimo/RLS, auditoria e marcador idempotente; sem endpoints, autenticação/MFA, contratos, dados reais, integrações/CRM, VPS ou deploy. Profiles D83/D84 e E83/N84 são o plano recomendado; eventual laboratório só direto deve excluir pool nominalmente e preservar seu gate em aberto.

**Condições para poder solicitar autorização específica:**

1. Arquitetura/Plataforma registram major escolhida e manifestos planejados, quota/custo máximo e destruição, sem provisionar agora.
2. Identidade, DBA e Segurança nomeados emitem pareceres RV02-03, com aceitação explícita de identidade simulada apenas no laboratório e limite do runtime confiável.
3. Dados/Backend/Produto validam FKs/capacidades, algoritmo de locks, revalidação, último gestor, marcador e semântica de commit inconclusivo. Perfil nativo PHP8.3 pooled é excluído; nenhum retry genérico com allow antigo.
4. Privacidade/QA validam coleta estritamente fictícia e guarda/descarte das provas; dados reais continuam excluídos enquanto política final/backup/autenticador não forem aprovados.
5. QA consolida testes 34 IDs por perfil aplicável, controles positivos, provas/critério de parada e revisão independente. Qualquer dispensa de cenário precisa justificativa e aceite de DBA/Segurança; não pode eliminar o teste central do risco liberado.
6. Responsável recebe pacote com SHA base/branch atualizados, arquivos e operações permitidos, ambiente, duração/limite de custo, regras de parada e exclusões. Nenhuma ordem anterior de documentação é citada como autorização de execução.

**Proposta de redação de uma futura ordem, ainda sem efeito:** “Autorizar exclusivamente o recorte de laboratório especificado e revisado por SHA, em branch filha isolada, com código/migrations/testes sintéticos e ambiente/custos limitados conforme manifesto aprovado. Sem dados reais, login, CRM, VPS, merge ou deploy. Interromper em vazamento, bypass, falta de autoridade simulada controlada, resíduo de conexão ou revogação/commit inconclusivos sem reconciliação. Entregar PR draft e evidências por cenário para revisão.” Preencher perfis/versões/limites/pareceres faltantes antes de solicitar essa ordem; este texto não a concede.

| Etapa | Autorização/evidência | Situação |
|---|---|---|
| Documentação RV02 | Instrução atual; atualizar D06/complemento e comentar PR25 | Autorizada; sem execução funcional |
| Código de laboratório | Ordem específica futura após pareceres e recorte/ambiente fechados | Não autorizada |
| Instalação/SQL/testes PG/pool | Ordem futura deve incluir explicitamente execução/ambiente/limites | Não autorizados; nada executado |
| Homologação | Evidências por cenário/perfil + parecer independente | Ausente; não decorre de CI Node |
| Merge em dev ou qualquer branch | Revisão de diff/SHAs/precedência e ordem posterior própria | Não autorizado |
| `main`, VPS, dados reais e deploy | Gates separados de produção/identidade/LGPD/infra | Não autorizados |

**Impedem programação agora:** falta da ordem funcional; baseline/perfis/custos não aceitos; emissores/bootstrap e ameaça ainda sem parecer; avaliador/locks sem revisão independente; algoritmo de snapshots/retries/commit e alçadas sem aceite; ambiente e política de provas não definidos por donos nomeados. Não exigir nova votação genérica de alternativas A, nem fingir aprovação técnica com documento escrito pelo próprio autor.

## 9. Correções, evidências de revisão e limites

| Ressalva RV01 / revisão | Correção documental |
|---|---|
| RV02-01 | Comparação técnica PG17/18 e recomendação PG18 para avaliação, preservando a proposta PG17 inicial no histórico |
| RV02-02 | Modos emulado/nomeado/sem nome separados; matriz PHP8.3/8.4 e libpq; tracking e perfis explícitos; N83 excluído |
| RV02-03 | Autoridade/fixture/contexto separados, grafo privado e pareceres independentes exigidos; sem promessa de resistência a runtime hostil |
| RV02-04 | Ordem completa, nova leitura após locks, erros/retry/deadline, marcador e commit inconclusivo; negativas coordenadas por H/C/P |
| RV02-05 | Histórico, auditoria, negativas, logs, marcadores e backups separados; prazos S/P e fonte de revogações para restore |
| RV02-06 | Oito gates com donos, dependências, bloqueios e evidências; 34 cenários preservados; promoção documental/código/testes/merge/deploy distinta |

D06 foi corrigido nos pontos correspondentes e vincula este parecer. A comparação com o HEAD inicial deve mostrar apenas dois Markdown em `docs/portal/`. Validação feita nesta rodada é documental/estática: cobertura dos seis itens e oito gates, preservação dos 34 IDs, links/fontes oficiais e diff sem whitespace inválido. Não alegar sintaxe/funcionamento de SQL que não foi criado, nem resultado de teste PG que não foi executado. Fontes externas são links consultados na data acima, não cópias integrais; nenhuma tag/digest foi instalada.

**Parecer final:** ressalvas documentais esclarecidas; faltam decisões pontuais e pareceres humanos indicados. PR #25 deve permanecer aberto em rascunho, PR #19 preservado e Issue #17 aberta. G-BB01–08, D02-SEC01..06, D03 físico/RLS, P0-DOC e H/C/P continuam abertos/não homologados. Nenhuma implementação da F1-01-B-B pode ser iniciada sem nova autorização expressa.
