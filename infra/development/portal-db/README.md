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
