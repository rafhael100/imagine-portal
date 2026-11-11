# Portal F122 — Bloqueio persistente de resultado desconhecido e versionamento de tentativas

**Escopo:** PostgreSQL 18 real mas **descartável** do GitHub Actions, PHP 8.3/8.4, dados inteiramente sintéticos, sem conexão operacional com CRM, sem dados pessoais, sem rota Laravel, sem deploy.

## Problema abordado

A F121 demonstrou duas bases independentes no CI: o Portal registra metadados na sua outbox e um receptor CRM **simulado** persiste o mesmo envio em outro banco. Uma resposta HTTP pode se perder **depois** do commit, deixando o Portal sem certeza do resultado. Repetir imediatamente a operação sem conciliação pode duplicar os efeitos remotos.

A F122 resolve a **parte local** da decisão: o banco de origem passa a manter uma reserva de entrega versionada e um estado de incerteza que impede novas reservas automáticas. Isso continua sendo uma fixture de laboratório, ainda não um worker operacional.

## Implementação

`unknown_hold_pg18.sql` acrescenta, apenas ao schema `portal_commercial_ci` de testes, `delivery_gate` com sete colunas técnicas: empresa UUID, submissão UUID, estado (`claimed` ou `unknown`), `claim_version`, `attempts` (1 a 3), `lease_until`, `updated_at`. A chave é organização+submissão e a FK exige que a submissão de teste exista no ledger F118. Não há dados de contato, tokens, recibo, chave, IP ou payload.

Dois papéis consumidores `NOLOGIN/NOBYPASSRLS` têm leitura isolada por `FORCE ROW LEVEL SECURITY`, sem permissões de INSERT/UPDATE/DELETE/TRUNCATE diretas. Duas funções PostgreSQL `SECURITY DEFINER` somente de CI, com busca restrita a `pg_catalog` e empresa A fixada no SQL, recebem apenas a referência de submissão e uma versão:

- `claim_lab_a(submission,expected_version)`: exige outbox preexistente, concede somente um lease inicial, ou recupera outro lease **vencido** por comparação de versão; incrementa o contador até o limite de três tentativas. Não aceita estado `unknown` como elegível a retry.
- `note_unknown_lab_a(submission,claim_version)`: coloca a submissão em `unknown` **sem lease ativo**, preservando versão e contagem. Exige a versão atual, inclusive para um lease que acabou de expirar e ainda não foi reclamado. Um consumidor com versão antiga não pode criar retenção após nova reserva. Nem a própria função reabre uma retenção já gravada.

Ambas têm EXECUTE revogado de PUBLIC e concedido somente ao consumidor A de laboratório. A fixture simula expiração usando o papel administrador do contêiner descartável, **nunca delegando poder de relógio ao consumidor**.

`test_unknown_hold_pg18.php` executa a prova real: reenvio inicial e repetido, lease vencida, comunicação incerta, bloqueio durável após resultado desconhecido, preservação de versão, outro envio independente, versões antigas, três tentativas com limite definitivo, rejeição de comandos diretos, leitura cruzada e falsificação de tenant via GUC. A CI executa as mesmas regras em PHP 8.3 e 8.4.

## Fronteiras para produção

A F122 não processa o recibo da F121 nem transforma um recibo simulado em prova do CRM. **O estado `unknown` não é falha definitiva nem sucesso; só pode ser resolvido por um processo operacional de reconciliação confiável**, com identidade de serviço, consulta de destino autenticada, evidência de persistência, regra documentada de reprocessamento, observabilidade e revisão humana apropriada. Nenhuma função desta etapa marca o envio como entregue ou exclui o registro.

A versão operacional precisa evitar `SECURITY DEFINER` com proprietário privilegiado de superusuário, usar credenciais mínimas, implementar tenant binding no servidor, registrar auditoria, aprovar privacidade e testar falhas em ambientes independentes. A F70 do Imagine Comercial permanece bloqueada. Nenhuma credencial real, `main`, VPS, DNS, serviço externo, pagamento, dado de cliente ou produção foi alterado.
