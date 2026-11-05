# PORTAL-F1-00 — Preparação do início controlado do Portal Imagine (v0.1)

**Data:** 05/11/2026  
**Projeto:** Portal Imagine — área institucional, cliente, comercial e administração  
**Repositório:** `rafhael100/imagine-portal`  
**Base factual inicial:** `main` em `50b683a499e674641df5749051f3d05f7ac3f627` (consultado em 05/11/2026)  
**Estado:** **PACOTE DE PREPARAÇÃO / PROPOSTA PARA DELIBERAÇÃO**. A criação deste documento não fecha gates, não aprova arquitetura nova, não concede autorização de programação/testes e não altera as decisões do Imagine Sites ou CRM Imagine.  
**Branch documental:** `docs/portal-f1-00-2026-11-05`. Nenhum merge na `main` por este instrumento.

> **Atualização normativa em 05/11/2026:** PORTAL-TEC-D01 **alternativa B (Laravel + PostgreSQL) aprovada expressamente** pelo responsável. Aplicação e banco do Portal continuam independentes do CRM. [Registro da decisão](PORTAL-TEC-D01-APROVACAO-ALTERNATIVA-B-LARAVEL-POSTGRESQL-2026-11-05.md). Esta atualização altera apenas o estado da D01; D02–D04 e gates de programação/integrações permanecem abertos.

## 1. Finalidade, regra de precedência e recorte

Preparar o próximo passo do Portal: inventariar o legado, propor uma sequência F1 inicial e tornar explícitas as decisões/gates que o responsável deverá aprovar **antes de solicitar programação ao Codex**. A orientação expressa mais recente do responsável foi prosseguir para o próximo passo PORTAL-F1-00, não autorizar indistintamente toda a engenharia.

As aprovações posteriores e específicas prevalecem sobre referências comerciais e técnicas históricas. Uma proposta não equivale à decisão aprovada; código existente não equivale a homologação. A versão técnica do Portal a ser desenvolvida depende de decisão de arquitetura; nada nesta minuta escolhe framework, esquema definitivo ou fornecedor.

**Limites nesta etapa:** edição documental na branch; sem alteração de código executável, sem compra, sem deploy, sem acionamento de integrações reais, sem dados reais, sem mudanças de DNS/Cloudflare/Asaas/Focus, sem migração, sem merge na main e sem reativação de rollback automático AGI-11-RB01. A pipeline existente do repositório pode ser disparada automaticamente por novos commits/PRs; não equivale a autorização de homologação nem teste operacional real.

## 2. Inventário factual do legado (não é certificação de qualidade)

| Evidência no repositório `main` | Constatação | Consequência para F1 |
|---|---|---|
| `package.json`, `README.md` | Versão 0.18.2; aplicação Node.js 22.16+/24; `pnpm`; interface e servidor; homologação local declarada | Existe base de implementação histórica e testes; não é projeto zerado |
| `server/store.mjs` | Persistência com `node:sqlite` / `DatabaseSync`; esquema de users/sessions/products/orders/payments/events/audit, migrações | Modelo atual não demonstra arquitetura multiempresa desejada; planejar decisão de banco, migração e entidades |
| `server/app.mjs`, `server/security.mjs` | Servidor HTTP, sessões, CSRF, senhas scrypt, API de pedidos e integrações | Reaproveitar conceitos e testes mediante revisão; não presumir que IAM, MFA e isolamento final já estão prontos |
| `portal.html`, `portal.js`, `*.js` | Interfaces de cliente/admin, produção, briefing, financeiro, BI, suporte, conta e checkout | Inventariar componente por componente antes de reescrever |
| `server/asaas.mjs`, `server/focus.mjs` | Adaptadores para pagamento/fiscal documentados; integrações reais pendentes | Desacoplar provedores da lógica de contratos; usar simulação apenas após autorização de testes |
| `tests/`, `.github/workflows/test.yml` | Testes versionados e workflow Node 22/24 ativado em push/PR | Planejar validação segura sem conectar produção; não declarar resultados de testes não executados nesta revisão |
| `README.md`, `docs/PROJETO.md`, `docs/OFERTA-ONEPAGE.md` | Declaram oferta antiga de R$ 990 em D0/D30/D60, R$ 79,90 e práticas do legado | **Não copiar** automaticamente o parcelamento em três cobranças separadas ou os preços para o novo checkout |

