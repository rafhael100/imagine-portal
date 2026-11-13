# Portal Imagine — comprovante de e-mail com ledger PostgreSQL (F03)

**Candidato de revisão somente em PostgreSQL 18 descartável. Não existe interface, e-mail real, conta operacional, autorização, deploy ou alteração de VPS.**

## Continuidade das PRs #601 e #603

Esta etapa liga a primitiva de domínio `PortalEmailProof` (HMAC vinculado a identidade/organização/e-mail/revisão) à tabela persistente `public.portal_email_proof_challenges` da migration candidata #603, **exclusivamente em `Tests\\Support\\DisposablePersistedEmailProofLab`**.

O fluxo, executado somente por `postgres` em `portal_browser_lab` com `APP_ENV=testing` e chave HMAC efêmera de 32 bytes, é:

1. Trava a identidade e a membership da organização sintética, confirma que ambas estão `invited`, que a organização está ativa, que existe **uma única membership** e nenhuma sessão ou grant prévio.
2. Gera token sintético de 256 bits com `PortalEmailProof::issue()`; persiste **somente digest HMAC, escopo, revisão e prazo de nove minutos** em uma transação. Uma segunda emissão na mesma identidade falha pela chave primária do ledger.
3. Para confirmar, bloqueia identidade/membership e a linha do comprovante, verifica **tenant, e-mail, revisão, validade, status pendente e digest por HMAC** com chave efêmera. Sem prova correta, incrementa o contador monotônico; após cinco erros a linha vira `blocked`.
4. Se a prova corresponder, faz `UPDATE status='consumed'` e marca `email_verified_at` **na mesma transação**, sem criar credencial, MFA, sessão ou permissão. O trigger do ledger impede reaproveitamento e regresso de contador.
5. Falhas de SQL, lock/concorrência, identidade ou papel de banco externo retornam erro genérico, sem vazar token, chave, SQL ou endereço de e-mail.

O workflow `portal-browser-identity-pg18.yml` instala a migration **somente no serviço PG18 efêmero do GitHub**, executa a suíte `DisposablePersistedEmailProofPg18Test` em PHP **8.3 e 8.4** e mantém todos os testes existentes de autenticação privada.

## Restrições importantes

- O token nunca é entregue por provedor de e-mail; volta **somente ao processo de teste**. O resultado não prova posse real de caixa postal.
- A função é uma fixture dentro do namespace de testes, usa conexão administrativa estritamente descartável e **não pode ser registrada como issuer operacional** ou chamada por HTTP.
- A tabela mantém `FORCE RLS` sem nenhuma policy allow. O papel de login do Portal **não tem autorização para inserir, consultar ou consumir** comprovantes.
- As garantias de revogação **não sobrevivem a restore conjunto do banco e da chave**. Necessária autoridade externa monotônica, quarentena e prova AppSec/DBA conforme issue #64.
- Faltam a prova real de e-mail, cadastro privado, gestão de chave, recuperação, rate limiting de rede, revisão independente de segurança, integração aprovada #341 e homologação em ambiente privado. Nenhuma migração de produção ou gasto novo foi autorizado.

**Política:** DRAFT / NO-GO para merge, deploy, sessões e acesso de clientes.
