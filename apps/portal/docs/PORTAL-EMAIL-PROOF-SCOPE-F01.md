# Portal Imagine — comprovante de e-mail vinculado ao escopo (F01)

**Candidato de segurança, somente desenvolvimento/revisão. Nenhum login, SMTP, conta real, privilégio, deploy ou merge é habilitado.**

## Entrega

O componente de domínio `App\\Domain\\Identity\\Security\\PortalEmailProof`, no namespace da aplicação, fornece duas operações **puramente criptográficas**: `issue()` e `matches()`. Não depende de PostgreSQL, cookies ou serviços externos. O código gera um token aleatório de **256 bits** e deriva um verificador **HMAC-SHA256 com separação de domínio**, usando uma chave de servidor de exatamente 32 bytes que **nunca é armazenada no banco**.

A derivação inclui, com codificação por comprimento, **UUID da identidade, UUID da organização, e-mail normalizado, revisão de segurança da identidade e token**. A verificação rejeita alterações em qualquer um desses campos, chave incorreta/rotacionada, token malformado ou verificador inválido. O token não aparece no verificador persistido. A operação não concede permissão, sessão ou benefício; `grantsAccess()` retorna sempre `false`.

O laboratório já existente de primeiro operador da PR #600 passa a utilizar esse componente:

- Reserva fictícia via PR #598 em PostgreSQL 18 descartável;
- Emissão do comprovante na tabela efêmera `portal_browser_private.lab_initial_email_proofs`, que persiste apenas `proof_digest`, `organization_id`, `identity_revision`, expiração, consumo e contador de tentativas;
- Confirmação com bloqueio da identidade e membership, **exatamente um membership convidado**, validação criptográfica de escopo, prazo de dez minutos, uso único e máximo de cinco palpites incorretos;
- Ativação de identidade/membership exclusivamente fictícias após senha Argon2id e MFA TOTP, com verificação adicional de que o comprovante consumido pertence ao tenant e à revisão atuais, sem grants ou sessão;
- Testes unitários e de integração de troca de empresa, identidade, endereço de e-mail, revisão, chave, token e membership extra, executados pela CI PG18 em PHP 8.3 e 8.4.

## Limites e ameaças ainda abertas

1. O token **não é enviado**. Nos testes ele é devolvido diretamente ao processo de teste. Não existe comprovação real de posse de caixa postal, envio SMTP, entrega, identidade de destinatário, reenvio autenticado, recovery ou interface de operador.
2. A chave efêmera dos testes não é uma chave operacional provisionada. É necessário um procedimento externo e revisado de geração, armazenamento, backup, versão, rotação, indisponibilidade e comprometimento da chave **separado do banco e do domínio de restore**.
3. A emissão operacional exigirá aprovação administrativa, identidade de empresa auditada, proteção de abuso, controle de concorrência, gestão de logs sem tokens, transportes de e-mail seguros e políticas RLS/DBA. Não copiar `Tests\\Support\\DisposableFirstEnrollment` para um serviço de produção.
4. HMAC e revisão **não resolvem recuperação de snapshot**: se banco, chave e sessões antigos forem restaurados em conjunto, provas antigas podem reaparecer. O bloqueio [PORTAL-SEC-R03 #64](https://github.com/rafhael100/imagine-portal/issues/64), testemunha externa monotônica e quarentena continuam imprescindíveis.
5. A cadeia de integração [#341](https://github.com/rafhael100/imagine-portal/issues/341) requer revisão independente AppSec/DBA e lote aprovado; nenhuma liberação de clientes, migração de VPS ou deploy é autorizada neste pacote.

**Política:** candidato DRAFT, fail-closed por padrão, `main`/dev não alteradas.
