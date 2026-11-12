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