Esta revisão verifica arquivos-base e o inventário de árvore do GitHub; **não constitui auditoria exaustiva linha a linha, revisão de vulnerabilidades, diagnóstico de dependências ou prova de funcionamento**.

## 3. Regras funcionais já aprovadas e preservadas

1. O Portal centraliza contratação, catálogo, pedidos, projetos, documentos e relacionamento/atendimento da Imagine; serviços possuem contratos e estados próprios.
2. Sites Onepage/Landing Page são produzidos e hospedados pelo fluxo Imagine Sites com **Cloudflare Workers Static Assets + GitHub + serviços autorizados**, e não dependem do servidor legado do Portal como hosting padrão.
3. Site estático elegível pode ser publicado sem CRM; **formulário não pode captar sem destino CRM oficialmente vinculado e autorizado**, validado no backend, inclusive diante de página antiga/cache.
4. Aceite de contrato, orçamento e versão de site exige ação positiva de representante autenticado/autorizado, com prova da versão/escopo. Não há aprovação por silêncio.
5. Vínculo Site×CRM e mudança posterior respeitam conta de destino oficial; troca por chamado da Imagine, gratuita no serviço de desvincular/reintegrar, sem migração automática dos dados.
6. A cortesia CRM Básico é um benefício de elegibilidade de serviço Imagine; plano pago do CRM e o financeiro CRM têm autoridades independentes.
7. **Política comercial posterior:** implantação por Pix à vista com **5%** de desconto ou cartão de crédito em **até 3×** sem desconto Pix; mensalidades por Pix Automático com **5%** de desconto ou cartão recorrente sem desconto Pix; **sem Pix parcelado**. A primeira mensalidade é **padrão 90 dias**, sujeita à oferta/campanha aceita; preço-base, promoção e cumulatividade exigem contratos/versionamento.
8. GA4 é conexão voluntária, mas o Portal **não** importa nem exibe métricas do GA4; o cliente consulta essas informações no Google.
9. DNS autoritativo do cliente não é gerenciado pela Imagine como regra; preferência por Cloudflare quando o titular assim escolher. Não modificar e-mail/domínio sem autorização.
10. O relógio IN-127 é `T0` de persistência durável + 72h de entrega inicial + 360h de fila de falhas = **432h contínuas**, sem reinício de janela; prova final de entrega pertence ao CRM autorizado; descarte operacional e backup obedecem regras ratificadas.
11. Agentes/n8n não substituem autorização de identidades, sistemas de registro nem avaliação de risco; rollback automático AGI-11-RB01 segue **DESABILITADO**.

Referências normativas centrais:
- [P0 — fechamento documental Sites/Portal/CRM](../imagine-sites/P0-FECHAMENTO-DOCUMENTAL-01.md)
- [AUT-P01–P10 e revisão de fronteiras](../imagine-sites/P0-DOC03-RV01-CONSOLIDACAO-AUT-P01-P10-2026-11-04.md)
- [Política vigente Pix/cartão, no repositório CRM](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-POLITICA-VIGENTE-PIX-05-CARTAO-3X-2026-11-03.md)
- [PORTAL-D01 — aceite eletrônico, no CRM](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-D01-aceite-eletronico-versoes-orcamentos.md)
- [Topologia de fronteiras ARQ-01, no CRM](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/P0-ARQ-01-topologia-fronteiras-plano-fechamento.md)

## 4. Divergências que o Codex NÃO deve converter em comportamento vigente

