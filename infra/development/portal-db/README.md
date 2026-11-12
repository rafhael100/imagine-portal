# Banco privado persistente — desenvolvimento do Portal

Pacote separado da stack F64 e do laboratório descartável. Cria PostgreSQL 18 com volume Docker e rede interna, sem nenhuma porta publicada. O serviço provision só roda explicitamente pelo perfil tools. Contém instalador e verificador; não é aplicação HTTP, não cria contas, vínculos, grants comerciais ou login público.

Segredos são gerados somente no host autorizado por prepare-secrets.py, em um diretório novo .private 0700, e nunca exibidos. Cada arquivo fica 0600 e UID/GID 82, para leitura no processo instalador sem root. A senha administrativa é montada exclusivamente em postgres/provision; a aplicação futura receberá apenas runtime.json e app-key. São independentes: senha administrativa, duas senhas de roles, prova HMAC, pepper, chave TOTP e chave Laravel. O diretório .private não é versionado nem enviado para imagem.

O instalador aceita somente portal_identity_dev em 127.0.0.1:5432, PostgreSQL 18 e origem fixa https://portal-dev.imaginecomunica.com.br. Usa dez migrations nomeadas, instala funções existentes com roles NOINHERIT/NOBYPASSRLS, habilita LOGIN apenas nos dois papéis runtime e provisiona a chave de prova em tabela privada. Recusa qualquer banco preenchido ou com papéis já presentes, inclusive instalação parcial. Não apaga nem reaplica silenciosamente.

## Execução no servidor autorizado

Usar checkout fixado/revisado e shell root, dentro desta pasta:
```bash
umask 077
python3 prepare-secrets.py "$PWD/.private"
docker compose config --quiet
docker compose build provision
docker compose up -d --wait postgres
docker compose run --rm -T provision php provision.php install
docker compose run --rm -T provision php provision.php verify
```

verify exige fundação sem identidades reais, confere chave/roles, abre conexões independentes de identidade e envelope e grava/lê/remove somente uma sessão aleatória de prova. A partir da F329, a verificação usa a mesma classe `PortalBrowserPrivateRuntime` da candidata Laravel: as credenciais são carregadas pelo arquivo privado validado, os dois papéis permanecem fixos, a chave AES de sessão é conferida contra TOTP/HMAC/pepper e as senhas SQL, e o verificador não cria rotas HTTP. Emite apenas um fingerprint de marcador aleatório não secreto, que deve permanecer igual após stop/start do contêiner. O marcador não é uma credencial. Depois do cadastro de usuários, usar verificação operacional própria; esta prova inicial intencionalmente recusa banco com contas.

Não usar down --volumes no servidor: isso apagaria dados persistentes. Para parar, usar docker compose stop. A CI usa projeto descartável próprio e remove apenas seu volume após testar provisionamento, negativas, isolamento de portas e persistência.

## Limites e próxima implantação

Somente desenvolvimento. Sem aplicativo HTTP ou conta de operador neste pacote. Sem dados de clientes. As prévias protegidas e os gateways atuais permanecem separados. Uma próxima implantação deverá montar runtime.json no UID do processo e registrar explicitamente driver/rotas, revisar cookies/proxy/HTTPS, fornecer cadastro e recuperação de conta por operador, documentar custódia/rotação das chaves e testar backup/restore sem reativar sessões ou regredir contadores MFA. Backup do volume sozinho não resolve restauração anti-rollback. Não atribuir a este pacote liberação de produção ou revisão independente.

## F338 — prova de restauração lógica exclusivamente descartável

O workflow de fundação executa `backup-restore-smoke.sh` **somente quando** `GITHUB_ACTIONS=true`, `PORTAL_DB_DISPOSABLE_CI=1` e o projeto Docker tem o nome de teste fixo. Faz `pg_dump --format=custom` da fundação **vazia** e restaura em novo banco `portal_identity_f338_restore_ci` no **mesmo cluster temporário**, sem alterar o banco de origem nem restaurar por cima de um alvo existente. Verifica marcador não secreto, tabelas `FORCE RLS`, ownership de funções e ausência de SELECT pelos papéis de runtime, e remove apenas o alvo que criou. Não imprime dumps nem os valores de segredos.

A prova não é um backup de produção e **não** equivale à recuperação após perda da VPS: roles globais já estão presentes no mesmo cluster, segredos e chaves são descartáveis, o ambiente tem zero identidades reais e não há anti-rollback entre cópias externas. Nunca executar o script na VPS; a guarda de ambiente recusa uso fora da CI. Os procedimentos operacionais de backup e recuperação precisam de aprovação e validação separadas.

