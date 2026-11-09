# Portal + Comercial F25 — Build assinado obrigatório na validação PHP→Astro

09/11/2026 — Prova de laboratório, sem autenticação operacional ou publicação.

## Problema
O F24 conferia assinatura, versão, integridade do payload e correspondência com HTML gerado. Quando todos os cinco parâmetros do catálogo estavam ausentes, porém, o build padrão do Astro podia compilar o conteúdo editorial estático. Um orquestrador defeituoso poderia aceitar esse resultado em vez de falhar.

## Verificações
A pipeline de laboratório fixa o Imagine Comercial no commit 03c22fe7c3bcca45badb6a61117f61276a6bad07 (PR #25). O fixture PHP da branch Portal Comercial F10 continua emitindo apenas catálogo sintético e chave descartável. Ao chamar o build, o workflow define IMAGINE_CATALOG_REQUIRE_SIGNED=1.

Depois da validação positiva PHP → PostgreSQL 18 → Astro, a pipeline tenta compilar novamente removendo deliberadamente todas as cinco variáveis de catálogo, mas mantendo o requisito obrigatório. O cenário deve falhar com a mensagem de recusa de fallback editorial. O teste de hash incorreto permanece ativo, assim como os controles F24 de correspondência de HTML e árvore de arquivos.

## Limites
A flag é uma declaração do orquestrador, não uma prova de segurança de suas permissões. É possível eliminá-la em um pipeline mal configurado ou comprometido. Para publicação produtiva ainda são necessários CI protegido, credenciais de serviço, grants/MFA, revisões de função, prova de revogação durável, antifraude, delivery receipt verificado e auditoria independente.

Sem merge em main, deploy, DNS, fornecedor pago, clientes reais, checkout ou produção. Um resultado CI verde apenas comprova o contrato de laboratório.
