# ADMIN fase2 — revisão funcional, segurança e experiência

Base: Portal889 `bf6b22ba90cd69617087745b5c6476dc2bd41f42`, RC49 `82624818832ee04b64111154fd551e2cca34c27e`, motor412 `238bcb026aca0a02d90c0ebff38fb2d4f35d8e1f`. Nenhuma dessas PRs foi presumida integrada à main.

## Achados e correções

| Achado | Risco | Correção |
| --- | --- | --- |
| Consume exibido para Ads/financeiro | Expectativa incorreta; backend bloqueava, mas UI convidava operação impossível | Disponibilidade por tipo internal_note, status/validade, MFA recente, aprovador identificado e operador distinto/permissão |
| Resolve oferecido sem evidência | Falsa disponibilidade operacional | Exibir somente quando a evidência do cenário for verificada; servidor continua revalidando |
| approved_by=null com estado approved | Motor não exigia aprovador positivo explicitamente | Motor413 passa a negar estado inconsistente; pin e hashes atualizados |
| Relatórios com segundo ramo inacessível | Código morto, manutenção ambígua | Ramo duplicado removido; um único caminho de consulta/exportação |
| Abas abriam cartões intermediários | Navegação interrompia o fluxo e perdia filtros | Links para os módulos reais, contexto e filtros de retorno preservados; URLs antigas redirecionam |

Controle da UI é apenas apresentação. Nenhum POST depende de esconder botões para autorizar; motor, escopo, epoch de sessão, MFA, revisão, idempotência e transação permanecem obrigatórios.

## Revisão de acessibilidade e identidade

Marca original e laranja#F9941D/cinza preservados. Navegação sem simular controles ARIA de abas; links nativos, foco visível, skip-link, labels explícitos, confirmação dentro de label e tabelas com cabeçalho scope=col. Mensagens e estados vazios diferenciados. CI Chromium verifica desktop e celular; UI não depende de JavaScript para ações essenciais.

## Testes e separação de revisão

Novos testes verificam disponibilidade de ações, tipo, validade, MFA, papéis, ausência de vínculos, logout, abas e filtros de retorno. Compilação Blade, suites existentes e fluxo Chromium permanecem na workflow.

Esta é **revisão técnica do autor com testes**, não parecer independente AppSec/DBA/LGPD ou certificação de acessibilidade. Não foi designado um revisor independente neste ambiente. Esse aceite deve ser fornecido por outro responsável antes da promoção operacional; não o marcar como concluído apenas por CI verde.

DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.
