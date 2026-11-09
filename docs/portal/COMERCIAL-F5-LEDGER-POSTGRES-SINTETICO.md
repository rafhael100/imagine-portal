# Portal Comercial F5 — Ledger PostgreSQL experimental de publicação

## Recorte implementado
Um **laboratório independente**, em PostgreSQL 18 descartável no GitHub Actions, materializa as relações físicas que a área editorial do Imagine Portal precisará posteriormente:

- `releases`: escopo de organização, release ID, SHA-256 aprovado, editor e instante de submissão.
- `approvals`: aprovação distinta por release e hash, com chave estrangeira composta que impede associação entre organizações; ordem temporal e segregação de editor.
- `publish_outbox`: uma intenção pendente por release, ligada **somente** a aprovação válida; publisher diferente do editor e do aprovador; idempotência por tenant e release.
- Eventos de aprovação e outbox são append-only; atualização de release aprovado é bloqueada.
- Role `commercial_lab_reader` restrita a SELECT com RLS ativada e **FORCE ROW LEVEL SECURITY** em todas as tabelas; teste de leitura entre empresas e limpeza do contexto ao finalizar transação.
- O CI verifica adversarialmente rejeição de aprovação cruzada, autoaprovação, ordem temporal inválida, enfileiramento sem aprovação, duplicação, reescrita e acesso indevido.

## Separação e segurança
**Não é uma migration Laravel** e **não deve ser executado em nenhum banco operacional**. Arquivos permanecem sob `apps/portal/labs/commercial-publishing/` e são carregados só no service container do GitHub Actions.

A GUC `commercial_lab.organization_id` **não comprova identidade**; qualquer aplicação que possa defini-la livremente pode forjar escopo. Em produção, o Portal deve obtê-la de sessão autenticada e autorizada, impor MFA e vincular a operação à revisão de membership, revogação e geração de grants, sem permitir que o cliente escolha GUC/role. O teste de leitor não é teste de RLS do aplicativo real.

O escritor neste laboratório é o usuário privilegiado **somente do banco descartável**. Ainda não há role de escrita de produção, serviço de publicação, consumidor de outbox, dead-letter queue, rotação de chaves, commit durável em ambiente autorizado nem histórico antirrollback por fonte externa.

A interface editorial do PR #131 continua somente leitura e sem rota HTTP. O emissor criptográfico dos PRs #119–#123 permanece um laboratório separado. **Integração destas partes é um gate futuro**, não concluída por esta implementação.

Nenhuma alteração em main, DNS, VPS, contas, pagamentos, Cloudflare ou publicação.
