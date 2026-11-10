# PORTAL-F106 — Consumo atômico de convite no PostgreSQL (laboratório)

Data: 10/11/2026 — Estado: implementação de laboratório, CI obrigatória, **NO-GO de produção**.

## Incremento efetivo

A F105 estabeleceu o token de convite Portal-only, armazenamento apenas como SHA-256, FK composta para vínculo identity+organization, TTL máximo de 72h, estado `pending/consumed/revoked`, índice de um convite pendente por vínculo e FORCE RLS sem policies.

A F106 adiciona **exclusivamente em `apps/portal/labs/identity-f106`** uma prova de consumo de convite com uma única instrução SQL UPDATE, devolvendo somente booleano:

- O digest precisa ter exatamente 64 caracteres hexadecimais minúsculos e corresponder à mesma identidade e empresa do convite.
- A linha precisa estar `pending`, não consumida, não revogada, já emitida e ainda não vencida (relógio servidor).
- A identidade de laboratório precisa estar ativa, ter `email_verified_at` e `mfa_confirmed_at` presentes, e o revisionamento de identidade precisa coincidir com o do convite.
- O vínculo precisa ter estado `invited`, revisionamento coincidente e pertencer exatamente à organização selecionada. A organização precisa estar ativa e com revisão correspondente.
- **Somente o status do convite** muda para `consumed` com timestamp. Não cria usuário, não ativa membership, não cria sessão, não emite e-mail nem concede permissão.

A função é `SECURITY DEFINER` com `search_path` fixo e `row_security=on`, propriedade de `portal_f106_invitation_definer` (papel `NOLOGIN/NOSUPERUSER/NOBYPASSRLS`). Tem privilégios mínimos por coluna e políticas `SELECT` / `UPDATE` aplicadas **somente ao definer NOLOGIN**, em banco descartável. A chamada pertence ao papel de laboratório `portal_f106_invitation_probe`, com `EXECUTE` na função e **sem SELECT/UPDATE em tabelas**.

## Provas adversariais

`test_pg_atomic_invitation_claim.php`, rodado em PostgreSQL 18 e PHP 8.3/8.4, testa: convite antes de verificação sintética de email/MFA, credencial incorreta, empresa ou identidade diferente, convite vencido, futuro, revogado, revisionamentos inconsistentes, suspensão de vínculo/organização, replay, tentativas concorrentes, rollback, ausência de sessão e vínculo ativado, políticas RLS e privilégios de papel.

Na disputa entre duas conexões, o primeiro UPDATE retém o lock de linha; a segunda tentativa não pode obter uma segunda confirmação (timeout local de lock ou rechecagem após COMMIT). A operação que sofre rollback não consome permanentemente o convite. **Isto prova unicidade da transição da linha de convite**, não uma barreira linearizável entre várias tabelas de identidade, vínculo e organização.

## Restrições operacionais importantes

- Embora os testes criem uma identidade artificial com timestamps de email e MFA, **não existe nenhum verificador real** nem uma autoridade externa de ator: um registro `email_verified_at` sintético não constitui prova de identidade.
- O digest do convite é **bearer-equivalente** durante a validade; nunca colocar em URL de registro, API de navegador, logs, analytics ou fornecedores.
- A função de laboratório tem políticas `USING(true)` para um papel `NOLOGIN`, **não** RLS multiempresa operacional. A futura implementação precisará de revisão independente de DBA e AppSec, RLS por empresa, rate-limit, transação com vinculação da identidade confiável e resistência TOCTOU.
- **Nenhum arquivo F106 é carregado em rotas, providers, nem na imagem runtime Docker.** O Laravel mantém `DenyingPortalAuthorities` da F98, sem login operacional e com apenas `/up` no servidor.
- Sem deploy, integração CRM, DNS, VPS, credenciais reais, cliente, campanha, cobrança ou custos. Não efetuar merge em `main`.

## Próxima etapa

Implementar uma camada de aceite de convite **apenas depois** de comprovar a origem autenticada da identidade, o canal de verificação, proteção contra phishing/replay, idempotência, auditoria/SEC-R03 e revisão de segurança. O estado `consumed` **não** é autorização HTTP.
