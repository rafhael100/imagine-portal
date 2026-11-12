# Portal Imagine SEO — candidata consolidada A–O (DRAFT/NO-GO)

**Candidata única de revisão:** [PR #460](https://github.com/rafhael100/imagine-portal/pull/460), base `dev/fast-track-portal-2026-11-05`. Antes desta atualização documental, a comparação total mostrava **99 commits, 63 arquivos e zero atrás**. Sem merge dev/main ou deploy. A antiga PR extensa [#394](https://github.com/rafhael100/imagine-portal/pull/394) **não** compõe a candidata.

## Entregas verificáveis

| Lote | PR histórica | Entrega |
| --- | --- | --- |
| A | [#398](https://github.com/rafhael100/imagine-portal/pull/398) | Contrato offline SEO RC32/F74 → PHP e HTML estático |
| B | [#399](https://github.com/rafhael100/imagine-portal/pull/399) | Tendências de sobreposição com supressão de métricas |
| C | [#400](https://github.com/rafhael100/imagine-portal/pull/400) | Planejamento de propriedades/paginação sem rede |
| D | [#403](https://github.com/rafhael100/imagine-portal/pull/403) | Classificação segura de resposta HTTP, status e Retry-After |
| E | [#408](https://github.com/rafhael100/imagine-portal/pull/408) | Paridade do modelo privado SEO-F79 com HTML PHP |
| F | [#416](https://github.com/rafhael100/imagine-portal/pull/416) | Handoff de exportações originais SEO-F88 → PHP com flags negativas |
| G | [#421](https://github.com/rafhael100/imagine-portal/pull/421) | PKCE S256, state aleatório de 600s, callback HTTPS estrito |
| H | [#439](https://github.com/rafhael100/imagine-portal/pull/439) | Classificação de callback OAuth sem expor código ou ampliar scope |
| I | [#446](https://github.com/rafhael100/imagine-portal/pull/446) | State/verifier cifrado AEAD no PostgreSQL e consumo atômico de uso único |
| J | [#447](https://github.com/rafhael100/imagine-portal/pull/447) | Coordenador que liga callback à consulta e consumo da state |
| K | [#449](https://github.com/rafhael100/imagine-portal/pull/449) | Purga limitada de estados vencidos/consumidos e proteção da chave |
| L | [#453](https://github.com/rafhael100/imagine-portal/pull/453) | Requisição fixa de troca OAuth com code+PKCE, sem enviar HTTP |
| M | [#455](https://github.com/rafhael100/imagine-portal/pull/455) | Parser estrito de resposta/token candidato com scope readonly |
| N | [#457](https://github.com/rafhael100/imagine-portal/pull/457) | Prova integrada PKCE→PostgreSQL→callback→request→resposta simulada |
| O | [#460](https://github.com/rafhael100/imagine-portal/pull/460) | Registro PostgreSQL de permissão por empresa, operador e propriedade exata |

**NÃO mesclar A–N separadamente ou em paralelo com #460**, para evitar merges duplicados. As PRs pequenas são trilha de revisão de cada incremento, e #460 é o diff único para futura análise.

## Evidência automatizada e limites

- GitHub Actions com PHP **8.3/8.4** × Node **22/24**; versão SEO RC32 `f46b65aaef9b10e32d02e415907cd916a43b548e`, F79 `fdae08985e89b9bd1323e42d1d64678ddc69e446` e F88 `56d7fa79904903f9af0c88646a59c0fa07546594`, fixados por SHA.
- Job **PostgreSQL 16 descartável** para migrações de revisão (up/down), AEAD XChaCha20-Poly1305, consumo atômico, anti-replay, retenção, código PKCE, parser de token e fingerprint de grants por tenant/ator.
- A allowlist de diff do CI aceita somente os contratos GSC, laboratórios, dois arquivos de migration explicitamente nomeados, HTML estático e workflow. O limite total de caminhos é 70, sem rotas, controllers, arquivos de credenciais, serviços de produção ou deploy.
- Os dados, domínios, CPF, códigos e tokens utilizados em laboratórios são **sintéticos**. O token exchange foi somente **planejado**: nenhuma requisição HTTPS ao Google foi realizada. Resultados não indicam acesso a clientes ou propriedade Google verificada.
- **Duas migrations foram adicionadas mas não aplicadas fora do PostgreSQL descartável de CI:** `2026_11_12_000001_create_portal_gsc_oauth_states.php` e `2026_11_12_000002_create_portal_gsc_property_grants.php`. Requerem revisão DBA antes de adoção em staging/produção.

## Bloqueios reais antes de operação

1. Autenticação de usuários Portal, MFA/RBAC e membership multiempresa **confiáveis**, sem aceitar empresa/ator declarados pelo cliente como prova.
2. Configuração Google Cloud válida (client ID/secret e callback **registrado exatamente**), consentimento e prova de acesso à propriedade GSC. Um registro SQL de grant ou fingerprint não prova propriedade atual.
3. Cliente HTTPS auditado com origem fixa, TLS, limite de tempo, política de erro/quotas e anti-SSRF, seguido de verificação de escopo e proveniência do token.
4. Vault de client secrets e refresh tokens com rotação, revogação, controle de chaves e logs sem segredos; política LGPD de retenção/expurgo e isolamento de tabelas por papéis.
5. Testes de ponta a ponta em **staging autenticado**, revisão independente AppSec/DBA/LGPD/SEO, ensaio de rollback e autorização específica de release.

Todo código novo permanece **DRAFT/NO-GO**. CI verde comprova apenas os contratos e integrações sintéticos descritos acima. Não houve merge em `dev/main`, publicação, DNS/301, chamada real ao Google, cobrança ou alteração de produção.
