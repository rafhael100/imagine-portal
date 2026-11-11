# Portal F123 — Fronteira de envio HTTP e bloqueio de retry automático

**Estado:** laboratório CI, NO-GO operacional. Base: F122, PostgreSQL 18 descartável e PHP 8.3/8.4. Não existe endpoint, worker, integração com o CRM verdadeiro, dado pessoal ou deploy.

## Lacuna resolvida

F122 criava um lease de entrega e impedia novos envios se o worker registrasse resultado `unknown`. Porém, se o worker começasse uma chamada HTTP e caísse **antes** de registrar o estado `unknown`, outro worker poderia recuperar o lease vencido automaticamente, sem saber se o destino já fez COMMIT. Limitar a três tentativas não impede efeito duplicado nesse cenário.

F123 separa a **reserva local** do **início potencial do envio remoto**:

1. `claim_lab_a(submission, expected_version)` da F122 reserva somente metadados e uma versão. Uma reserva vencida, ainda no estado `claimed`, pode ser recuperada com CAS e limite de três tentativas.
2. `begin_dispatch_lab_a(submission, claim_version)` é a fronteira **antes de qualquer byte HTTP**. Exige versão corrente e lease válido. Muda atomicamente `claimed -> dispatching`. Chamadas repetidas, versão antiga e lease já vencido falham.
3. Uma vez em `dispatching`, o `claim_lab_a` da F122 **nunca consegue recuperar o lease**, mesmo vencido, porque somente aceita `claimed`. A perda de resposta ou parada de processo, portanto, não aciona retry automático.
4. `note_unknown_lab_a` aceita também `dispatching -> unknown` com a versão corrente, mesmo após o lease vencer. `unknown` não é sucesso nem falha definitiva e permanece bloqueado para novos claims; exige reconciliação confiável posterior.

O SQL altera apenas o check de estado existente e uma função de laboratório, sem guardar recibos, chaves, tokens ou payload. Os papéis `NOLOGIN/NOBYPASSRLS`, permissões `EXECUTE` explícitas e `FORCE RLS` são mantidos. A fixture ainda usa `SECURITY DEFINER` do superusuário de CI; **isso não está liberado para produção**.

## Testes e limites

`test_dispatch_fence_pg18.php` confirma: tentativa sem lease, versão obsoleta, início único, lease vencido após início de envio, ausência de retry, `unknown` durável, recuperação apenas de lease nunca enviado, bloqueio de antigo owner, isolamento por empresa, proibição de escrita direta e ausência de recibo ou ACK. A workflow F123 reexecuta F117–F122 antes da nova prova sob PHP 8.3/8.4 e PostgreSQL 18 efêmero.

**Obrigação de um worker futuro:** chamar `begin_dispatch` e obter confirmação do COMMIT **antes** de qualquer I/O remoto; ao não conseguir comprovar o estado do destino, não reenviar. Esta fixture não impõe essa ordem sobre um worker que ainda não existe. A eventual conciliação por recibo autenticado requer checagem de tenant/destino, assinatura/rotação, consentimento/LGPD, auditoria e supervisão independente. Não há transição `delivered` nem permissão de avisar o visitante que o CRM persistiu.

Não alterar `main`, banco operacional, DNS, VPS, credenciais ou serviços externos. A operação real segue bloqueada por revisão SEG/DBA/LGPD, homologação e autoridade de sessão/empresa do Portal.
