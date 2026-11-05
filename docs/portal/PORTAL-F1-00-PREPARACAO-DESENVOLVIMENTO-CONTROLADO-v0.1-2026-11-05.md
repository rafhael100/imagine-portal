# PORTAL-F1-00 — Preparação do início controlado do Portal Imagine (v0.1)

**Data:** 05/11/2026  
**Projeto:** Portal Imagine — área institucional, cliente, comercial e administração  
**Repositório:** `rafhael100/imagine-portal`  
**Base factual inicial:** `main` em `50b683a499e674641df5749051f3d05f7ac3f627` (consultado em 05/11/2026)  
**Estado:** **PACOTE DE PREPARAÇÃO / PROPOSTA PARA DELIBERAÇÃO**. A criação deste documento não fecha gates, não aprova arquitetura nova, não concede autorização de programação/testes e não altera as decisões do Imagine Sites ou CRM Imagine.  
**Branch documental:** `docs/portal-f1-00-2026-11-05`. Nenhum merge na `main` por este instrumento.

> **Atualização normativa em 05/11/2026:** PORTAL-TEC-D01 **alternativa B (Laravel + PostgreSQL) aprovada expressamente** pelo responsável. Aplicação e banco do Portal continuam independentes do CRM. [Registro da decisão](PORTAL-TEC-D01-APROVACAO-ALTERNATIVA-B-LARAVEL-POSTGRESQL-2026-11-05.md). Esta atualização aprovou D01; D03–D04 e gates de programação/integrações permanecem abertos. **Atualização adicional de 05/11/2026:** D02 recebeu aprovação funcional para **logins independentes Portal × CRM, sem SSO entre eles nesta versão**; integração de identidade com Universidade Imagine e Performance AI fica apenas para avaliação futura, sem aprovação de SSO. [Decisão D02](PORTAL-TEC-D02-LOGINS-INDEPENDENTES-PORTAL-CRM-DECISAO-2026-11-05.md). O detalhamento técnico de MFA, RBAC, organizações, recuperação e autenticação servidor a servidor continua aberto. **PORTAL-TEC-D03 aprovada expressamente em 05/11/2026:** alternativa D03-MIG-B e modelo lógico direcional, com migração de dados reais sujeita a inventário, proteção e autorização específica. [Aprovação D03](PORTAL-TEC-D03-APROVACAO-D03-MIG-B-MODELO-LOGICO-2026-11-05.md). **D04-FASE-A aprovada expressamente como diretriz documental**: catálogo, ofertas e contratos simulados sem pagamentos reais. [Ratificação D04](PORTAL-TEC-D04-APROVACAO-FASE-A-CATALOGO-OFERTAS-SIMULADAS-2026-11-05.md). **Esclarecimento posterior D03-R02:** legado é protótipo com **dados somente de teste**; aproveitar **principalmente ideias de negócio e fluxos**, não planejar migração nem reutilização obrigatória de código. [Decisão D03-R02](PORTAL-TEC-D03-R02-LEGADO-PROTOTIPO-FLUXOS-NEGOCIO-DADOS-TESTE-2026-11-05.md).

> **Atualização documental 05/11/2026 — F1-01 preparada, NÃO autorizada para codificação:** [pacote da fundação técnica F1-01 v0.1](PORTAL-F1-01-FUNDACAO-TECNICA-ESCOPO-CONTROLADO-PREPARACAO-v0.1-2026-11-05.md). Primeiro recorte sugerido (**F1-01-A**) limita-se a um scaffold Laravel isolado, configuração de exemplo sem segredos e testes locais somente após autorização específica. **Nenhum código, PR de implementação, VPS, banco real, merge ou deploy autorizado.** A sequência F1-02+ e as demais subfases não são liberadas.

