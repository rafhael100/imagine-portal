# PORTAL-F33 — Publicar piso de revogação **antes** de alterar a autoridade fictícia

**09/11/2026 · Apenas duas instâncias PostgreSQL 18 descartáveis, sem dados/clientes reais.**

## Lacuna entre F31 e F32

F31 e F32 demonstraram que uma testemunha com revisão mais recente bloqueia
uma prova antiga após restauração do banco principal. Porém, os ensaios
revogavam primeiro na primária e depois avançavam a testemunha. Se o
processo falhar **entre essas operações**, a revogação poderá ser
perdida por um restore antigo da primária antes de existir uma
referência externa atualizada.

F33 demonstra uma opção explicitamente **witness-first**:

1. Conferir organização e assunto **fictícios** do evento;
2. Registrar *primeiro* a intenção de revogar na segunda instância;
3. A publicação na testemunha, em uma única transação SQL, aumenta
   o piso monotônico e grava um evento com **ID único** no ledger;
4. Só após confirmar a publicação, aplicar a revogação do estado
   sessão/membership na primária em outra transação;
5. Se houver falha/crash entre os passos 3 e 4, a leitura
   `F31RollbackAwareLabReader` rejeita a prova antiga porque
   seu piso externo já avançou. Repetir o evento **com a mesma
   carga** retorna `replayed` sem incrementar a sequência outra vez;
6. Se a primária estiver bloqueada/indisponível, NÃO declarar o
   estado de revogação completamente aplicado; a evidência externa
   já bloqueia provas antigas no leitor F31, e um retry pode
   completar a primária quando ela estiver disponível.

## Código criado

- `witness-publisher-schema.sql`: adiciona **somente na segunda
  instância** o schema `f33`, role `f33_revocation_publisher LOGIN
  NOINHERIT`, ledger de eventos e função
  `f33.publish_revocation(...)` com `SECURITY DEFINER`,
  `search_path` fixo e EXECUTE exclusivo dessa role.
  A role não recebe UPDATE/DELETE/CREATE/SELECT direto nas
  tabelas de estado ou no ledger. A função obtém `FOR UPDATE`
  no piso da organização para serializar publicações, exige
  aumento estrito, vincula ID/evento/assunto e grava atualização
  do piso + ledger em **uma transação do witness**.
- `F33SyntheticRevocationEvent.php`: validações estritas para
  organização `org-a`/`org-b`, identificadores
  `DEMO-SESSION-*`/`DEMO-USER-*`, piso e evento fictícios.
  Não existe endpoint de criação de eventos por clientes.
- `F33WitnessFirstRevocationPublisher.php`: código PHP **sem
  provider/route**. `publishWitness()` utiliza apenas a role
  de publicação da testemunha; retorna `published` ou
  `replayed`, que **não equivalem a revogação completa**.
  `finishPrimary()` revalida/republica o evento na testemunha
  antes do UPDATE transacional em `f24.session_fixture`
  ou `f24.membership_fixture`. Caso a publicação falhe,
  a primária **não é modificada**. O escritor primário usa
  a conta admin **do banco fictício**, pois F24 não dispõe
  de writer operacional; isso é inadequado para produção.
- `F33WitnessFirstRevocationPublisherTest.php`: dez cenários
  PHP/PG18 de baseline, publicação com queda antes da primária,
  repetição/idempotência, ID conflitante, tentativa de escrita
  direta pela role restrita, revogação de EXECUTE, lock timeout
  da primária após avanço externo, membership, isolamento da
  outra empresa e assunto de organização incorreta.
- Workflow de CI F22–F32: carrega o schema F33 na **segunda base**
  e cria senha SCRAM aleatória para a nova role; roda os testes
  em PHP 8.3 e 8.4, depois continua F32 com dump/restore
  exclusivamente do banco fictício primário. Remoção de ambos
  os containers/volumes e do backup com `trap`.

## Comportamentos esperados

- Uma falha **antes de registrar a intenção no witness** impede
  `finishPrimary`, mas não consegue impedir uma outra aplicação
  fora deste protocolo de escrever a revogação na primária.
- Uma queda **depois da publicação no witness e antes da primária**
  torna tokens antigos inválidos no leitor F31 imediatamente,
  mesmo que a primária permaneça `active`. Isso prioriza
  segurança sobre disponibilidade; os usuários podem perder
  acesso até a reconciliação.
- Repetir o mesmo ID com carga diferente falha; repetição
  idêntica não cria outra sequência.
- Em falha da primária, o piso externo permanece elevado;
  após retirada do impedimento, a etapa de escrita pode
  ser repetida sem baixar a revisão.

## Limites críticos e próximos gates

**F33 NÃO É UM PUBLICADOR OPERACIONAL COMPLETO.** Os métodos
`publishWitness()`/`finishPrimary()` são invocados diretamente
nos testes; ainda faltam produtor confiável, outbox, fila/retry
durável, observabilidade, backoff, poison-message handling,
reconciliação automática, limites de latência, semântica de
entrega, idempotência após backup, autenticação de operador,
avaliação de abuso e assinaturas de eventos.

**A testemunha continua no MESMO runner GitHub**, não em domínio
administrativo realmente independente. Um admin do witness
pode desabilitar triggers, apagar ledger ou restaurar seu estado.
A função SECURITY DEFINER exige revisão profunda de grants,
search_path e riscos de SQL; sua aprovação aqui é **somente
para fixtures**, não permissão para instalar em produção.

A ordem testemunha-primeiro não resolve revogações que
ignoram o protocolo, revogação distribuída linearizável,
transações que atravessam serviços, relógios adversariais,
desastre conjunto, manipulação de chaves ou operações após a
segunda checagem do leitor. Nenhum contrato com o emissor
Ed25519 da integração Portal–Performance foi alterado.

**Sem rotas, service providers, migrações ou login reais,
VPS/Cloudflare, CRM, pagamentos, clientes ou dados pessoais.
PR DRAFT/SEM MERGE/DEPLOY.** P0/D02/D03, SEC-R03/Z4,
T3/OPEN, AppSec/DBA permanecem abertos. Os jobs GitHub Actions
podem consumir minutos do plano; nenhum fornecedor novo foi
contratado por esta etapa.
