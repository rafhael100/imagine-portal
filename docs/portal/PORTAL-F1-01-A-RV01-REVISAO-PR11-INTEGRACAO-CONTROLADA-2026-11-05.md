# PORTAL-F1-01-A-RV01 — Revisão técnica e destino de integração do PR #11

**Data:** 05/11/2026  

> **ATUALIZAÇÃO SUPERVENIENTE (05/11/2026):** o responsável aprovou **somente F1-01-B-A** como programação controlada, com branch filha da fundação existente e PR draft destinado à branch de desenvolvimento, **sem merge**. Ver [decisão B-A](PORTAL-F1-01-B-DEC-01-APROVACAO-PROGRAMACAO-CONTROLADA-2026-11-05.md). O recorte técnico de derivação B-A **não implica aprovação geral de INT-A para integração** do PR #11, nem autoriza alteração da `main`.

**Situação:** **REVISÃO DOCUMENTAL/TÉCNICA CONCLUÍDA; PROPOSTA DE GOVERNANÇA DE INTEGRAÇÃO — NÃO HÁ AUTORIZAÇÃO DE MERGE**.  
**Escopo da autorização atual:** analisar a etapa seguinte e preparar F1-01-B; "vamos para o próximo passo" não revoga restrições anteriores de `main`/deploy nem autoriza programação indistinta de F1-01-B.

## 1. Evidências verificadas

| Evidência | Resultado |
|---|---|
| [PR #11](https://github.com/rafhael100/imagine-portal/pull/11) | **open, draft, não mesclado**, base `main`, head `dev/fast-track-portal-2026-11-05` |
| HEAD do código revisado | `098cdfd50024ea872861f3ba3a058409e5f976b7` |
| BASE `main` conferida | `50b683a499e674641df5749051f3d05f7ac3f627`; não alterada |
| Comparação Git | 9 commits à frente de `main`, 0 atrás; **39 arquivos no PR** |
| Perímetro | **38 arquivos em `apps/portal/` e 1 workflow em `.github/workflows/laravel-f1-01-a.yml`**; nenhum arquivo do Node.js/SQLite legado alterado |
| [GitHub Actions PHP/Composer](https://github.com/rafhael100/imagine-portal/actions/runs/42116952698) | **success** em PHP 8.3 e 8.4, com **2 testes/5 asserções por matriz** |
| [Workflow Node legado](https://github.com/rafhael100/imagine-portal/actions/runs/42116952613) | **success**, verificação independente, sem provar funcionamento Laravel |
| Dependências | `composer.lock` versionado; Laravel `v13.40.0`, Collision `v8.9.5`, PHPUnit `12.5.43`; 75 dependências runtime e 27 dev |
| Auditoria | `composer audit --locked` sem alertas publicados na data da execução; não substitui revisão de segurança ampla |
| Infra/produção | Nenhuma evidência de migração, deploy ou PSP/CRM real nas alterações do PR |

### Observações da revisão

- A estrutura `apps/portal/` fornece apenas bootstrap Laravel, configurações e teste de `GET /up`; nenhuma rota de autenticação, compra ou lead foi implementada. O teste verifica 404 em `/`, `/login`, `/checkout` e `/api/leads`.
- O `DB_CONNECTION=pgsql` apenas declara a conexão-alvo: **nenhum banco PostgreSQL real foi criado ou testado**; multiempresa/RLS e segurança de dados ainda não foram exercitados.
- `.env.example` mantém placeholders, `APP_DEBUG=false`, `DB_PASSWORD=` vazio; `.gitignore` ignora `.env` e `vendor/`. Logs/configurações deverão ser reavaliados quando identidade real for implementada.
- O workflow isolado tem `contents: read` e instala dependências do lockfile; não executa migrações nem aplica deploy. Um workflow temporário para fixar lockfile foi **removido** depois de uso. A inspeção do PR não substitui revisão completa de segurança, licenças, dependências e infraestrutura.
- O projeto legado foi preservado conforme D03-R02 (protótipo com dados de teste); o novo Laravel não depende da migração do SQLite.
- **Correção de semântica de documentação:** registros históricos de “testes Laravel pendentes”, presentes em trechos antigos do PR, foram **superados** pelo relatório final de CI. O sucesso dos testes iniciais não atesta prontidão de produção.

## 2. Conclusão técnica restrita

**F1-01-A VALIDADA PARA CONTINUIDADE EM RAMO DE DESENVOLVIMENTO, NÃO HOMOLOGADA PARA PRODUÇÃO.** O código revisado é uma base adequada **somente** para planejamento e implementação posterior de módulos isolados, com critérios próprios. Não interpretar o check verde como autorização de `main`, infraestrutura ou liberação comercial.

## 3. Destinos de integração — decisão ainda pendente

| Alternativa | Funcionamento | Avaliação |
|---|---|---|
| **INT-A — Permanecer na branch `dev/fast-track-portal-2026-11-05` e manter PR #11 draft** | A branch atual já contém a F1-01-A validada. A próxima etapa, após autorização própria, parte de um **novo ramo filho** com PR direcionado à branch de desenvolvimento; `main` continua intocada. Nenhum merge agora. | **RECOMENDADA** para não confundir validação técnica com promoção de release |
| **INT-B — Criar `dev/portal-integration-...` e, após aprovação expressa, mesclar PR de código nesse destino** | Estabelece base de integração separada por nome, com revisão/CI obrigatórios, sem `main` | Possível, mas adiciona ramificação/merge desnecessários neste momento |
| **INT-C — Merge imediato na `main`** | Mistura fundação Laravel com repositório legado antes de gates P0 e release | **NÃO AUTORIZADO/NÃO RECOMENDADO** |

### Sequência de controle recomendada

1. **Aceitar documentalmente INT-A**, sem mover ref nem alterar a base/estado do PR #11 nesta revisão; preservar o SHA e o caráter draft.
2. Detalhar F1-01-B como contratos de domínio para organizações, membros, atribuições e permissões com **dados fictícios** e **sem login real**; obter aprovação expressa de recorte.
3. Após aprovação B específica, criar ramo filho do head conferido de `dev/fast-track-portal-2026-11-05`, limitado ao novo recorte; abrir PR desse ramo para **branch de desenvolvimento**, não para `main`.
4. Testes de validação do ramo filho não fecham automaticamente D02-SEC, P0-DOC06, tenancy/RLS, pagamentos ou APIs de terceiros.
5. Eventual merge controlado em ramo de integração ou promoção à `main` exigem **autorização distinta**, revisão humana e gates aplicáveis.

## 4. Pendências e travas

- **D02-SEC01/02/03/04/05/06**: identidades, isolamento, segurança, recuperação, delegação e integrações.
- **D03 físico/RLS** e **P0-DOC06**: definir vínculo de usuário com empresa, titularidade, FK composite ou equivalente, políticas de acesso e testes negativos.
- **P0-DOC01–14**: permanecem abertos ou parciais; RV-CT homologado por implementação ainda pendente.
- **D04-B/C**: contratação financeira e integração de pagamento reais não aprovadas.
- **Sem** merge na `main`, deploy, acesso VPS, CRM/Sites/Performance AI/Universidade, dados reais, exclusão de legado, AGI-11-RB01.

## 5. Deliberação futura

**PORTAL-F1-01-A-INT-01 (PROPOSTA NÃO RATIFICADA):** aprovar `INT-A` como estratégia de base técnica **de desenvolvimento**, mantendo PR #11 draft e `main` preservada, sem fazer merge. Em seguida, se quiser, aprovar separadamente o recorte de F1-01-B com código estritamente fictício.

**Status:** documento de revisão, **não autorização de execução**.