> **EXECUÇÃO CONTROLADA F1-01-A (05/11/2026):** autorização específica concedida posteriormente para **somente o scaffold Laravel isolado**, implementado sob `apps/portal/` na branch `dev/fast-track-portal-2026-11-05`; [PR de código #11 em draft](https://github.com/rafhael100/imagine-portal/pull/11) e [registro documental de execução](PORTAL-F1-01-A-REGISTRO-EXECUCAO-CONTROLADA-PR11-2026-11-05.md). Este registro **não** libera F1-01-B/C, F1-02+, merge na main, testes não executados, produção ou fechamentos P0. Avisos anteriores de “F1-01-A não autorizada” devem ser lidos conforme a sequência temporal de decisões.

> **REVISÃO E PRÓXIMA ETAPA (05/11/2026):** F1-01-A **concluiu a validação automatizada inicial** em PHP 8.3/8.4, com lockfile e auditoria de dependências; [relatório](PORTAL-F1-01-A-RELATORIO-VALIDACAO-CI-LOCKFILE-2026-11-05.md). A [revisão RV01 do PR #11](PORTAL-F1-01-A-RV01-REVISAO-PR11-INTEGRACAO-CONTROLADA-2026-11-05.md) recomenda **INT-A**: preservar a fundação na branch de desenvolvimento e PR draft, **sem merge**, e deliberar sobre uso do ramo como base futura. A [F1-01-B v0.1](PORTAL-F1-01-B-MODELO-ORGANIZACOES-MEMBERSHIPS-PERMISSOES-PROPOSTA-v0.1-2026-11-05.md) propõe **B-A — contratos de organizações/memberships/permissões apenas com fixtures fictícias, sem banco e sem login**. **INT-A e B-A ainda não receberam aprovação expressa como decisões novas; F1-01-B não está autorizada para programação.** Nada altera `main`, VPS, D02-SEC ou P0.

> **APROVAÇÃO F1-01-B-A (05/11/2026):** foi ratificada a programação **exclusivamente da modelagem pura de organizações, memberships, papéis e permissões com testes sintéticos**, em ramo filho da fundação validada e PR draft com base na branch de desenvolvimento, **sem merge**. Ver [DEC-01 aprovada](PORTAL-F1-01-B-DEC-01-APROVACAO-PROGRAMACAO-CONTROLADA-2026-11-05.md), [instrução Codex](PORTAL-F1-01-B-A-TAREFA-CODEX-APROVADA-2026-11-05.md) e [issue #12](https://github.com/rafhael100/imagine-portal/issues/12). **A execução não foi iniciada por esta atualização; B-B/C, F1-02, `main`, VPS, P0 e integração de sistemas permanecem bloqueados.** Avisos anteriores de “B-A não autorizada” são estados históricos anteriores à aprovação.

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

**Diretriz aprovada em 05/11/2026:** logins e sessões **independentes** entre Portal e CRM, sem SSO nessa relação na primeira versão, com bases, credenciais e autoridades de autorização separadas. Integração operacional por APIs e contas de serviço autenticadas permanece possível sem compartilhar login humano. [Decisão D02](PORTAL-TEC-D02-LOGINS-INDEPENDENTES-PORTAL-CRM-DECISAO-2026-11-05.md).

**Ainda por especificar:** MFA e sessões de cada produto, identidades, organizações, papéis, convites, recuperação, proteção entre tenants, revogação e autenticação servidor a servidor. **Estudo posterior, não aprovado:** eventual integração de identidade do Portal com Universidade Imagine e Imagine Performance AI, sem revogar sua experiência de acesso pelo Portal nem seus contratos próprios.

### PORTAL-TEC-D03 — Persistência e migração do legado

**Diretriz APROVADA:** [D03-MIG-B e modelo lógico direcional](PORTAL-TEC-D03-APROVACAO-D03-MIG-B-MODELO-LOGICO-2026-11-05.md). Backend Laravel/PostgreSQL novo. **[Esclarecimento D03-R02](PORTAL-TEC-D03-R02-LEGADO-PROTOTIPO-FLUXOS-NEGOCIO-DADOS-TESTE-2026-11-05.md):** antigo Portal era protótipo com dados de teste; reutilizar principalmente conceitos de negócio e fluxos, não migrar SQLite por padrão nem portar código por obrigação. Descarte irreversível, se um dia necessário, exige checagem mínima para evitar perda acidental de informação relevante; isso não torna a migração uma etapa obrigatória.

### PORTAL-TEC-D04 — Recorte comercial/financeiro inicial

**D04-FASE-A APROVADA COMO DIRETRIZ DOCUMENTAL:** [ratificação](PORTAL-TEC-D04-APROVACAO-FASE-A-CATALOGO-OFERTAS-SIMULADAS-2026-11-05.md). Fase futura inicial com catálogo, preços/campanhas, cálculo determinístico de ofertas e versões contratuais usando **somente dados fictícios, sem cobrança real**. [Proposta original](PORTAL-TEC-D04-CONTRATACAO-PRECOS-FINANCEIRO-PROPOSTA-v0.1-2026-11-05.md). Fases B e C **não** aprovadas. Contratos e integrações reais, Pix Automático, conciliação, cancelamento, fiscal e benefício cortesia dependem de autorização, PSP, segurança e P0; a aprovação de escopo **não libera código**.

## 6. Sequência proposta de implementação (NÃO autorizada nesta v0.1)

| Marco | Resultado verificável futuro | Restrições e dependências |
|---|---|---|
| **F1-01** | [Pacote técnico documental v0.1 preparado](PORTAL-F1-01-FUNDACAO-TECNICA-ESCOPO-CONTROLADO-PREPARACAO-v0.1-2026-11-05.md); futura F1-01-A: skeleton Laravel isolado, README, configuração fictícia, testes locais condicionados | Reaproveitar apenas ideias/fluxos do protótipo; sem código autorizado, migração SQLite, contas reais, PSP, CRM, VPS, deploy ou merge |
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
| G-01 — arquitetura técnica D01 e identidade D02 | **PARCIAL** — D01 B aprovada; D02 logins independentes aprovados, detalhamento aberto | Stack Laravel/PostgreSQL; sem SSO Portal–CRM; MFA/RBAC/contas de serviço e migração ainda pendentes |
| G-02 — inventário legado, estratégia de compatibilidade e dados | **PARCIAL — D03-MIG-B aprovada e D03-R02 refinada** | Legado é protótipo com dados de teste; aproveitar ideias e fluxos; nova arquitetura não requer migração por padrão. Verificação mínima apenas antes de eventual descarte irreversível |
| G-03 — recorte F1-01 e critérios de teste/aceite | **PACOTE DOCUMENTAL PREPARADO, NÃO APROVADO PARA EXECUÇÃO** | F1-01-A proposta restrita ao scaffold isolado; não instruir Codex a criar PR de código antes de autorização específica |
| G-04 — segurança multiempresa, segredos e MFA | **ABERTO** | Não ativar acesso multiempresa real |
| G-05 — interfaces e autoridades Portal × Sites × CRM | **ABERTO** | P0-DOC03 e contratos RV-CT relevantes permanecem abertos |
| G-06 — pagamentos, propostas, aceites e contratos | **PARCIAL — D04-FASE-A direcional aprovada** | Catálogo/ofertas/contratos simulados no escopo futuro; B/C, PSP, aceites e cobranças reais bloqueados |
| G-07 — infraestrutura, backups e implantação | **DOCUMENTAL/PARCIAL** | Leaseweb e R2 UE não equivalem a infraestrutura contratada/provisionada |
| G-08 — autorização explícita de programação por etapa | **NÃO CONCEDIDA por esta minuta** | Não criar branch de código, não programar, não executar testes intencionalmente |

Os P0-DOC01–P0-DOC14 do Imagine Sites **continuam com o estado registrado** em [P0-FECHAMENTO-DOCUMENTAL-01](../imagine-sites/P0-FECHAMENTO-DOCUMENTAL-01.md). A preparação F1 do Portal não os fecha silenciosamente; a autorização parcial, se futura e expressa, deve justificar o isolamento das tarefas não dependentes.

## 8. Critérios documentais para liberar **somente F1-01** no futuro

1. **D01 já aprovada na alternativa B e D02 aprovada para logins independentes Portal–CRM**; detalhar MFA/RBAC, identidades, contas de serviço e limites de responsabilidade (ou autorizar especificamente apenas diagnóstico read-only).
2. Congelar o escopo permitido da F1-01, arquivos-alvo, exclusões, dependências e riscos.
3. Confirmar que `main` permanece imutável; criar **apenas após autorização** uma branch de código como `dev/fast-track-portal-2026-11-05`, partindo de SHA conferido.
4. Comprovar que cada PR terá descrição de escopo, evidências de testes de software somente no momento permitido, diffs, SHA e revisão humana; **sem merge automático**.
5. Preservar bancos, credenciais e dados reais fora do repositório e do ambiente de testes.
6. Proibir deploy, chamadas financeiras/CRM reais, alterações de DNS, backup real e custo sem gates próprios.
7. Definir explicitamente quem pode liberar F1-02+ e demais gates, sem inferir aprovação em cadeia.
8. Manter uma matriz de riscos, rollback de código condicionado a autorização e sem AGI-11-RB01 automático.

## 9. Próximo instrumento de decisão recomendado

**PORTAL-F1-00-DEC-01 (a deliberar):** direção analisa esta minuta, escolhe a estratégia de backend/identidade ou autoriza exclusivamente um diagnóstico documental mais detalhado. Em seguida será preparado instrumento separado para autorização da **F1-01**, com compromisso claro de branch, testes, não implantação e gates.

**Situação consolidada em 05/11/2026:** D01 Laravel/PostgreSQL **aprovada**; D02 logins Portal–CRM separados **aprovados**; D03-MIG-B/modelo lógico **aprovados** e D03-R02 protótipo com dados de teste **ratificada**; D04-FASE-A **aprovada como diretriz**. **F1-01-A implementada e validada por CI no PR #11**, mas draft, não integrada nem liberada para produção. **Revisão RV01 e proposta F1-01-B B-A preparadas, sem aprovação de programação B**. D02-SEC, D03 físico, D04-B/C, P0, decisão de destino da integração, `main`, VPS e deploy preservados conforme gates.
