# PVI-G03-PREP — contrato candidato de navegação privada

## Situação e autorização

**Proposta documental para revisão; implementação de PVI-G03 bloqueada.** A Direção aprovou a entrega PVI-G02-A e solicitou avançar à próxima etapa nesta conversa. A sequência da [Issue #65](https://github.com/rafhael100/imagine-portal/issues/65) prevê PVI-G03 (contrato de rotas protegidas), dependente das fontes de identidade/sessão/membership/ownership e respectivos gates. Este recorte prepara esse contrato, sem declarar cumprida a condição de início de código funcional. Aceite visual ou dos testes da interface não homologa segurança.

Base exclusiva: [PR #73](https://github.com/rafhael100/imagine-portal/pull/73), commit `18f6fe779fb3f1b02a20bcfb1159ab6e95d5852d`, descendente do PR #68 em `aab05561611f876e5f5a55c9216d2a61e62da273`. Branch filha documental: `docs/portal-pvi-g03-contrato-rotas-2026-11-08`. PR separado DRAFT, com base na branch do #73, sem modificar o PR aprovado. Somente dois arquivos Markdown novos em `apps/portal/docs/`.

Foram relidos AGENTS, contratos de autoridade em `app/Domain/PortalAccess/Authority/`, política em memória de `OrganizationAuthorizationService`, `Permission`, rotas e bootstrap, bem como a proposta da Issue #65. `STATUS-ATUAL.md` segue ausente. Documentação operacional candidata dos PRs #49 e #69 foi confrontada somente por leitura; eles não foram incorporados. Os PRs #71 (arquitetura) e #72 (prévia offline com oito áreas) também são paralelos: este documento não os substitui, integra ou aprova. As seis áreas abaixo descrevem a base visual #73, não um catálogo definitivo do produto.

## Estado existente, sem inferências de prontidão

`PreviewArea` controla seis âncoras de um documento fictício. `ClientSection` descreve sete telas ilustrativas anteriores. Nenhum catálogo contém autorização. Os hashes `#pv-*` não são enviados ao servidor e nunca serão prova de acesso.

`PortalBoundaryVerdict::canAuthorizeHttp()` retorna **false**, inclusive quando `syntheticChecksPass` é true. `DenyingPortalAuthorities` retorna null em todos os resolvedores. `PortalSessionSnapshot` é livremente instanciável e não autentica ninguém. A política em memória verifica correspondência de ator/empresa/recurso, vínculo ativo e concessão explícita; não prova emissor, organização operacionalmente ativa, MFA, frescor autoritativo ou revogação concorrente. Nada disso será conectado a HTTP por esta proposta.

`routes/web.php` não registra rotas de negócio. O runtime da base contém somente as rotas preexistentes `up` e `storage/{path}`; o comentário histórico que menciona apenas `/up` não descreve sozinho a lista completa. Nenhuma dessas rotas é endpoint da prévia. Shell, componentes, CSS e navegação aprovados permanecem intactos.

## Catálogo candidato de leitura, ainda não registrado

Os caminhos são nomes para deliberação, **não URLs disponíveis**. Somente GET e HEAD são candidatos de leitura. HEAD deve realizar a mesma autorização e devolver os mesmos headers/status do GET, sem corpo. Nenhum GET ou HEAD deve selecionar empresa, renovar privilégio, contratar, aprovar, pagar, abrir solicitação ou gerar efeitos comerciais.

| Área da base visual | Âncora atual | Recurso HTTP candidato | Contrato futuro de acesso |
| --- | --- | --- | --- |
| Visão geral | `#pv-inicio` | `/portal` | sessão Portal e contexto empresarial válidos; cada resumo exige autorização própria antes de agregar |
| Meus serviços | `#pv-servicos` | `/portal/servicos` | vínculo/grant e titularidade dos serviços; não confundir serviço visível com contrato vigente |
| Performance AI | `#pv-performance` | `/portal/performance` | vínculo, concessão e elegibilidade comercial específica; sem acesso automático ao motor ou Google/Meta |
| Solicitações | `#pv-solicitacoes` | `/portal/solicitacoes` | escopo empresarial, concessão da área e política de cada item; abrir/criar/editar não faz parte deste catálogo |
| Universidade | `#pv-universidade` | `/portal/universidade` | benefício/elegibilidade vigentes e política própria; visibilidade não confirma matrícula |
| Financeiro | `#pv-financeiro` | `/portal/financeiro` | concessão financeira explícita e titularidade; sem PSP, cobrança, comprovante ou contrato real nesta etapa |

Não há endpoint candidato de CRM: login/sessão e identidade desse produto continuam independentes. Sites/landing pages mantêm contratos próprios. Não se assume que concessões do Portal sejam aceitas por outro produto.

O enum atual `Permission` contém somente `services.view`, `services.manage`, `billing.view` e `members.manage`. O acesso operacional das seis áreas **não** pode ser inferido desses quatro valores. A futura revisão deverá especificar a ação exata de cada recurso e seus grants; nenhum novo valor foi criado aqui. Uma permissão de escrita não implica leitura por inferência, nem vice-versa. Valores desconhecidos, curingas e papéis enviados pelo cliente nunca concedem acesso.

Detalhes por identificador, exports/downloads, pesquisa, paginação, APIs JSON, seleção de organização e qualquer método de mutação permanecem fora da proposta de implementação. Quando especificados, precisarão das mesmas fronteiras por item, filtros autorizados antes da paginação e prevenção de enumeração. Não incluir empresa, usuário ou recurso arbitrários em payloads só para satisfazer uma view.

## Cadeia de confiança candidata

A ordem abaixo expressa dependências de decisão, não uma lista de middleware já implementada. Nomes, pacotes, TTLs, storage, provedor MFA, roles PostgreSQL e topologia de autoridade continuam pendentes.

1. Resolver a requisição somente em infraestrutura futura aprovada, com transporte seguro e sessão Portal própria. Um cookie/handle é referência opaca: o navegador nunca fornece ator, MFA, grants, revisão, proprietário ou estado de organização como fatos confiáveis. Identidade CRM, e-mail igual ou ID textual coincidente não autentica no Portal.
2. Obter principal e estado de sessão de autoridade Portal confiável. Verificar emissor, integridade, expiração absoluta/inatividade, revogação, revisão/generation e nível de autenticação exigido para a ação. A política de MFA/step-up precisa ser ratificada antes de servir a área; não usar o valor demonstrativo de 600 segundos como decisão aprovada.
3. Resolver empresa selecionada no servidor. Um ID em URL, query, header, cookie ou formulário expressa no máximo uma intenção de seleção; é necessária nova validação de vínculo, estado da empresa e concessão. Ausência ou ambiguidade do contexto bloqueia dados. A seleção persistente de empresa será uma mutação separada, com CSRF e renovação/revalidação apropriadas; não será realizada por GET.
4. Resolver membership e permissões de fonte autoritativa, com frescor e revogação. Não confiar em arrays de papéis, hidden fields, claims não verificados, cache sem versão, DTOs instanciados pela aplicação ou resultado positivo de uma PoC. A fronteira T2/T3 permanece questão arquitetural aberta, não resolvida apenas por um middleware.
5. Para cada recurso, comprovar a empresa proprietária e a ação permitida a partir da autoridade do servidor, antes de materializar conteúdo. Listas, contagens, indicadores, filtros, nomes, links, tooltips, exports e agregados também são dados protegidos. Não buscar todas as empresas para depois esconder itens no Blade.
6. Aplicar política de produto/contrato/benefício sem compartilhar sessão com CRM ou presumir habilitação de Performance/Universidade. Estado comercial e grant técnico são condições distintas e ambos podem ser necessários.
7. Executar a leitura dentro de uma garantia de consistência/frescor cuja semântica de concorrência e revogação tenha sido aprovada. Uma checagem antes da consulta, sozinha, não resolve TOCTOU. Definir ponto observável de decisão, autoridade de versão e efeito de revogação concorrente com AppSec/DBA, sem inventar locks ou SQL nesta etapa.
8. Produzir DTOs mínimos de apresentação depois de autorizar todos os campos. Blade recebe somente textos/valores autorizados e os escapa. Dados reais não podem ser misturados com o aviso de demonstração fictícia. A transição de demo para conteúdo autorizado será entrega separada, com novo aceite visual e testes.
9. Aplicar resposta privada, falha fechada e auditoria mínima sem segredos. Falha de store, autoridade, cache ou verificação não pode cair no showcase, em payload antigo ou em uma decisão positiva sintética.

Restauração de backup exige condição adicional: o serviço não volta a atender usando uma revisão regressiva de sessão/grants. [Issue #64](https://github.com/rafhael100/imagine-portal/issues/64) e [PR #69](https://github.com/rafhael100/imagine-portal/pull/69) documentam risco e proposta de fence durável; Z4 não foi escolhido/homologado. Nenhum TTL em cache ou epoch dentro do mesmo backup resolve sozinho o problema. Recuperação permanece offline/fail-closed até reconciliação e liberação específicas, sem executar laboratório aqui.

## Respostas e ausência de enumeração — decisões candidatas

| Condição futura | Resposta candidata | Invariante |
| --- | --- | --- |
| Sem sessão/expirada/revogada | 401 em representação de erro; para navegação HTML, eventual redirecionamento para login só após contrato de auth próprio | não incluir recursos/empresas; não criar tela ou redirecionamento agora; destino de retorno precisa ser local e validado |
| Sessão válida, área conhecida sem concessão | 403 genérico quando a existência da área é pública e a política ratificar essa distinção | não revelar produtos contratados, papéis ou empresa alheia |
| Recurso inexistente ou fora do escopo autorizado | 404 indistinguível para ambos | mesmo envelope/campos/headers; não consultar sem escopo para explicar a diferença |
| Dependência de segurança indisponível/inconclusiva | 503 genérico, sem conteúdo protegido | nunca liberar por timeout, fallback local ou cache antigo; sem detalhes de host, SQL, tenant ou provedor |
| Erro inesperado | 500 genérico | sem stack trace, dump de request, token ou dados pessoais |

Esses códigos não são um comportamento implementado ou uma aprovação final de política HTTP. AppSec deverá revisar também timing, tamanho do corpo, redirects, cache, mensagens e tentativas repetidas; igualar status não comprova ausência de canal lateral. HEAD não deve contornar essas decisões. Métodos não especificados não ganham handlers por conveniência.

Candidato para páginas/dados privados: `Cache-Control: private, no-store`, sem compartilhamento por CDN/service worker ou cache de resposta; `Vary` não substitui isolamento e não torna seguro um cache compartilhado. Cache interno de dados/grants exigirá chaves com contexto, versão e invalidação aprovados. Navegação entre empresas, voltar/avançar, abas e logout exigem ensaios próprios. Não haverá dado sensível em URLs, hashes, títulos de analytics ou logs.

O CSP atual da demo impede scripts, conexão, imagens e forms. Não será afrouxado neste recorte. CSP de uma aplicação autenticada, CSRF, cookies seguros/HttpOnly/SameSite, rotação, session fixation, rate limiting e recuperação de acesso exigem desenho e revisão próprios; flags ou receitas genéricas não encerram esses gates.

Auditoria candidata: identificador de correlação gerado no servidor, ação categorizada, decisão e motivo interno minimizado, instante confiável e referência pseudonimizada quando justificada. Nunca cookie/token, senha, segredo MFA/recovery, claims completos, query com PII ou conteúdo de formulário. Retenção, acesso e finalidade dependem de decisão de privacidade. Erros externos não espelham motivo interno. Não foi criado logger ou envio de eventos.

## Fronteira com a composição Blade aprovada

O shell continua responsável apenas por layout, aviso fictício e slot confiável. `PreviewArea` não vira enum de rotas ou grants. `ClientSection` não vira fonte de entitlement. `PortalBoundaryVerdict` não vira allow token. Ocultar um link, desabilitar um botão ou remover um item do menu não protege o endpoint correspondente.

A futura adaptação precisa definir mapeamento explícito entre catálogos e DTOs e resolver namespace CSS `pv-*`/`pi-*`, estados de conteúdo e títulos. Sem essa revisão não haverá substituição automática de componentes nem unificação com a prévia de oito áreas do #72. Loading/empty/error/denied devem apresentar somente estado autorizado e não confirmar existência de recursos de outra empresa. Este PR não acrescenta esses estados ou ações às telas.

## Gates e decisões para revisão

| Decisão pendente | Responsabilidade de revisão proposta | Evidência necessária antes de código operacional |
| --- | --- | --- |
| Proveniência do principal, emissor Portal, sessão/MFA e recuperação | Direção + revisão interna AppSec | contratos ratificados e negativos de falsificação/replay/recuperação, sem aceitar fake como autoridade |
| Modelo de autoridade T2/T3 e privilégios de writers | Arquitetura + AppSec/DBA | escolha explícita de risco/arquitetura; evidência própria, sem presumir ratificação dos PRs #49/#51/#67/#69 |
| Semântica de revogação/consistência e anti-rollback | AppSec/DBA + responsável operacional futuro | ponto de decisão, barrier/fence, falhas e restauração; Z4 e risco residual ainda pendentes |
| Concessão por área, item e contrato/benefício | Produto + AppSec | vocabulário e fontes aprovados; isolamento por organização e por produto |
| HTTP, erros, cache, contexto de organização | Arquitetura + AppSec | matriz negativa aprovada e contrato 401/403/404/503/HEAD/redirect/CSRF sem enumeração |
| Adaptação das seis/sete/oito áreas de demos paralelas | Direção + UI/arquitetura | um mapa de navegação de produto aprovado; preservar demos históricas sem transformá-las em aplicação |
| Entrega executável e eventual laboratório | Direção, com pareceres técnicos | autorização específica para escopo, branch, ambientes descartáveis, testes e rollback; nenhuma execução por inferência |

Revisões internas seguem a governança de custo zero da [Issue #58](https://github.com/rafhael100/imagine-portal/issues/58); não se declara parecer independente ou especialista humano inexistente. P0/SEC-P01, G-ID/G-BB/D02-SEC/D03/RLS, AppSec/DBA, autoridade de identidade/sessão, SEC-R03/T3, UI-R03, CRM #27 e release **continuam abertos**. Aprovar o texto candidato não os fecha.

## Saída desta etapa

Entregar este contrato e a [matriz de ensaios futuros](PORTAL-PVI-G03-PREP-MATRIZ-ACEITE-2026-11-08.md) para revisão da Direção. Apenas após resolver os gates e emitir nova autorização específica poderá existir uma entrega de implementação de rotas/auth. Integração da DEV, merge dos PRs empilhados, laboratório, deploy, VPS, produção, despesas e go-live são decisões separadas e não foram solicitados por este contrato.
