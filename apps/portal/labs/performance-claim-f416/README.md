# F416 — laboratório descartável de consumo único, sem cobrança

O envelope assinado F415 carrega os canais escolhidos e o contrato HMAC
de revisão Portal→CRM. **Uma assinatura não bloqueia replay**: sem banco,
o mesmo envelope pode ser apresentado repetidamente enquanto estiver válido.

F416 prova somente a proteção de um registro de ativação em **PostgreSQL 18
descartável**, numa base chamada `portal_purchase_rehearsal`. Não é uma
migration de produção e não deve ser aplicada na VPS do cliente.

## O que a suíte demonstra
- Controles `CHECK` e `UNIQUE(tenant_ref,order_ref,policy_revision)`:
  somente uma revisão por pedido/política; total de ativação R$490×1/2/3.
- RLS ENABLE/FORCE e role app sem BYPASSRLS/INHERIT/SUPERUSER.
- Leitura por empresa exige HMAC verificado pelo próprio PostgreSQL,
  guardado em tabela sem permissão de SELECT para o login de aplicação.
- Login de aplicação não consegue atualizar estado nem consumir autorização.
- Worker SQL restrito consome um claim uma vez por `UPDATE ... WHERE
  state='issued' AND envelope_hash=? AND envelope_mac=?`. Uma chamada
  repetida devolve 0 linhas; MAC errado não altera estado.
- Revogação anterior ao consumo impede a utilização posterior.
- Sem host network, segredos, bancos persistentes, pagamento, identidade
  real, dados pessoais ou credenciais de PSP.

## Limitações explícitas
O laboratório usa assinaturas e referências de **teste** como dados.
O SQL NÃO comprova autenticidade HMAC de F415: esta é verificada em PHP
antes de registrar a solicitação; a integração cliente/CRM real ainda
precisa conectar essas partes, com sessão MFA, autorização por empresa,
estado atômico, consentimento, trilha de auditoria e rollback.

A prova não implementa gateway, fatura, nota fiscal, delivery em CRM,
confirmação do provedor nem extrato oficial de mídia. Não usar `SET
SESSION AUTHORIZATION` em uma aplicação operacional; somente no teste
executado como superusuário em banco efêmero.

Comando do CI: criar PostgreSQL 18 isolado `--network none`, aplicar
`schema.sql` e `test-claims.sql`, destruir o contêiner sempre ao final.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CHARGE.**
