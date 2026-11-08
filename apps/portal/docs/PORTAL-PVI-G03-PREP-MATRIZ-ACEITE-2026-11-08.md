# PVI-G03-PREP — matriz candidata de aceite e ensaios

## Distinção de evidências

Complementa o [contrato candidato](PORTAL-PVI-G03-PREP-CONTRATO-ROTAS-2026-11-08.md), baseado no PR #73 em `18f6fe779fb3f1b02a20bcfb1159ab6e95d5852d`. Esta matriz é **documental**: todos os casos G03-T01..T24 abaixo são **NOT RUN**. Não há servidor HTTP de negócio, adaptador confiável, cliente real, laboratório PostgreSQL ou permissão para implementar autenticação nesta entrega.

Evidência já existente da base visual: PR #73, [Laravel PHP 8.3/8.4](https://github.com/rafhael100/imagine-portal/actions/runs/42527980638) com 160 testes/2.627 assertions por versão; [Node 22/24](https://github.com/rafhael100/imagine-portal/actions/runs/42527980668) com 65 testes por versão; Chromium/offline locais descritos no documento PVI-G02-A. Esses resultados comprovam somente seu recorte, **não** os ensaios operacionais abaixo. A execução sintética da base já verifica que o verdict não autoriza HTTP e que renderizar Blade não cria rota/sessão/banco/HTTP; não equivale a E2E privado.

Os atores e empresas dos futuros ensaios serão **exclusivamente fictícios**: ator A com vínculo em Empresa Aurora; ator B com vínculo em Empresa Boreal; recurso RA de Aurora e RB de Boreal; ator C sem vínculo. Identificadores locais distintos, sem e-mails/nomes/credenciais reais. A montagem deverá separar entrada hostil da autoridade de referência, para não provar somente que um fake aceita o que ele mesmo produziu.

## Casos propostos, não executados

| ID / status | Preparação e estímulo futuro | Oráculo obrigatório | Gate relacionado |
| --- | --- | --- | --- |
| G03-T01 — NOT RUN | sessão Portal válida de A; grant específico; empresa e RA autoritativos; GET/HEAD em cada um dos seis destinos | apenas campos autorizados; HEAD sem corpo, com mesma decisão do GET; sem efeito comercial | G-ID, G-BB, D02-SEC, PVI-G03/G04 |
| G03-T02 — NOT RUN | ausência de handle, sessão expirada/revogada ou referência aleatória | negar sem corpo protegido; resposta genérica; nenhum redirect arbitrário | SEC-P01, G-ID |
| G03-T03 — NOT RUN | enviar actor, role, grants, MFA e owner falsos em query/header/form/cookie, inclusive alegando administrador | campos hostis não se tornam autoridade; nenhum grant ou dado adicional | P0, autoridade T2/T3 |
| G03-T04 — NOT RUN | mesmo e-mail/ID textual de A em CRM; cookie/handle de CRM no Portal | identidade CRM não autentica no Portal nem cria sessão/SSO | D02-SEC, CRM #27 |
| G03-T05 — NOT RUN | A seleciona Boreal alterando URL/query/header/contexto de empresa | seleção não autoriza; nenhum nome, contagem, item ou link de Boreal | G-BB, D03/RLS |
| G03-T06 — NOT RUN | A pede RB conhecido e um ID inexistente, com sessão/contexto Aurora | ambos indistinguíveis conforme política ratificada; sem owner/nome no erro | G-BB, anti-enumeração |
| G03-T07 — NOT RUN | C sem vínculo; vínculo suspenso/revogado; empresa suspensa; grant ausente/desconhecido | negar em cada variação; enum/snapshot/papel fictício não substitui fonte atual | G-ID, G-BB |
| G03-T08 — NOT RUN | trocar empresa legitimamente e repetir em abas, histórico e cache velho | contexto e autorização reavaliados; nenhuma resposta da empresa anterior aparece à outra | sessão, cache, G-BB |
| G03-T09 — NOT RUN | listar/filtrar/paginar/agregar dados mistos RA/RB por mecanismo futuro autorizado | filtragem antes de paginação/agregação; sem total, contagem ou indicador de Boreal | D03/RLS, privacidade |
| G03-T10 — NOT RUN | testar entitlement separado de grant; contrato cancelado ou benefício expirado | não habilitar Performance/Universidade/Sites/Financeiro por presença no menu | produto, PVI-G04 |
| G03-T11 — NOT RUN | MFA insuficiente ou step-up vencido segundo política futura ratificada | negar/desafiar conforme contrato próprio; nenhuma flag vinda da requisição resolve | G-ID, D02-SEC |
| G03-T12 — NOT RUN | revogar sessão ou membership antes da decisão; cache mantém revisão anterior | nenhum acesso pós-revogação no ponto semântico aprovado; sem fallback em cache | revogação, AppSec/DBA |
| G03-T13 — NOT RUN | concorrer revogação com leitura, troca de empresa e alteração de owner/grant | resultados lineares/consistentes segundo contrato ratificado; registrar ponto de decisão e ausência de vazamento | concorrência, D03/RLS |
| G03-T14 — NOT RUN | autoridade de identidade/membership/owner indisponível; timeout, exceção, dados malformados | falha fechada, erro genérico; zero payload protegido, zero uso de fake permissivo | P0, disponibilidade |
| G03-T15 — NOT RUN | fonte de dados falha durante consulta ou composição parcial | não devolver página parcial protegida ou dado de cache velho; sem stack/SQL/tenant no erro | falha de banco, D03 |
| G03-T16 — NOT RUN | recuperar snapshot anterior à revogação em ambiente descartável futuro, sem serving | serviço permanece fechado até reconciliação/fence; sessão/grant revogado não ressuscita | SEC-R03, T3, release |
| G03-T17 — NOT RUN | usar `syntheticCandidate`, `PortalSessionSnapshot`, fakes ou resultado de policy como se fossem principal operacional | nenhum caminho de autorização HTTP habilitado por objetos de teste; canAuthorizeHttp da base continua false | P0, separação demo/real |
| G03-T18 — NOT RUN | XSS nos campos textuais e atributos; HTML arbitrário em fonte de apresentação | escape de texto, slots só de templates confiáveis; sem script/atributo executável ou dado indevido | AppSec, composição |
| G03-T19 — NOT RUN | GET/HEAD tentando selecionar empresa, contratar, criar solicitação ou pagar; métodos não previstos | nenhuma mutação/efeito externo; sem handler implícito; mutações futuras exigem contrato CSRF próprio | CSRF, semântica HTTP |
| G03-T20 — NOT RUN | respostas privadas em cache compartilhado, back/forward após logout, logout em outra aba/dispositivo | nenhum conteúdo reutilizado fora da sessão/contexto vigente; testar política no navegador e infraestrutura futura | sessão, cache, privacidade |
| G03-T21 — NOT RUN | erros/retries repetidos e observação de corpo/timing/headers/redirects para RB vs inexistente | envelope consistente e canal lateral dentro de limites definidos por AppSec; sem URL de retorno externa | anti-enumeração, abuso |
| G03-T22 — NOT RUN | observar logs, URLs, headers e auditoria de sucessos/negações/falhas | correlação minimizada; zero cookie/token/senha/MFA/recovery/PII desnecessária | privacidade, auditoria |
| G03-T23 — NOT RUN | acionar UI para CRM/Performance/Universidade sem habilitação separada | nenhum SSO, compartilhamento de sessão, anúncio, matrícula ou integração decorrente da navegação | produtos, CRM #27 |
| G03-T24 — NOT RUN | revisão teclado/leitor de tela, mobile, estados empty/loading/denied/erro e dados parciais | apresentação acessível sem confirmar dados de outra empresa; revisão humana registrada sem certificar por screenshot | UI-R03, PVI-G05/G06 |

