# Imagine Comercial + Portal + SEO Engine — consolidação para homologação

**Atualizado em 09/11/2026 — documentação da ponta de desenvolvimento, sem aprovação de produção.**

## Estado objetivo e contratos

| Componente | Ponta em PR draft | O que está validado | O que NÃO está concluído |
|---|---|---|---|
| Imagine Comercial P0 | PR #19, branch `feat/f19-legacy-service-url-inventory-2026-11-09` | Astro 7, 15 páginas, SEO, QA browser, prévia de migração Cloudflare, catálogo assinado de laboratório | Checkout, dados/preços oficiais, política jurídica homologada, domínio e redirects ativos |
| Imagine SEO Engine P1 | PR #7, branch `feat/seo-f5-search-console-csv-import-2026-11-09` | Auditor local, links internos, análise de consultas CSV GSC e comparação de períodos | OAuth real, propriedade GSC autorizada, métricas do domínio, produto SaaS multiempresa |
| Imagine Portal — publicação comercial | PR #142, branch `feat/portal-comercial-f10-scoped-signed-issuer-2026-11-09` | Aprovação e assinatura sintéticas, ledger/outbox Postgres, fence, permissão tipada e recovery lease experimental | Emissor de identidade autenticada/MFA, writer limitado, consumidora outbox real, delivery receipt remoto, deploy |

As branches não estão integradas à `main`. Todos os PRs citados são **rascunhos empilhados**, não releases.

## Ordem de dependências existente

- **Comercial:** #1 → #2 → #5 → #6 → #7 → #8 → #9 → #10 → #11 → #12 → #13 → #14 → #15 → #16 → #17 → #18 → #19.
- **SEO Engine:** #2 → #3 → #4 → #5 → #6 → #7.
- **Portal (recorte comercial):** #119 → #121 → #123 → #131 → #133 → #134 → #137 → #138 → #139 → #142; o recorte depende da fundação Portal em desenvolvimento e **não substitui** outras cadeias de autenticação/segurança do mesmo projeto.

**Não mesclar apenas a ponta sem examinar os ancestrais, autorizações e compatibilidade das demais frentes do Portal.** Antes de qualquer merge, revalidar o conjunto integrado sobre uma branch de integração exclusiva, preferencialmente por PR de revisão.

## Evidências de CI reprodutíveis

- **Comercial + SEO F5:** https://github.com/rafhael100/imagine-comercial/actions/runs/42620760030 — 78 testes Node Comercial + 35 do motor SEO, build Astro, auditor de SEO e navegador aprovados. Workflow pinado em SEO commit `238acb0772dc7597f23ce3fd1761d68be1d35179`.
- **Portal → Comercial F19 + SEO F5:** https://github.com/rafhael100/imagine-portal/actions/runs/42621077771 — assinatura PHP com permissão sintética, aprovação e reserva de dispatch no PostgreSQL, build Astro do Comercial commit `365a6be1ac2a16f9eb39522ceb0c308e05f75302`, auditoria SEO e rejeição de hash divergente.
- **PostgreSQL F11:** https://github.com/rafhael100/imagine-portal/actions/runs/42622135820 — lease expirado recuperado, ack antigo negado, revogação e leitor RLS, duas conexões disputando um lease e apenas uma adquirindo.
- **PHP Laravel:** https://github.com/rafhael100/imagine-portal/actions/runs/42618194523 — 181/181 testes Laravel nas matrizes PHP 8.3 e 8.4 antes do F11 (o F11 é SQL de laboratório).
- **Cloudflare local:** https://github.com/rafhael100/imagine-comercial/actions/runs/42620759992 — validação em loopback de redirects candidatos e 404; sem redirecionamentos de produção.
- **Lighthouse laboratório:** https://github.com/rafhael100/imagine-comercial/actions/runs/42583567496 — homepage e Landing Pages 100/100 em performance, acessibilidade e SEO; não representa CWV de campo.
- **SEO Engine direto:** https://github.com/rafhael100/imagine-seo-engine/actions/runs/42610898272 — 35/35 testes offline aprovados.

## Gates antes do uso por clientes

1. **Identidade:** backend de sessão autenticada exclusiva do Portal, MFA e roles/grants obtidos de fonte confiável; membro e organização não podem vir de payload do cliente ou da GUC SQL controlável.
2. **Revogação:** transação com isolamento rigoroso, controle de versão dos grants no momento do efeito, proteção externa contra replay/rollback de backup, operador de banco com privilégios mínimos.
3. **Publicação:** writer de outbox autorizado, leasing/ack com prova verificável do destino, idempotência suportada no consumidor e reconciliação de entrega após timeouts. A confirmação local F11 não comprova deploy.
4. **Integridade:** chave privada protegida em cofre, rotação e revogação de chaves, políticas de aprovação editorial, versionamento por release e registro de auditoria imutável; evitar expor o assinador inferior como API direta.
5. **Site e SEO:** domínio completo inventariado com GSC autorizado, revisão por URL de 301/404, portfólio e termos/privacidade, conteúdo e marca validados, staging protegido e regressão de browser/SEO/Cloudflare no build integrado.
6. **Comercial e fiscal:** contratos, preços, descontos, checkout recorrente e emissão fiscal homologados no Portal; nenhum valor fictício deve ser publicado como oficial.
7. **Lançamento:** janela, responsável e rollback documentados e aprovação exclusiva para merge `main`, DNS, Cloudflare e produção, sem novas despesas não autorizadas.

