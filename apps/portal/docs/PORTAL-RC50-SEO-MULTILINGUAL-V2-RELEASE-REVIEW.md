# Portal RC50 — SEO multilíngue assinado com controle de confiança PG18 (15/11/2026)

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Consolida o Portal RC49 [#887](https://github.com/rafhael100/imagine-portal/pull/887) e as entregas SEO F138 [#893](https://github.com/rafhael100/imagine-portal/pull/893), F139 [#894](https://github.com/rafhael100/imagine-portal/pull/894) e F140 [#895](https://github.com/rafhael100/imagine-portal/pull/895). Não modifica o verificador F122/v1.

## Resultado técnico do recorte SEO

- Engine F126 em SHA imutável `38b80fe77bd74ea6e19b8751014038ac3b9f8400` audita HTML estático, produz DTO redigido e assina com domínio v2 separado do v1.
- F138: verificador PHP strict schema e Ed25519, com tenant/run/commit independentes, validade, nonce e relatório sem URLs. CI PHP 8.3/8.4 passou.
- F139: assinatura real do Engine → verificador Portal → nonce PG18 uma vez, com FORCE tenant RLS e concorrência. [CI F139](https://github.com/rafhael100/imagine-portal/actions/runs/43788945751): **SUCCESS**.
- F140: chave pública cadastrada no banco e selecionada pelo trusted signerRef, não pelo envelope. Aceite e revogação sob lock comum; evento de auditoria de revogação F128/F129 obrigatório. [CI F140](https://github.com/rafhael100/imagine-portal/actions/runs/43789337129) e [suíte geral](https://github.com/rafhael100/imagine-portal/actions/runs/43789337044): **SUCCESS**.

As chaves, identidades, HTML, usuários, tenants e serviços dos testes são **sintéticos e descartáveis**. Não há rota HTTP real de publicação.

## Atenção: revisão do histórico

A comparação da branch funcional F140 com `main` retornou **412 commits à frente**, zero atrás e **somente 300 arquivos** no resultado de comparação (listagem possivelmente truncada). O projeto Portal tem múltiplas frentes em paralelo; NÃO aceitar automaticamente que a RC50 equivale apenas às quatro últimas PRs. Revisar inventário integral paginado, mudanças de rotas/autenticação/migrations/RLS, ordem de aplicação, dependências e impactos em CRM, Sites, Universidade e Performance.

## Gates obrigatórios

- Revisão independente AppSec/DBA/LGPD e aprovação separada para migrations de staging, login administrativo, GSC e dados de clientes.
- Custódia/rotação/revogação de chaves Ed25519 reais; identidade autenticada do emissor; autenticação de administrador e organização/serviço; limites e logs com privacidade.
- Fonte IBGE verificada, originalidade/QA editorial, não geração massiva de páginas doorway, GSC OAuth readonly com grants verdadeiros.
- VPS Leaseweb staging isolado, TLS, backups/restauração, controles de rede, auditoria de volume/porta, logs e rollback ensaiados.
- Aceite humano de liberação; nenhum merge ou deploy automático a partir de CI de laboratório.

**Estado: HOLD.** Não houve implantação nem acesso a contas reais nesta branch.
