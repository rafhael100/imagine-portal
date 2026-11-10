# Portal Comercial F39 — PHP/PostgreSQL → Astro F38, pacote protegido verificável

Data: 09/11/2026. **Laboratório sintético e exclusivo de revisão; nenhuma implantação.**

## Objetivo

Ampliar o fluxo já validado na F25, de emissão PHP com Ed25519, reserva e revogação em PostgreSQL 18, build Astro e auditor SEO, até a conferência pós-compilação do pacote. Isso testa a **compatibilidade entre repositórios** do procedimento `npm run build:protected` (Comercial F37) e `npm run verify:protected` (F38). Não incorpora a autenticação real do Portal nem um publicador de produção.

## Código pinado e origem de confiança

- Portal: branch de teste F39, filha de F25 PR #153, incluindo o emissor PHP somente sintético e as provas PostgreSQL existentes.
- Comercial: commit fixo `b9044101cd8095189f6a922ffed90d7e9635c367`, candidato de integração [Comercial #38](https://github.com/rafhael100/imagine-comercial/pull/38).
- SEO Engine: commit fixo `238acb0772dc7597f23ce3fd1761d68be1d35179`.
- GitHub Actions somente `contents: read`, checkout sem persistência de tokens; chave Ed25519 de **teste descartável**, emitida pelo fixture PHP no `RUNNER_TEMP`. Banco PostgreSQL 18 em serviço efêmero sem dados de cliente.

## Sequência de laboratório

1. O emissor PHP produz envelope com Ed25519 e chave de teste, e o PostgreSQL verifica o ledger, fences e revogação sintéticos já existentes.
2. O build Astro roda com `IMAGINE_RELEASE_TARGET=staging` e `IMAGINE_CATALOG_REQUIRE_SIGNED=1`, todos os pins e envelope do fixture. `npm run build:protected` recusa fallback editorial e gera `dist/` + `artifacts/protected-build-review/PROTECTED-BUILD-REVIEW-ONLY.json`.
3. Um **passo separado de CI** relê os bytes do build, verifica novamente assinatura e equivalência PHP → HTML → hash e compara com o relatório, então passa o SHA-256 da árvore via `GITHUB_ENV` ao próximo passo. **É apenas simulação de passagem de confiança no mesmo runner**, não witness externo nem atestação impossível de adulterar pelo operador do runner.
4. `npm run verify:protected` relê os arquivos, verifica o mesmo envelope assinado e exige `IMAGINE_EXPECTED_BUILD_TREE_SHA256` recebido do passo anterior.
5. Testes negativos tentam hash falso, alvo `review`, homepage alterada após build e relatório adulterado; todos devem falhar. Depois o pacote é restaurado e deve passar.
6. Auditor SEO independente lê o HTML final. O PostgreSQL continua exercitando revogação e os testes antigos de hash incorreto e omissão total do catálogo assinado.

## Limites e gates de produção

A prova é útil para encontrar diferenças entre PHP, PostgreSQL, Astro e as rotinas F37/F38, mas **não dá autoridade de publicar**. O futuro fluxo real deverá usar sessão autenticada, grants e MFA, aprovação editorial com identidade verificável, chave guardada e revogação, testemunha remota/receipt de destino idempotente, hash esperado entregue por **canal externo autenticado e separado do pacote/runner**, logs e proteção contra restauração de banco, staging na VPS Leaseweb e autorização exclusiva antes de qualquer deploy/alteração de DNS.

Proibido confundir `GITHUB_ENV` com mecanismo de assinatura ou prova anti-rollback. Sem segredos de clientes, novo custo, merge em `main`, Cloudflare remoto, DNS ou publicação nesta PR.

## Evidências e pinagem das ações de CI

- [PHP 8.3 → PostgreSQL 18 → Comercial F38 + SEO #42731305139](https://github.com/rafhael100/imagine-portal/actions/runs/42731305139): sucesso no fluxo E2E (170/170 testes Comercial + 35/35 SEO), incluindo build protegido, verificador offline e recusas de hash, alvo, HTML e manifesto adulterados.
- As ações oficiais são pinadas por commit SHA completo no workflow: `actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1` (v7), `actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1` (v7) e `shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962` (commit da tag anotada v2 consultada via API GitHub). Isso reduz alterações inesperadas; **não certifica a segurança das dependências nem confere autoridade de publicação**.
- Uma nova execução do E2E **após a pinagem** é necessária para confirmar a compatibilidade. A versão anterior passou usando tags, antes da alteração dos commits de actions.