| Código | Legado/proposta histórica | Precedência/ação segura |
|---|---|---|
| LEG-01 | Cobranças de implantação em D0/D30/D60 de 3 × R$330, assinatura fixa de R$79,90 | Nova regra é cartão em até 3× ou Pix à vista; confirmar preço vigente por produto/campanha/contrato. **Não ativar cobranças** a partir de valores legados |
| LEG-02 | SQLite de instância única e relação direta user → order | Aprovar arquitetura de tenants, organizações, contas, permissões e migração antes de persistir novos modelos |
| LEG-03 | Interface de cadastro/acesso do legado | Definir autenticação do Portal, integração de contas com CRM e política MFA/SSO antes de implementar |
| LEG-04 | Asaas/Focus indicados em código e documentos | Contratos, autorização e homologação operacional de PSP/fiscal permanecem específicos e abertos |
| LEG-05 | Fluxo de produção/publicação do Portal anterior | Respeitar manifesto de versão, aceite expresso, checklist de entrega e prova de publicação distinta de evento ENTREGUE |
| LEG-06 | Histórico de Cloudflare/DNS, backups e integrações disperso | Site, Portal e CRM com limites de infraestrutura, persistência e políticas de recuperação distintos |

## 5. Opções técnicas pendentes (submeter para decisão, NÃO aprovar por inferência)

### PORTAL-TEC-D01 — Stack de backend do novo Portal

- **A — Evolução controlada de Node.js**, substituindo gradualmente o SQLite por persistência e controles multiempresa adequados. Vantagem: maior aproveitamento inicial do legado; risco: exigir extensa refatoração de modelo, autenticação e finanças.
- **B — Backend Laravel + PostgreSQL**, com aplicação e banco próprios do Portal; recuperar apenas regras, componentes de interface e testes úteis do legado. Vantagem: convergência de conhecimento e ferramentas com o CRM sem compartilhar banco; risco: maior esforço de migração e adaptação.
- **Não recomendado:** manter o legado Node/SQLite monolítico e suas regras comerciais como solução final sem revisão.
- **Decisão posterior, em 05/11/2026:** **alternativa B aprovada expressamente**, nos limites registrados em [PORTAL-TEC-D01](PORTAL-TEC-D01-APROVACAO-ALTERNATIVA-B-LARAVEL-POSTGRESQL-2026-11-05.md). A comparação de alternativas acima permanece como histórico anterior à aprovação; não reabrir D01 sem nova deliberação específica.

### PORTAL-TEC-D02 — Identidade e acesso entre Portal e CRM

Propor titularidade de identidades, organizações, papéis, convite, MFA e eventual SSO, com **separação entre autoridade do Portal e autoridade de dados do CRM**. Definir fluxo de autenticação/autorização interserviço, desativação e recuperação, sem banco/sessão compartilhados por inferência.

### PORTAL-TEC-D03 — Persistência e migração do legado

Definir se há dados reais no legado e quais contratos/versões devem ser preservados; formalizar plano de migração **antes** de modificar schema. Se não houver dados reais, não inventar legado operacional. Backups e rollback requerem política aprovada, com restauração testável em fase própria.

### PORTAL-TEC-D04 — Recorte comercial/financeiro inicial

Fase inicial constrói **catálogo e contratos versionados sem cobrança real**. Contratação financeira real, Pix Automático, conciliação, cancelamento, fiscal e cortesia só entram após especificação de autoridade/PSP, aceite, custos e gates cruzados. Não ativar PSP apenas porque adaptador existe.

## 6. Sequência proposta de implementação (NÃO autorizada nesta v0.1)

| Marco | Resultado verificável futuro | Restrições e dependências |
|---|---|---|
| **F1-01** | Auditoria técnica do legado + arquitetura mínima e plano de migração; esqueleto isolado e testes de unidade somente após autorização | Não ativar contratos, integração CRM, autenticação real externa ou produção |
| **F1-02** | Modelo de empresa, conta, usuário, perfis e política de acesso, com teste de isolamento | Precisa D01/D02 e gate de segurança; sincronismo CRM via contrato, não DB compartilhado |
| **F1-03** | Área do cliente, catálogo e painel de serviços com dados fictícios e permissões | Sem cobrar, provisionar benefício ou criar contratos reais |
| **F1-04** | Solicitação de serviços, pedidos, briefing e arquivos privados | Versionamento/retensão e estados documentados |
| **F1-05** | Fluxo de revisão de prévias, aceite expresso e suporte | Autoridade da versão e LGPD aprovadas; sem publicação automática |
| **F1-06** | Domínio financeiro desacoplado, cálculo de ofertas e simulações | Sem gateway/PIX reais até homologação e autorização |
| **F1-07** | Contratos/eventos para Imagine Sites, CRM e ecossistema | Dependente dos P0-DOC03/05/06/12 e de contratos técnicos homologados |

