# PORTAL-F74 — revisão integrada de fundação de login e MFA

Data-base 09/11/2026, validações GitHub UTC 10/11/2026. Este arquivo consolida a linha de desenvolvimento desde F68, não autoriza clientes, produção, merge ou deploy.

## Progresso efetivo com código e CI

- **F68 / PR #202:** ambiente de execução Laravel em Docker PHP8.4 com localhost nginx somente `/up`, identidade PostgreSQL F66 com cinco tabelas, FORCE RLS sem policies, modelos Eloquent F67, prévias HTML de Portal/Sites/Performance, contrato de verificação Sites, provas PG de revogação e teste SEC-R03 C04.
- **F69 / PR #203:** hashing e verificação Argon2id e TOTP RFC6238 por secret Base32 e contador.
- **F70 / PR #204:** primeira coluna persistente `mfa_last_used_counter` com CAS PostgreSQL que rejeita reuso de código inclusive entre conexões.
- **F71 / PR #205:** ativação `pending_mfa→active` e atualização do contador TOTP numa mesma transação, com isolamento de empresa, revisão, expiração e rollback quando falta autoridade simulada.
- **F72 / PR #206:** consolidação anterior de revisão, **11 workflows** aprovados na HEAD `c909f1c8ddeb76218d70d96fdcccce098824ff66`.
- **F73 / PR #207:** envelope criptografado do segredo TOTP usando PHP sodium secretbox, nonce aleatório e chave distinta, sem armazenar o segredo em texto claro; campo `mfa_secret_ciphertext` vinculado à transação F71 por igualdade de ciphertext sob lock. Testes unitários de criptografia e CI PostgreSQL para recusa de referência obsoleta.

## O que funciona sem fornecedor adicional

- O pipeline cria e testa contêineres PHP/nginx e esquema PostgreSQL descartável.
- O código de autenticação está pronto para ser integrado de forma controlada no backend, mas não opera como um sistema de login para clientes.
- Os contratos de revisão visual e campanhas continuam fornecendo somente dados sintéticos, sem publicidade, consentimento ou pagamentos.
- Nenhuma aplicação separada do CRM pode aproveitar automaticamente o Portal como sessão compartilhada.

## Bloqueios antes de virar produto operacional

1. **Autoridade de identidade real:** fluxo de registro/convite aprovado, confirmação de e-mail, recuperação de conta, limites de tentativas e bloqueio, login seguro, sessão/cookies HttpOnly Secure SameSite, CSRF e OAuth/OTP conforme plano. O código F71 ainda aceita segredo de laboratório explicitamente.
2. **Gestão de chaves:** fonte de segredo verificável por servidor, KMS ou cofre com rotatividade, backup/recuperação, provisionamento TOTP, desativação e códigos de recuperação; isolação rigorosa das chaves de tenants e de PHP/CI.
3. **PostgreSQL com policies revisadas:** nenhum GRANT ou RLS permissiva está liberado. Implementar papel de app não-superuser, operação por recurso e sessões verificadas no servidor; revisão de segurança DBA.
4. **Antirollback independente:** SEC-R03/Z4/T3 exige witness externo resistente a restauração de banco e comprovação de revogações de sessão/membro/MFA após restore.
5. **Aplicação e serviços:** rotas protegidas, autorização de contratos, Sites, CRM/Performance, Universidade, segurança service-to-service, workflows de pagamento, auditoria e LGPD.
6. **Infraestrutura:** VPS Leaseweb disponibilizada e homologada, TLS e DNS quando liberados, mecanismos reais de backup/restore e rollback, monitoramento e AppSec independente.

As provas atuais são sintéticas, inclusive as 11 suítes de CI de F72. **Não usar sucesso técnico como autorização de produção.**

## Regras de entrega

- PR único de revisão da linha F68–F74 em `dev/fast-track-portal-2026-11-05` a ser mantido em rascunho, com CI exata verificada.
- Não alterar `main`, implantação, domínio, credenciais, gateway ou dados pessoais; não provisionar a VPS sem acessos revisados.
- Avanço futuro de login real requer decisão de segurança sobre key management, autorizações RLS e witness externo.
