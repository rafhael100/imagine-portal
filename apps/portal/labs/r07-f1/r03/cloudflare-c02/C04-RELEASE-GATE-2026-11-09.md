# PORTAL-SEC-R03-C04 — Controle automatizado de evidências e bloqueio de liberação

**09/11/2026. Status: validação interna de laboratório, NÃO homologação de produto.**

## Objetivo

Evitar que a quantidade crescente de PRs e testes *sintéticos* do Portal Imagine seja interpretada incorretamente como comprovação de segurança operacional, autorização de gastos, direito de acesso a clientes ou liberação de produção.

Este recorte é filho do PR #124 (testemunha simulada) e depende dos PRs #120, #116, #69 e da Issue #122. **Não há conexão Cloudflare disponível neste ambiente; nenhum serviço real foi criado.**

## Entrega

- `C04-OPERATIONAL-GATES.json`: registro estruturado de **38 gates**, sendo 24 casos SEC-R03-X01..X24 marcados **NOT_RUN** operacionalmente e 14 requisitos adicionais mantidos **BLOCKED**: P0 R01-01/02/03/09/10, G-ID, G-BB, D02-SEC, D03-RLS, T3, AppSec/DBA, Z4 externa, limite de gastos e OPEN.
- `src/c04-release-gates.mjs`: verificador offline. Rejeita lista incompleta, reordenada, duplicada ou adulterada; flags de autorização incompatíveis; reviewer inventado; evidência operacional autocertificada; alterações que expõem `workers.dev`, preview URLs, rotas, cron ou binding R2 real.
- `test/c04-release-gates.test.mjs`: ensaios negativos que tentam promover gates, omitir riscos, falsificar evidência, alterar configurações ou invocar ações de liberação.
- CI Node 22/24: integra o verificador ao pacote C02/C03 e exige **explicitamente** a mensagem `operational release remains BLOCKED`.
- Um retorno 0 do CI significa **somente** que o laboratório ainda não se apresentou como produto homologado; não é parecer de segurança nem sinal para deploy.

## Invariantes de segurança

1. **Nenhum código neste pacote abre sessão, expõe dados de cliente ou aprova restauração real.** Mesmo estados fictícios `OPEN` e uma testemunha coerente permanecem sem efeito operacional.
2. Nenhuma string, flag JSON, parecer autocertificado ou CI isolado substitui AppSec/DBA, revisão independente, prova de IAM, controle de custos e autorização gerencial para OPEN.
3. **KNOWN GAP:** Z4 + testemunha restauradas juntas podem parecer consistentes apesar de revogações posteriores. Outro gap: R2 local sem lock permite sobrescrever evidências. São resultados adversariais, não testes de segurança que passaram.
4. Não inventar evidências para SEC-R03-X01..X24. Cada caso operacional terá de ser reavaliado separadamente contra arquitetura e infraestrutura efetivas, inclusive efeitos realmente observados.
5. CI deve falhar caso alguém tente marcar `PASS` no registro de laboratório. Uma eventual homologação real exigirá **novo processo documental/operacional distinto**, nunca apenas editar este JSON e liberar.
6. O teste de configuração é uma verificação sintática de arquivo versionado: **não comprova** quais URLs, domínios, bindings, tokens ou políticas existem na conta Cloudflare.
7. O PR #124 é um laboratório de um só processo, não testemunha independente real. A Issue #122 continua rastreando acesso à conta de testes, billing, decisão de topologia e custos.

## Evidência esperada

- HEAD exata do PR e diff com apenas este laboratório.
- Workflow C02/C03/C04 Node 22 e Node 24 aprovados.
- Smoke Miniflare/workerd local sem publicação e CI do legado/Laravel sem regressão.
- Teste de falha de ativação indevida efetivamente exercitado em CI.
- Registrar no PR o status de qualquer workflow pulado; **SKIPPED não é PASS de prova PostgreSQL**.

## Caminho crítico para uso real

1. Fechar a origem confiável de identidade, vínculo, membership e autorização real do Portal.
2. Definir Z4 com domínio de restauração/controle independente, histórico de revogações, CAS real e proteção contra rollback da própria testemunha.
3. Completar manifesto de **todos** os readers/writers do Portal e integrações (incluindo job, CLI, outbox, CRM, Sites, Performance, n8n), sem extrapolar exemplos.
4. Realizar testes SEC-R03-X01..X24 no ambiente operacional restrito, com evidência de efeitos em SQL, jobs e integrações, não apenas HTTP 403.
5. Obter pareceres técnicos, aprovação gerencial do plano de recuperação e operação; homologar disponibilidade, segurança, LGPD, billing, RPO/RTO e rollback.
6. Somente após gates independentes completos, preparar plano de deploy e eventual migração/merge; nenhum deles autorizado por esta PoC.

**Restrições:** PR DRAFT; sem merge para `dev`/`main`, deploy, DNS, Cloudflare real, VPS, dados pessoais, secret, gastos, pagamentos, grant ou conta de cliente.
