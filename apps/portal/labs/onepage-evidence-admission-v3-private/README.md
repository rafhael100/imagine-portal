# Admissão D0 sintética — v3 privada para revisão

Base nominal: #940 `1b394f9826f623dec02beffcd2528b6a17d0c51e`. Os 77 arquivos-base usados na composição foram comparados por Git blob com a árvore completa desse pin. Não se alega checkout completo desse SHA. `base-pins.json` registra as dependências. AGENTS.md de apps/portal foi lido; a árvore pinada não contém .agents/skills. Nenhum arquivo herdado foi alterado, incluindo fixture_bind_charge, Guard, runner #940 e os 15 arquivos protegidos. v1/v2 permanecem intactos.

A autorização posterior Sentinel_61f1b06bf2848191a6380f444206c22b, em resposta ao pedido Sentinel_7f4276ad897c8191b45afda2a5c42a8b, cobre a concessão interna de INSERT ao owner NOLOGIN existente, restrita à única D0 sintética previamente identificada. Não cobre ID oficial, serviço financeiro, dados reais ou publicação.

## Implementação

1. A composição reutiliza somente preparação nativa e barreiras de #940. Remove as chamadas aos casos Atomic/Recovery verdes. Cria dois pedidos por create/review/accept nativos; bootstrap prepara termos e reservas sintéticas. O comprador, com sua sessão nativa, chama fixture_claim_charge para A. B inicialmente permanece não tentado.
2. Antes de criar o produtor/conceder EXECUTE, o bootstrap fixa a fonte sintética `admit-v1`, evento `sourceEntryA`, ID `pay_LABadmissionA` e a linha setup-1 do pedido A. Congela o JSON completo da testemunha: pedido/tenant/aceite/contrato, conta/ambiente, cliente/referência, centavos/moeda/vencimento, fonte/evento e ID. O digest é SHA-256 da representação JSONB canônica produzida pelo SQL do bootstrap. A fixture é a fonte confiável DESTE ENSAIO; não há alegação de resposta ou baixa de PSP.
3. AnchorDDL gera uma expressão literal hexadecimal desse JSON na policy e na função; o digest esperado também é literal. Hex evita interpretação de aspas, barras ou delimitadores do corpo PL/pgSQL. A função exige igualdade com essa âncora e revalida a reserva tentada. Um digest correto escolhido pelo produtor para outra cobrança não serve. Não há GUC, token ou tabela adicional de autoridade.
4. Único delta de privilégios: produtor LOGIN restrito recebe USAGE do schema e EXECUTE da nova função; owner NOLOGIN recebe INSERT somente em fixture_binding_witnesses. Policy de INSERT exige session_user produtor, linha inteira igual à âncora e digest igual ao esperado. ENABLE/FORCE RLS e políticas anteriores permanecem. Preflight SQL recusa policy de INSERT pré-existente ou owner já com INSERT. Nenhum UPDATE/DELETE/table access/role membership é dado ao produtor; nenhum atributo dos papéis existentes é alterado.
5. fixture_bind_charge continua intocada e exclusiva do comprador nativo. A função nova grava somente testemunha, sem vínculo, cobrança, confirmação, NextStep ou briefing. Imutabilidade é contra os papéis restritos; o administrador descartável continua capaz de modificar fixtures.

## Teste integrado preparado (NÃO executado em PG)

