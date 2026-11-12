# PORTAL-SEC-R03-F01 — Contrato fail-closed de restore (laboratório)

**Estado: SIMULAÇÃO IMPLEMENTADA / RISCO OPERACIONAL NÃO RESOLVIDO / PRODUÇÃO NO-GO.**

Este pequeno lote, criado diretamente sobre `dev/fast-track-portal-2026-11-05`, formaliza a política de segurança necessária para analisar **ressurreição de sessões e permissões após `pg_restore`**. Origem: [issue #64](https://github.com/rafhael100/imagine-portal/issues/64), evidência de que uma sessão revogada no banco vivo reapareceu ativa na cópia restaurada.

## Lógica observável do laboratório

Entrada sintética e tipada:

- `LabRestoreSnapshot`: organização, ID de cluster primário, sequência do backup, revisões das sessões/permissões e indicação de sessões/membros ainda ativos na cópia.
- `LabExternalWitnessFloor`: organização, ID fictício da testemunha **separada**, contador monotônico próprio, **geração de backup primário que a testemunha declara cobrir** e revisões mínimas. O contador próprio do serviço e a sequência primária não são comparáveis entre si.
- `LabExternalWitnessReader`: interface sem qualquer implementação de rede, banco ou Cloudflare; `inspectFromReader()` faz **duas leituras** e falha em ausência, exceção ou divergência entre elas.
- `LabQuarantineEvidence`: sete declarações de pré-condição sobre isolamento de HTTP, jobs/escrita, revogação de sessões, MFA/recovery, credenciais de serviços, permissões e invalidação de caches/pools. Esses booleanos são **falsificáveis**, usados somente para o teste e **não são atestados reais**.
- `LabRestoreServingGate`: rejeita tenant diferente, mesma identidade de cluster, falta de cobertura explícita de uma geração do banco principal posterior àquela do backup, revisão que não ultrapassa estritamente as revisões antigas, sessões ainda ativas, quarentena incompleta e resultado de testemunha alterado entre leituras.

**Invariante obrigatório:** `LabRestoreFenceVerdict::canAuthorizeHttp()`, `canReleaseProductionServing()` e `canReissueCustomerSessions()` retornam **sempre false**, inclusive quando `syntheticChecksPassed=true`. Não há middleware, controlador, binding de dependência, conta de serviço, SQL, API, rede, token, login ou operação na VPS. Uma saída `synthetic_recovery_candidate_only` **não** tem efeito real.

## O que os testes adversariais exercitam

- Snapshot pré-revogação com sessão ou membership ativa volta a ser recusado.
- Piso externo sem referência à geração primária posterior ao backup ou revogação não superior à revisão antiga é recusado. Um contador local da testemunha muito alto **não** equivale a cobertura de eventos primários; os dois relógios lógicos têm origens diferentes.
- Testemunha da organização errada ou que alegue a mesma origem do banco primário é recusada.
- Ausência/queda entre leituras, exceção do leitor e atualização concorrente de testemunha levam a recusa, sem usar cache antigo nem revelar erro sensível.
- Cada requisito individual da quarentena é indispensável; registros inválidos e revisões fora de limites falham.
- Uma simulação totalmente coerente nunca recebe autorização real.
- Rotas de cliente/restore, bindings de testemunha e sessões reais continuam ausentes.

Rodar `cd apps/portal && php artisan test --filter 'LabRestoreServingGateTest|LabRestoreRuntimeBoundaryTest'`. CI Laravel em PHP 8.3/8.4 valida o mesmo código sem PostgreSQL nem serviços externos.

## Lacunas para concluir SEC-R03 (abertas e bloqueantes)

1. **Testemunha monotônica realmente independente**: uma string diferente de cluster não prova segregação física ou administrativa. Ainda não existe implementação externa persistente e resistente ao rollback conjunto. Mesmo duas leituras iguais não impedem uma revogação que chega logo depois (TOCTOU).
2. **Protocolo de escritores linearizável**: toda revogação de sessão, MFA, membership, chave de API e credencial precisa publicar primeiro uma barreira durável e resolver concorrência com os leitores. Não concluído.
3. **Reversão também da testemunha**: restaurar banco primário e testemunha em conjunto pode recriar um estado aparentemente coerente. O laboratório **não consegue detectar todos esses casos**; exige uma terceira âncora confiável ou política de invalidação universal com nova geração fora do backup.
4. **Recovery operacional em containers/VPS**: restauração totalmente offline, backup de filas/outbox, isolamento de workers, destruição de sessão e rotação de segredos com comprovação, RLS, teste de race/cache e reconciliação antes do serving real. Não realizado.
5. **Revisão independente AppSec/DBA/LGPD**, autorização de gasto/infra, homologação no ambiente permitido e decisão de merge/deploy. Não realizados.

## Decisão

O laboratório é **um avanço de contrato e regressão**, não é correção operacional da issue #64. Nenhum estado `PASS` neste arquivo dispensa qualquer dos gates acima. A implementação futura de Durable Objects/R2 Cloudflare ainda depende do limite de custos e permissões da [issue #122](https://github.com/rafhael100/imagine-portal/issues/122); **não cria recursos nem cobra fornecedores**. O lote fica DRAFT/NO-GO, diretamente baseado em `dev` e separado das PRs de interface #588→#592→#594→#595.

## Correção F02 — não comparar relógios lógicos independentes

A revisão do protocolo identificou um falso pressuposto na primeira versão: `witness.sequence > snapshot.backupSequence` não é uma prova de ordenação, pois as sequências não compartilham uma autoridade nem namespace. O modelo passou a exigir `LabExternalWitnessFloor.coveredBackupSequence`, **referência explícita à sequência emitida pelo primário**, e valida `coveredBackupSequence > snapshot.backupSequence` sem comparar o contador interno `sequence` da testemunha. Testes demonstram a recusa de `sequence=900000000` com cobertura insuficiente, e que um contador local menor não prova nem invalida, por si só, a cobertura primária.

**Limitação crítica mantida:** um cliente malicioso pode declarar `coveredBackupSequence` arbitrariamente neste fixture. Em operação real, cobertura dependeria de um recibo **autenticado, emitido na sequência da revogação e persistido fora do domínio de falha/rollback do banco primário**, com verificação de identidade da fonte, anti-replay, consistência e rollback da própria testemunha. Nenhum desses mecanismos foi implementado por esta PR. Por isso **`canReleaseProductionServing() === false` permanece incondicional**.
