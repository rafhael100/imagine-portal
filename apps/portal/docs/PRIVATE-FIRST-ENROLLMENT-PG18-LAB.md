# Portal Imagine — ativação de primeira identidade (somente laboratório PostgreSQL 18)

**Estado: implementação de testes sintéticos, não é ativação de conta real.** PR filha da #598 (convite inicial) / #596 (login privado). Módulos em `tests/Support`; não existem serviço registrado, endpoint HTTP, comando de provisionamento de clientes, envio de e-mail, interface de operador ou acesso à VPS.

## Sequência validada
1. O teste `DisposableFirstInvitationBootstrap` cria organização fictícia, identidade e membership `invited` sem senha/MFA/permissões.
2. `DisposableFirstEnrollment::issueEmailProof()` cria prova aleatória com 256 bits, validade de dez minutos e armazena **somente SHA-256**, em uma tabela efêmera criada e removida pela suíte PostgreSQL, no schema privado.
3. `confirmEmail()` usa transação e bloqueio de identidade para verificar prazo e consumir a prova **exatamente uma vez**, gravando `email_verified_at`. Código errado, expirado ou repetido não confirma.
4. `setFirstPassword()` aceita exclusivamente texto de laboratório iniciado por `synthetic-`, aplica a política de hash Argon2id já existente e só persiste após verificação do e-mail. Não muda a identidade para ativa.
5. `confirmMfaAndActivate()` exige a identidade e membership convidadas, organização ativa e correta, e-mail confirmado, hash de senha presente, nenhum grant/sessão e recibo de e-mail consumido. Verifica um código TOTP com a primitiva first-party e relógio do PostgreSQL; cifra a semente **sintética pública do teste** com chave efêmera independente; promove identidade e membership na mesma transação, com atualização do contador anti-replay.
6. Uma segunda ativação, tentativa de regravar a senha, uso de outra organização, falsificação de código, grant inserido indevidamente e conexão com papel de runtime são rejeitados.

## Provas executáveis

O workflow `.github/workflows/portal-browser-identity-pg18.yml` instala o schema existente em PostgreSQL 18 descartável e roda `DisposableFirstEnrollmentPg18Test.php`, mais as demais suítes de login e sessão, em PHP **8.3 e 8.4**.

## Bloqueios não removidos

- **Prova de e-mail NÃO é real:** o token é retornado diretamente ao próprio teste. Um provedor de envio, comprovante verificável de posse da caixa postal, controles contra abuso/ressolicitação e registro de entrega/expiração ainda são necessários.
- **Senha/MFA NÃO são registrados por usuário verdadeiro:** não há fluxo web seguro para operador, aprovações de cadastro, autenticação da solicitação, proteção de segredos/QR, recovery, chaves versionadas ou integração com o serviço operacional. A chave é efêmera do teste. Não migrar essa rotina para produção sem revisão.
- Uma identidade fictícia pode mudar para `active` **dentro do banco descartável**, mas não recebe *grants*, sessões, roteamento HTTP nem acesso de cliente; a recuperação anti-rollback #64 ainda impede a entrada operacional.
- Não há transação linearizável com testemunha externa de revogação nem processo operacional de restore. RLS, políticas AppSec/DBA, integração #341, homologação autorizada e aprovação de merge/deploy seguem necessários.
- Não usar o teste em produção ou na VPS real. Sem real usuário, email, senha, custos, deploy ou merge.

A implementação continua DRAFT / NO-GO para publicação.