- A com reserva tentada; B sem tentativa, depois B tentado pelo próprio comprador. Admissão de B recusada em ambas as fases, inclusive reutilizando ID/evento de A.
- Divergência de pedido, tenant/contexto, linha, conta/ambiente, cliente/referência, valor/moeda/vencimento, ID/evento e digest. Digest é recomputado nos negativos para excluir a falsa prova de apenas hash incorreto.
- NULL em cada argumento; JSON null/lista/escalar/objeto incompleto; campos inválidos; UUID e JSON malformados rejeitados com SQLSTATE 22P02 permitido e sanitizado. Nenhuma mensagem PDO/SQL é impressa.
- Catálogo: FORCE RLS, uma policy de INSERT, owner sem login/bypass/superuser, INSERT sem UPDATE/DELETE, produtor sem membros/atributos elevados, sem tabela e apenas uma função executável no schema de laboratório. Acessos diretos e caminho de vínculo recebem 42501.
- Antes de commit e após rollback o auditor não vê testemunha. No commit, uma segunda sessão produtora real tenta o mesmo evento/ID. O auditor verifica `pg_blocking_pids` antes de permitir commit, então o filho deve retornar replay. Não se usa somente sleep como prova de concorrência.
- Nova conexão auditora, com PID distinto, relê exatamente uma linha, JSON canônico e digest esperados. Replay conserva conteúdo/digest/xmin. Pedidos/bindings permanecem iguais ao baseline. Nenhuma jornada verde de #939/#940 é reexecutada.

## Sessões, isolamento e limites de processo

Produtor tem login próprio. Atestação correlaciona session_user/current_user, banco, loopback/porta, cluster start, atributos/memberships e backend observado por auditor separado. O consumidor não entrega sessão ao produtor. O filho recebe somente ambiente estéril e dados sintéticos de teste; não recebe DB_USERNAME/DB_PASSWORD/bootstrap ou sessão de comprador. Seu DSN usa exclusivamente o papel produtor em loopback. O bootstrap existe somente no fixture/auditor de teste.

O processo PHP filho tem I/O limitado, deadline e término/recolhimento próprios; não sinaliza PostgreSQL ou shell de limpeza. A composição conserva o bounded.py e trap do runner #940. Limite externo do futuro job continua último recurso, jamais prova de teardown.

`compose.py` mantém somente `--check`, sem modo de execução. A função Python `assemble(portal,tmp)` prepara a fixture/launcher/worker para revisão e futuro executor compatível; a CLI atual apenas monta em diretório temporário, linta e remove. A montagem PHP v3 está completa, mas ela só pode ser executada após revisão independente, em cluster NOVO descartável com as barreiras herdadas de namespace somente loopback, configuração efetiva Laravel, processo/pg_controldata e identidade SQL completas. O DDL isolado não substitui tais preflights. Netlink já recusado neste executor; não foi tentado novamente.

## Resultados realmente obtidos

- `python3 check.py`: checks estáticos de escopo, ordem, modo somente offline e preservação integral de v1/v2 + 15 arquivos publicados: PASS.
- `python3 compose.py --check <portal-base> <php8.4> <bibliotecas-extraídas>`: 77 blobs, 9 lints PHP, 2 bash -n e 18 casos de fatos da atestação: PASS.
- PHP 8.4.24 extraído, `env -i PATH=/usr/bin:/bin LC_ALL=C LD_LIBRARY_PATH=<bibliotecas-extraídas> <php8.4> -n offline.php`: 19 checks novos de renderização/recusa/escaping seguro e processo filho (sucesso, timeout e limite de saída): PASS. Sem PDO nesses testes.
- Leitura estrutural do patch com `git apply --stat`: preparada na exportação, sem aplicar.

NÃO executados: parser PostgreSQL, aplicação DDL, catálogo/ACL/RLS efetivos, readback/transações, concorrência SQL e teardown PG. Nenhum software instalado. Nenhum workflow criado/disparado, chamada Asaas/VPS, credencial real, rede operacional, publicação, merge ou deploy.

Risco residual principal: sem PG18 funcional não se pode declarar válida a sintaxe/semântica SQL nem a interação real de RLS, locks, sessões e transações. Os testes PHP de arrays/renderização não substituem essas provas. A âncora é deliberadamente de uma única fixture e não é uma arquitetura operacional de ingestão. D30/D60/D90, preços e regras de liberação não mudaram.

Próximo passo: revisão independente do patch v3, especialmente igualdade de âncora na policy/função, depois execução única do novo recorte em executor descartável compatível. Não repetir as suítes verdes como objetivo nem remover preflight por limitação do ambiente.