**A ordem acima é proposta; não inicia F1-02 a F1-07 automaticamente quando F1-01 for eventualmente autorizada.**

## 7. Matriz de gates e autonomia

| Gate | Estado na data deste documento | Consequência |
|---|---|---|
| G-00 — registro/revisão do pacote PORTAL-F1-00 | Preparado como proposta v0.1 | Aprovação expressa ainda pendente |
| G-01 — arquitetura técnica D01 e identidade D02 | **PARCIAL** — D01 B aprovada; D02 aberta | Stack Laravel/PostgreSQL decidida; IAM/SSO e migração ainda exigem decisão específica |
| G-02 — inventário legado, estratégia de compatibilidade e dados | **PARCIAL** | Não reescrever nem migrar com base em suposição |
| G-03 — recorte F1-01 e critérios de teste/aceite | **PROPOSTA** | Não instruir Codex a criar PR de código antes de autorização específica |
| G-04 — segurança multiempresa, segredos e MFA | **ABERTO** | Não ativar acesso multiempresa real |
| G-05 — interfaces e autoridades Portal × Sites × CRM | **ABERTO** | P0-DOC03 e contratos RV-CT relevantes permanecem abertos |
| G-06 — pagamentos, propostas, aceites e contratos | **ABERTO/PARCIAL** | Nenhuma cobrança automática/real ou contratação definitiva |
| G-07 — infraestrutura, backups e implantação | **DOCUMENTAL/PARCIAL** | Leaseweb e R2 UE não equivalem a infraestrutura contratada/provisionada |
| G-08 — autorização explícita de programação por etapa | **NÃO CONCEDIDA por esta minuta** | Não criar branch de código, não programar, não executar testes intencionalmente |

Os P0-DOC01–P0-DOC14 do Imagine Sites **continuam com o estado registrado** em [P0-FECHAMENTO-DOCUMENTAL-01](../imagine-sites/P0-FECHAMENTO-DOCUMENTAL-01.md). A preparação F1 do Portal não os fecha silenciosamente; a autorização parcial, se futura e expressa, deve justificar o isolamento das tarefas não dependentes.

## 8. Critérios documentais para liberar **somente F1-01** no futuro

1. **D01 já aprovada na alternativa B**; detalhar D02 (identidades, autenticação e limites de responsabilidade) ou autorizar especificamente apenas diagnóstico read-only independente da decisão D02.
2. Congelar o escopo permitido da F1-01, arquivos-alvo, exclusões, dependências e riscos.
3. Confirmar que `main` permanece imutável; criar **apenas após autorização** uma branch de código como `dev/fast-track-portal-2026-11-05`, partindo de SHA conferido.
4. Comprovar que cada PR terá descrição de escopo, evidências de testes de software somente no momento permitido, diffs, SHA e revisão humana; **sem merge automático**.
5. Preservar bancos, credenciais e dados reais fora do repositório e do ambiente de testes.
6. Proibir deploy, chamadas financeiras/CRM reais, alterações de DNS, backup real e custo sem gates próprios.
7. Definir explicitamente quem pode liberar F1-02+ e demais gates, sem inferir aprovação em cadeia.
8. Manter uma matriz de riscos, rollback de código condicionado a autorização e sem AGI-11-RB01 automático.

## 9. Próximo instrumento de decisão recomendado

**PORTAL-F1-00-DEC-01 (a deliberar):** direção analisa esta minuta, escolhe a estratégia de backend/identidade ou autoriza exclusivamente um diagnóstico documental mais detalhado. Em seguida será preparado instrumento separado para autorização da **F1-01**, com compromisso claro de branch, testes, não implantação e gates.

**Status atualizado em 05/11/2026:** pacote de preparação redigido e D01 alternativa B aprovada; **P0 do Sites/Portal/CRM, D02–D04 e autorização de execução ainda abertos; código e produção não foram alterados**.