## F339 — consistência da origem e testes negativos do gate

Antes do `pg_dump`, a prova registra o marcador da origem e exige seu formato hexadecimal de 64 caracteres. Após inspecionar o arquivo, mas **antes** de criar qualquer banco-alvo, reconfere o marcador e confirma novamente que não há identidades; após a restauração, reconfere a origem. Isso protege contra mudanças detectáveis durante a preparação, sem prometer um snapshot transacional entre verificações. A própria extração `pg_dump` possui sua consistência de snapshot; o marcador não detecta toda modificação possível em tabelas não monitoradas.

O workflow testa antes de criar segredos que o script retorne código 64 quando falta `GITHUB_ACTIONS`, quando `GITHUB_ACTIONS=false` ou quando `COMPOSE_PROJECT_NAME` não corresponde ao projeto descartável esperado. Esses testes são locais à CI, não executam recuperação no servidor. A prova continua **NO-GO** para implantação, restauração externa, conta real ou dados de clientes.

## F340 — recusa de sobrescrita comprovada na CI

O workflow cria um banco-alvo descartável de mesmo nome **antes** de chamar a restauração. O script deve recusar a operação com código 65, o banco preexistente deve continuar presente e o fingerprint da origem deve permanecer igual. Só o próprio workflow, no projeto Docker descartável, remove explicitamente esse banco de teste depois da verificação. Isso comprova o bloqueio de destino já existente, mas não cobre autorização para recuperação fora da CI nem restauração em cluster novo.

## F341 — matriz de privilégios após restauração

A prova verifica também que os dois papéis runtime (`portal_browser_runtime` e `portal_browser_envelope_runtime`) não herdam **nenhum** privilégio direto de tabela (SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER) nas nove tabelas sensíveis protegidas com RLS. A validação ocorre **no banco-alvo restaurado**, não apenas na origem; a checagem de nove tabelas com ENABLE+FORCE RLS permanece obrigatória. Não substitui auditoria DBA independente nem testes de cópia externa.

## F342 — banco-alvo restaurado sem CONNECT público

`createdb --template=template0` pode conceder CONNECT a `PUBLIC` por padrão. No laboratório descartável, imediatamente após criar o banco-alvo, a restauração revoga CONNECT público e exige que ambos os papéis runtime não possam conectar ao destino. Após `pg_restore`, confere novamente a negação dos dois papéis e a ausência de concessão explícita de CONNECT a PUBLIC na ACL do banco. O acesso administrativo do próprio laboratório é preservado para leitura de verificação e limpeza do destino. Esta restrição protege apenas o banco restaurado sintético; não configura o banco de produção nem resolve os procedimentos de emergência de um cluster novo.

## F343 — lista restrita para CONNECT e proteção do marcador

A verificação do banco restaurado recusa qualquer entrada `CONNECT` na ACL concedida a papéis diferentes do administrador `postgres`, inclusive `PUBLIC` ou um papel terceiro não conhecido pela aplicação. A matriz dos dois papéis runtime passa a incluir também a tabela `public.portal_development_installation`, contendo o marcador não secreto: além das nove tabelas protegidas por FORCE RLS, essa décima tabela não pode oferecer privilégios diretos aos processos da aplicação. Ainda é somente uma prova em um cluster CI sintético; a segurança contra administrador/host comprometido e a recuperação fora desse cluster exigem controles separados.

## F344 — manifesto de migrations após restauração

O laboratório recusa uma origem que não possua exatamente as dez migrations previstas no provisionador. Calcula um fingerprint MD5 **apenas dos nomes ordenados** das migrations (não dos dados ou arquivos de aplicação) e confirma que o banco restaurado conserva a mesma lista e contagem. A verificação não substitui inspeção de integridade do SQL, das tabelas ou dos dados nem uma política de migração para produção; ela detecta somente diferenças do manifesto que seria esperado no laboratório sintético.

## F345 — lista exata de migrations autorizadas

A F344 comparava contagem e fingerprint entre origem e destino; isoladamente isso não impediria que a origem trouxesse dez **nomes inesperados**. O gate F345 exige que cada nome pertença ao conjunto fechado das dez migrations explicitamente instaladas em `provision.php`, tanto antes do dump quanto depois da restauração. Em conjunto com a contagem exata, isso comprova igualdade do conjunto de nomes conhecido, não a integridade criptográfica do conteúdo dos scripts ou de dados de negócio.

