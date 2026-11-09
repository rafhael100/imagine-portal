# PORTAL-F32 — pg_dump + pg_restore reais de um banco fictício, preservando a testemunha

**09/11/2026 · Somente laboratório PHP/Laravel e PostgreSQL 18 em dois containers temporários.**

## O que avança em relação ao F31

F31 simulou um restore antigo alterando de volta linhas de sessão e
membership na base principal; comprovou que uma segunda base com piso
monotônico mais recente impediria o reuso de um token sintético antigo.
**F32 substitui a simulação de linhas por uma operação de backup lógico
e restore real**, sem tocar em servidores ou dados de clientes.

A prova roda **depois de todas as suites F22–F31** no mesmo job do GitHub
Actions, com PHP 8.3 e 8.4 em ambientes temporários independentes.

## Sequência executada pelo workflow

1. Provisiona o **banco primário** F21/F24/F25 e a **testemunha** F31
   como PostgreSQL 18 separados em Docker, cada um com seu próprio
   diretório de dados, papel de leitura, credencial SCRAM aleatória e
   porta de host vinculada somente a `127.0.0.1`.
2. Executa os testes regulares de integração das etapas anteriores;
   ambos os bancos devem ter retornado ao estado fictício inicial.
3. Usa **`pg_dump -Fc`** para salvar uma cópia lógica completa do
   banco primário `postgres`, no runner efêmero, em arquivo privado
   criado por `mktemp` com `umask 077`.
4. Revoga a sessão fictícia `DEMO-SESSION-A` no primário (estado
   `revoked`, revisão 2) e avança a testemunha para piso de sessão 2
   e sequência 2. **Os commits são separados**; isto não é transação
   distribuída, outbox nem protocolo de entrega confiável.
5. Executa **`pg_restore --clean --if-exists --exit-on-error`** no
   banco primário a partir do dump antigo. A testemunha não é
   apagada, restaurada ou reinicializada durante essa etapa.
6. Executa especificamente `F32ActualPgRestoreWitnessTest.php`
   com opt-in `F32_AFTER_RESTORE=DUMP_RESTORE_COMPLETED`.
   A suíte verifica o estado pós-restauração: sessão primária ativa
   em revisão 1, testemunha ainda em piso 2, antigo HMAC de fixture
   aceito **apenas pelo caminho primário F24 não autoritativo**,
   mas **negado pelo caminho F31** como
   `stale_external_witness`, sem linhas, totais, cursor ou grant.
   Uma nova prova de revisão 2 também não supera a versão 1
   da sessão restaurada no primário.
7. Remove o arquivo lógico e ambos os containers/volumes com
   `trap`, inclusive após falha.

## Limites de segurança e evidência

- **É um `pg_dump/pg_restore` lógico real, mas SOMENTE de
  fixtures efêmeras.** Não testa snapshot de bloco, WAL/PITR,
  restore de volume, backups históricos, replicação, múltiplas
  regiões, RPO/RTO, desastres reais, failover ou corrupção de
  backups de produção.
- A testemunha está no **mesmo runner e domínio administrativo**.
  O operador pode reverter ambas as instâncias, alterar o código
  que compara revisões, trocar chaves ou neutralizar o trigger
  monotônico. **Não constitui uma âncora externa de confiança
  independente nem atende a SEC-R03/Z4.**
- O fluxo de revogação não é atomicamente entregue à testemunha.
  Falhas antes do update externo ou commit atrasado mantêm
  uma janela vulnerável. F31 tem observação antes/depois, mas
  não garante revogação linearizável, proteção de respostas
  já emitidas nem consenso distribuído.
- O emitente HMAC do F24 é de laboratório; não existe login
  real, MFA, sessão operacional, identidade legal do cliente
  ou contrato de interoperabilidade com o emissor Ed25519
  específico da integração Portal–Performance.
- Nenhum schema ou execução F32 está integrado em rotas,
  providers, produção, VPS Leaseweb, Cloudflare, CRM, Sites,
  Universidade, Performance, agentes ou n8n. Tudo é fictício.
- O CI usa apenas minutos do GitHub conforme o plano, não
  provisiona um serviço externo de backup ou testemunha.

**Estado:** PR DRAFT, filho de F31, sem merge em `dev`/`main`,
sem deploy e sem homologação AppSec/DBA. P0, D02-SEC, D03-RLS,
SEC-R03-X01..X24, T3/OPEN permanecem **bloqueados**.
