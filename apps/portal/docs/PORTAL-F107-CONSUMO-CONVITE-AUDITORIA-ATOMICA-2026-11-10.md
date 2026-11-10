# PORTAL-F107 — Consumo de convite e auditoria na mesma transação

**Data:** 10/11/2026. **Ambiente:** laboratório PostgreSQL 18 descartável. **Não autoriza produção.**

## Entrega

A F106 demonstrou que um UPDATE condicional em PostgreSQL permite marcar um convite como consumido apenas uma vez, com verificação sintética de identidade/empresa, email e MFA, validade, revisões e estado. Esse processo ainda não persistia auditoria de segurança.

A F107 adiciona o evento `invitation.consumed` ao catálogo fechado da migração F89 e cria `apps/portal/labs/identity-f107/atomic_invitation_audit.sql`. O SQL de laboratório:

- Revoga o `EXECUTE` da função **não auditada** F106 do papel de prova `portal_f106_invitation_probe`.
- Concede a um definer `NOLOGIN/NOSUPERUSER/NOBYPASSRLS` apenas `INSERT` nas colunas estritamente necessárias de `portal_security_events` e `USAGE` na sequência; a tabela permanece sob `FORCE RLS`, sem policies para o caller.
- Cria uma policy `FOR INSERT` somente para o definer e somente para o evento `invitation.consumed`.
- Expõe ao laboratório apenas `portal_f107_claim_with_audit`, que primeiro chama F106 e, se a atualização do convite foi válida, escreve um evento F89 na **mesma transação PostgreSQL**.
- Se o evento não puder ser inserido, a subtransação do bloco `EXCEPTION` desfaz também a transição do convite. Nada deve retornar uma falsa confirmação de sucesso.

O evento registra IDs UUID de identidade/empresa, revisão e um digest SHA-256 determinístico derivado de IDs de convite e evento, **sem armazenar o token ou seu hash bearer-equivalente**. Esse hash **não é assinatura digital nem prova externa**: administradores de banco ou restaurações de backup podem manipular o ambiente inteiro, razão pela qual o testemunho externo SEC-R03 permanece obrigatório.

## Testes

`test_pg_atomic_invitation_audit.php` executa num banco local descartável com duas conexões independentes. Verifica: uma linha e um único evento por consumo; replay recusado; empresa/identidade incorretas; falha do INSERT de auditoria fazendo rollback da mudança do convite; concorrência com lock de linha, timeout controlado e único commit; rollback completo de uma transação; comprovação de que não foram criadas sessões nem ativados vínculos; trigger F89 de auditoria bloqueando UPDATE, e privilégios restritos de execução e escrita.

A CI F107 roda PHP 8.3/8.4 e PostgreSQL 18, executa previamente F105 e F106, aplica a migração F89 de auditoria, roda a prova F107 e depois desfaz funções, policies, roles, tabelas e migrations. O PR também aciona a validação Laravel e o smoke Docker privado da F64, sem copiar `labs/` para o runtime.

## NO-GO operacional

Esta é uma **prova de atomicidade entre uma linha de convite e um evento de auditoria**, não uma fonte confiável de identidade. Os campos `email_verified_at` e `mfa_confirmed_at` usados na fixture são preenchidos sinteticamente; o aceite real ainda depende de verificação independente de titularidade, convite entregue pelo canal autorizado, MFA real, proteção contra phishing, rate-limit e CSRF.

As policies `USING(true)` do laboratório são exclusivas para um definer NOLOGIN e **não constituem RLS multiempresa pronto para clientes**. A prova também **não** garante linearização frente a mudanças simultâneas de membership/organização. É necessária revisão independente DBA/AppSec/LGPD, SEC-R03 externo, modelo de segurança da VPS, session e IAM reais, E2E de cliente e aprovação formal antes da abertura de qualquer rota.

Sem login, endpoint de aceite, envio de email, acesso CRM, publicidade, faturamento, deploy, dados de cliente, despesas novas ou merge na `main`. PR **draft**.
