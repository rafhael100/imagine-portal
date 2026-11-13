# Preparação da primeira conta privada

Ferramenta administrativa de uso único, fora do aplicativo web. Não instala schema, altera roles ou ativa serviços. Requer a fundação privada PG18 existente e o arquivo de prova correspondente. Valida MFA antes de gravar; consome o contador confirmado para impedir reutilização. Senha Argon2id e segredo MFA selado pela chave independente existente.

A transação bloqueia o marcador de instalação e as tabelas, recusa qualquer identidade/organização/membership/grant/sessão/recurso já existente e grava organização, identidade e vínculo juntos. Qualquer falha desfaz tudo. A identidade e o vínculo são INVITED, e email_verified_at permanece NULL. Não cria sessão ou permissão: conhecer a senha e o MFA ainda não libera login. A verificação real do e-mail e a ativação revisada são etapas pendentes, não substituídas por declaração administrativa.

## Operação após testes e backup
Usar o checkout da candidata revisada. A imagem de provisionamento existente contém as dependências PHP; montar este diretório apenas no contêiner de ferramenta. No diretório infra/development/portal-db:
```sh
docker compose run --rm -it --interactive=true \
  -e PORTAL_INITIAL_ACCOUNT=PREPARE_INVITED_ONLY \
  -v /srv/imagine/portal-auth-development/infra/development/portal-enrollment:/opt/portal-enrollment:ro \
  provision php /opt/portal-enrollment/enroll.php
```
Não executar esse comando enquanto o agente estiver lendo a tela. Entregar o console ao operador ANTES da execução. O próprio operador executa, informa e-mail/organização, define a senha e inscreve a chave no autenticador, confirma o código e digita PREPARAR. Nenhum desses valores vai em argumentos, logs, arquivos intermediários ou chat. Não usar gravação de terminal/redirecionamento. A ferramenta exige TTY e limpa a tela ao terminar, deixando recibo sem credenciais.

Recibo de sucesso informa UUID da organização; não é token de acesso. Não configurar UUID na aplicação nem publicar login até concluir verificação e ativação. Se o banco já tiver conta, recusar; nunca truncar dados para tentar novamente. Após criar identidade, o antigo verify da fundação vazia deve recusar por design. Backups completos continuam necessários e restauração de contas exige processo próprio.

O teste test.php só funciona com GITHUB_ACTIONS=true e PORTAL_ENROLLMENT_CI=DISPOSABLE_ONLY. Usa banco recém-criado exclusivamente no runner, valores fictícios e cleanup com nome fixo de CI. Nunca rodar fixtures em VPS real.
