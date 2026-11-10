# PORTAL-F108 — Consumo auditado sob bloqueios de identidade, empresa e vínculo

**Data:** 10/11/2026. **Status:** demonstração PostgreSQL 18 descartável, PR draft; **NÃO liberado para produção**.

## Problema tratado

A F106 comprova que uma única linha de convite não é consumida duas vezes. A F107 adicionou auditoria F89 na mesma transação, revertendo o consumo se a auditoria não puder ser persistida. Essas provas não impediam uma mudança concorrente da identidade, vínculo empresarial ou empresa durante a leitura de autorização.

A F108 introduz `portal_f108_locked_claim_with_audit`, uma camada de laboratório que exige três leituras `SELECT ... FOR SHARE` em **ordem fixa** antes de invocar o consumo auditado F107: identidade → organização → vínculo. O critério de validade da identidade (ativa, email e MFA marcados no fixture), da empresa (ativa) e do vínculo (estado invited) é conferido sob os bloqueios. As revisões de segurança continuam verificadas dentro da F106/F107, **enquanto os locks estão retidos**.

## Privilégios e políticas SQL

O PostgreSQL exige uma permissão `UPDATE` em ao menos uma coluna para `SELECT FOR SHARE`. A F108 concede ao proprietário de função `NOLOGIN/NOSUPERUSER/NOBYPASSRLS` exclusivamente `UPDATE(status)` sobre essas três tabelas, conservando acesso SELECT por coluna. Como o PostgreSQL também exige uma policy `FOR UPDATE` para que `SELECT FOR SHARE` veja linhas sob RLS, cada tabela ganha uma policy estritamente para o proprietário `NOLOGIN` com `USING (true) WITH CHECK (false)`. O `WITH CHECK (false)` impede qualquer UPDATE de linha, inclusive atribuição sem alteração; o teste tenta mudar `status` e deve obter SQLSTATE 42501. O papel leitor do laboratório não recebe essas permissões e só tem EXECUTE na F108, não na F107 nem na F106.

**Alerta:** `UPDATE(status)` e policies `USING(true)` são uma concessão sensível de laboratório. Não reutilizar este esquema em ambiente persistente antes de revisão independente de privilégios/DBA/AppSec e demonstração de segurança sob carga. Essas policies não são RLS por empresa; o isolamento ainda depende dos predicados e do emissor confiável ausente.

## Testes adversariais

`labs/identity-f108/test_pg_locked_claim_revocation.php` usa transações em conexões PostgreSQL independentes para demonstrar que:

- Alteração de revisão de identidade **não comprometida** bloqueia a verificação do convite e faz o convite falhar fechado no timeout; após COMMIT, a revisão desatualizada impede o consumo.
- Se a confirmação auditada obtém os locks primeiro, um escritor que tenta alterar a revisão do vínculo **não consegue ultrapassar o lock**; após COMMIT do consumo, a mudança pode prosseguir.
- Uma organização suspensa durante outra transação bloqueia a verificação; após COMMIT, o convite não pode ser consumido.
- Revogações não geram eventos de auditoria falsos, convites inválidos continuam pendentes, replay continua recusado, e as funções não geram sessões nem ativam memberships.
- Uma tentativa direta de UPDATE pelo proprietário técnico, apesar do `UPDATE(status)` necessário ao lock, é impedida por `WITH CHECK(false)`.

O workflow F108 roda regressões F105–F107, o novo SQL e a nova suíte PHP 8.3/8.4 × PostgreSQL 18, com banco e senhas descartáveis, rollback total dos objetos de laboratório. O PR também reexecuta Laravel e o teste de contêineres privado.

## Limites de garantia

Esta prova assegura **serialização entre as transações observadas que atualizam as linhas bloqueadas** em PostgreSQL READ COMMITTED. Não evita que um escritor privilegiado altere dados após o commit do consumo, nem impede alterações em sistemas externos; também não é prova de login, MFA ou identidade real. Não valida a origem do solicitante, consentimento LGPD, canal de entrega, antifraude, limite de tentativas, cache/filas, SEC-R03 testemunha externa, desastres ou consistência multi-banco.

A F98 mantém as autoridades Laravel configuradas para negar acesso. O HTTP continua somente `/up`. Nenhum código F108 em `labs/` é exposto no runtime, nenhuma VPS, DNS, dados reais ou custos adicionais são utilizados. **Sem merge na main; PR DRAFT / NO-GO.**