Cada caso precisa de subcasos por área, método e classe de ameaça quando aplicável. T01 não autoriza rodar contra produto real nem preencher a aplicação com mocks permissivos. T09/T16 dependem de autorização separada para storage/laboratório; esta matriz não a concede. Nenhum teste poderá usar cliente, fornecedor, produção ou credenciais reais.

## Protocolo de evidência para execução futura

1. Fixar SHA de implementação, SHA da autoridade de referência, versões de runtime, infraestrutura descartável e origem sintética de fixtures; registrar responsável, data e escopo autorizado.
2. Registrar o estado esperado **antes** da execução. Casos de concorrência precisam de barreiras e ordem observável, sem usar apenas sleeps como evidência de consistência. Nenhum positivo deve esconder negativo esperado ou marcar conhecido gap como segurança homologada.
3. Preservar saída do teste, status, contagem, código de retorno, envelope HTTP redigido e correlações minimizadas. Hashes e artifacts devem apontar para a mesma HEAD; não reutilizar screenshots antigas para alegar um fluxo novo.
4. Marcar PASS, FAIL, BLOCKED ou NOT RUN por caso com evidência. Job verde de lint/Markdown/Blade não muda NOT RUN de HTTP/PG/MFA/restore. Falta de adaptador, gate ou ambiente é BLOCKED, não aprovação.
5. Definir descarte/rollback do ambiente futuro antes de provisionar: interromper serving, retirar fixtures e credenciais descartáveis, confirmar ausência de dados reais e de exposição. Não executar restauração, SQL, criação de banco ou provisionamento nesta fase.
6. Submeter conclusões à Direção e às revisões técnicas internas de AppSec/DBA, com risco residual explícito. Não inventar parecer independente, custo zero garantido de infraestrutura ou aprovação de fornecedor.

## Verificações efetivas desta entrega documental

A validação desta etapa será restrita a diff Markdown, links internos e invariantes de escopo: exatamente dois arquivos novos sob `apps/portal/docs/`, sem alteração de rotas, bootstrap, classes, testes existentes, componentes, manifestos ou workflows. As referências de paths e métodos acima são texto candidato, não configuração executável.

Na publicação, o PR deverá registrar SHA final, comandos e resultados realmente observados. Workflows existentes de Laravel/Node podem executar automaticamente; seu resultado será reportado separadamente e não representa execução dos casos G03-T01..T24. Capturas PVI-G02-A permanecem as da composição aprovada, sem novas alegações visuais para este diff documental.

## Critério de saída e decisões seguintes

**Saída desta preparação:** dois documentos revisáveis e decisão sobre catálogo, fronteiras e plano de ensaios. **Não é saída dos gates PVI-G03/G04.** Antes de programação operacional, resolver pendências da tabela de decisões do contrato e emitir autorização explícita para o recorte executável. Os critérios de release, integração de branches e deploy continuam separados.

Nenhum gate foi encerrado: P0/SEC-P01, G-ID/G-BB/D02-SEC/D03/RLS, autoridade de identidade/sessão, AppSec/DBA, SEC-R03/T3, UI-R03, CRM #27 e release. `main`, DEV, PRs anteriores/paralelos, VPS, produção e serviços externos permanecem preservados. Sem merge, deploy, integração, banco, SQL/migrations, login, laboratório ou despesas.