## F346 — prova negativa do manifesto na CI

Após instalar a fundação de teste, a CI altera temporariamente **um nome** da tabela de migrations para outro não autorizado, preservando as dez linhas. Executa a restauração sob o projeto Docker descartável e exige recusa 65 *antes de criar o banco-alvo*; em seguida reverte a alteração e confirma que o verificador da instalação retorna exatamente o fingerprint anterior. Nada disso se destina a uma base existente na VPS ou produção.

## F347 — fundação inteiramente vazia no teste de restauração

O dump não pode transportar dados de sessão, envelopes, permissões, organizações ou recursos comerciais, mesmo quando a tabela de identidades está vazia. Antes e após o dump, o script exige zero linhas nas oito tabelas sintéticas pertinentes; exige zero também no alvo restaurado. A CI demonstra a recusa inserindo temporariamente um único envelope sintético sem identidade correspondente: a tentativa deve retornar código 65 e **não pode criar** banco-alvo. Remove o registro de teste e comprova o fingerprint original. Esta proteção também consta em uma frente paralela de auditoria (#563); integrar **somente uma candidata consolidada** para evitar duplicidade. Continua sem cobertura de backup de clientes reais, recuperação em cluster novo e anti-rollback.

## F348 — segundo cluster PostgreSQL independente na CI

A CI agora inclui um **segundo contêiner PostgreSQL 18**, com cluster próprio sem rede, volume de origem, portas publicadas ou credenciais reais. O ensaio gera um arquivo `pg_dump` **somente da fundação sintética e vazia**, inicializa o contêiner separado com o segredo efêmero do runner, cria apenas quatro papéis conhecidos em estado `NOLOGIN` e restaura o arquivo com `pg_restore --exit-on-error`. Exige marcador igual, zero dados nas oito tabelas de domínio, dez migrations, presença da chave sintética, nove tabelas com RLS/FORCE, proprietários das funções esperados, nenhum privilégio direto dos papéis runtime e CONNECT restrito no banco restaurado. A limpeza destrói **somente o contêiner recém-criado** e o arquivo temporário privado; não usa ou altera a VPS.

**Limitações:** esse ensaio comprova restauração em um cluster novo dentro da CI, não recuperação operacional fora do runner. Os papéis runtime permanecem `NOLOGIN`, não há custódia independente ou rotação de segredos, cópias fora da máquina, reativação de contas, antifraude, restore com usuários reais, anti-rollback de MFA ou teste de desastre na VPS. **DRAFT/NO-GO** para implantação, mesmo com todos os workflows aprovados.

## F349 — contrato SQL compartilhado para recuperar funções e migrations

Os dois ensaios de restauração (no mesmo cluster descartável e no segundo cluster sem rede) agora executam `restore-contracts.sql` contra o **banco restaurado**, e não apenas o banco de origem. A instrução SQL exige as dez migrations exatas e confere as **13 funções de identidade/envelope**: existência, `SECURITY DEFINER`, papel proprietário, `row_security=on`, `search_path` restrito e matriz exata de `EXECUTE` de ambos os papéis runtime. A função interna de prova HMAC não pode ser executada diretamente. O contrato é somente leitura e falha fechado; não substitui teste de recuperação de credenciais reais nem auditoria independente.

## F350 — provas negativas para o contrato SQL

A CI exige que `restore-contracts.sql` passe com a fundação sintética íntegra, falhe após uma alteração temporária de `SECURITY DEFINER` para `SECURITY INVOKER` na função de validação de sessão e falhe quando `PUBLIC` recebe `EXECUTE` direto na função interna HMAC. Cada alteração é revertida antes de uma segunda aprovação do contrato e da comparação do fingerprint original. São testes exclusivamente no cluster descartável, sem alterar autenticação real, sem abrir rotas HTTP e sem deploy.

**Correção de inicialização F348 (CI):** uma execução da candidata F350 detectou que `pg_isready` aceita o servidor temporário do `initdb` **antes** de `POSTGRES_DB` existir. O segundo cluster agora precisa ter o processo final PostgreSQL como PID 1 **e** responder a uma consulta SQL ao banco-alvo correto antes de criar os papéis. Esse teste evita a corrida de inicialização sem aumentar permissões ou usar rede externa.
