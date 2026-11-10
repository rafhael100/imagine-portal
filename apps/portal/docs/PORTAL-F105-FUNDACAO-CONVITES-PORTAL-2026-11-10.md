# PORTAL-F105 — Fundação fechada de convites Portal-only

**Data:** 10/11/2026. **Status:** código e migração em revisão, teste PG18 descartável, sem envio de convites.

## Código novo

- `app/Domain/Identity/Invitations/PortalInvitationSecret.php`: material de convite de 256 bits gerado por `random_bytes`, codificado em Base64URL canônico de 43 caracteres. O token de entrega pode ser obtido **uma única vez** pela instância; o digest SHA-256 fica disponível para persistência, mas não é um comprovante de autorização. O método de validação usa `hash_equals` depois de verificar formato canônico; serialização de instâncias é proibida e sua saída de debug é redigida. Uma cópia externa do token ainda pode continuar na memória do processo: não se promete apagamento de memória seguro.
- Migração `2026_11_10_000007_create_portal_access_invitations.php`: tabela `portal_access_invitations` PostgreSQL-only, que armazena **apenas** digest do token, UUID da identidade e empresa, três revisões de segurança, estados `pending/consumed/revoked`, timestamps de emissão, expiração, consumo e revogação. A FK composta aponta para um vínculo existente daquela identidade **naquela empresa**, evitando convites cruzados sem membership.
- Restrição `expires_at` maior que `issued_at` e de no máximo **72 horas**; consumido e revogado são estados mutuamente exclusivos, com timestamps coerentes; um índice único parcial impede dois convites `pending` para o mesmo vínculo. Um convite pendente expirado **não muda de status automaticamente**; reemissão exige revogação ou atualização explícita anterior por serviço transacional revisado.
- A tabela tem `ENABLE RLS` e `FORCE RLS` com **zero policies** permissivas e sem GRANT para papéis da aplicação. Nem mesmo um convite marcado como consumido autentica usuário ou cria sessão; não existe worker de envio, finalizador de aceite, recuperação ou rota HTTP.

## Provas de laboratório

- Teste de unidade do token em Laravel/PHP 8.3/8.4: unicidade, revelação única, hash canônico, input malformado/CRM, comparação constante, redaction em debug e bloqueio de serialização.
- `labs/identity-f105/test_pg_invitation_foundation.php` cria identidade e membership fictícias e prova integridade do digest, único convite pendente, recusa de cross-tenant por FK, digest fora do formato, TTL excedido, revisão 0, timestamps/estado inconsistentes e nenhuma sessão gerada.
- Em PostgreSQL 18 descartável, um papel não superuser recebe `GRANT SELECT` somente para demonstrar que `FORCE RLS` **sem policies** continua retornando zero linhas. INSERT/DELETE por papel não autorizado falham. O workflow faz rollback da tabela e da identidade fictícia.
- A abertura do PR draft executa novamente a validação geral Laravel e smoke Docker privado; a imagem de homologação não inclui diretório `database/`, testes ou `labs/`.

## Bloqueios para convite real

Não há emissor confiável/limitação de taxa, verificação de propriedade do e-mail, entrega autorizada de mensagem, MFA de cliente, compare-and-swap transacional para consumo, reuso/replay, revisão de privilégio do emissor, auditoria SEC-R03, LGPD, recuperação e revogação de emergência. Nenhum `token_sha256` (segredo bearer-equivalente) deve ser exposto a navegador, telemetria, URL, log ou terceiro. A identidade Portal permanece independente do CRM. O login, a área comercial e os demais endpoints continuam fechados; a autoridade Laravel F98 segue negando tudo.

**DRAFT / NO-GO de produção**, sem merge em main, deploy, acesso de clientes ou novos custos.