## Próxima rodada automática recomendada

Concentrar-se em resolver dependências de autenticação e grants do Portal já existentes em outras branches, criar um plano de integração de código sem perder mudanças paralelas, realizar revisão de segurança de outbox/revogações e exercitar staging protegido **somente quando houver autorização e infraestrutura aprovada**. Priorizar resultados verificáveis por CI em vez de multiplicar PRs de PoC.

Nenhuma alteração neste documento autoriza merge, deploy, mudança de domínio, contratação de serviços ou acesso a dados reais.


## Atualização de dependências Comercial F25 — 09/11/2026

Esta seção é mais recente que a tabela inicial e substitui **somente** as referências de ponta do Comercial para fins de planejamento de homologação.

- Imagine Comercial: [PR #25](https://github.com/rafhael100/imagine-comercial/pull/25), branch `feat/f25-signed-catalog-required-mode-2026-11-09`. [CI #42657373288](https://github.com/rafhael100/imagine-comercial/actions/runs/42657373288): 99/99 testes do site e 35/35 SEO Engine, build Astro e QA navegador.
- Portal integração cruzada: [PR #153](https://github.com/rafhael100/imagine-portal/pull/153), empilhado sobre #151/#142; [CI #42657709386](https://github.com/rafhael100/imagine-portal/actions/runs/42657709386). Contrato sintético PHP+PostgreSQL→Astro aponta para commit F25 fixado e **rejeita ausência de todos os parâmetros do catálogo** quando `IMAGINE_CATALOG_REQUIRE_SIGNED=1`.
- F25 não atesta identidade, MFA, grants duráveis, aprovação editorial em sistema autenticado, replay após restauração de backup, writer com credenciais mínimas, idempotência de entrega e recibo do destino. O controle só proíbe fallback silencioso no build quando a intenção obrigatória é externamente definida.
- Antes de mexer em `main`: montar branch **exclusiva de integração**, revisar ancestrais e contratos atuais entre as frentes Comercial, Portal e SEO; executar CI cross-repo completo em ambiente protegido. Não mesclar cadeias de PRs automaticamente ou ignorar os trabalhos paralelos da família PORTAL-F22..F30.
- As despesas de infraestrutura, DNS, Cloudflare público, credenciais e dados reais seguem bloqueadas até autorização específica.


## Atualização F26 — verificação de recibo de destino (09/11/2026)

- [PR Portal Comercial #156](https://github.com/rafhael100/imagine-portal/pull/156) introduziu apenas um **verificador de atestação Ed25519 independente** de destino, no PHP, com checagens exatas de organização, release, hashes, dispatch, geração, tentativa, destino, ambiente, nonce, observação temporal e campos JSON.
- [CI de integração PHP→Astro F26 aprovado](https://github.com/rafhael100/imagine-portal/actions/runs/42662172399): 26 checagens positivas/negativas do verificador, além dos contratos F24/F25 de compilação e recusa de fallback. [Paridade PHP/Node aprovada](https://github.com/rafhael100/imagine-portal/actions/runs/42662172328). Demais CI do PR também aprovado nesta rodada.
- **Não há receipt remoto operacional.** Os testes usam uma chave de testemunha descartável criada no laboratório e os dados são sintéticos; a assinatura não comprova que arquivos foram publicados em Cloudflare.
- **ACK continua bloqueado**: falta obter testemunho assinado por destino independente de verdade, nonce emitido por serviço confiável, banco transacional com registro de consumo único e piso externo de revogação/rollback, acesso de serviço mínimo e confirmação da mesma árvore de arquivos no destino. Nunca chamar `ack_delivery_lease` apenas com confirmação de origem não autenticada.
- Não confundir os controles atuais de catálogo público (assinatura Portal→Astro) com a prova inversa de entrega (destino→Portal), que exige chaves e domínios criptográficos distintos.
- PRs empilhados, `main`, DNS, Cloudflare e produção seguem intactos.


### F26-B — complemento de nonce e ACK local descartável

- [CI #42663904358](https://github.com/rafhael100/imagine-portal/actions/runs/42663904358) executou **26 checagens criptográficas PHP** e ensaio PostgreSQL 18 real **apenas na CI**, recebendo o digest SHA-256 do build Astro F24.
- O banco experimental emitiu nonce único por tentativa, conservou histórico imutável, negou tentativa vencida, geração errada, digest/destino discrepante e replay, e aceitou **um único ACK local** dentro da transação de laboratório.
- **Não altera o gate operacional:** o recibo assinado foi validado por uma fixture PHP separada; ainda não existe prova remota recebida de destino autenticado nem ligação de identidade/grants/assinatura em uma única transação operacional. A credencial de um serviço consumidor mínimo e a proteção contra rollback de banco também não estão operacionais.
- O encerramento de publicação produtiva e o DNS ficam estritamente bloqueados. Prova SQL não é atestado Cloudflare, e o CI não deve reportar uma publicação realmente realizada.
