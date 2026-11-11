# Minha conta

A área do cliente reúne dados de acesso, responsável, empresa, telefones, site, cadastro fiscal, endereço e informações já enviadas em cada projeto. Contatos de briefing podem ser sugeridos no formulário quando ainda não há perfil salvo; a origem é indicada e o cliente confirma ao salvar. Os dados públicos de cada site não são alterados por uma edição no cadastro geral.

Acesso: GET/POST /api/account usam a sessão atual, sem parâmetro de outro usuário. Versões de cadastro evitam sobrescrever edições concorrentes. Telefones, e-mail e URL são validados. Trocar o e-mail de acesso exige a senha atual e encerra as outras sessões; o e-mail fiscal é independente. Alterar senha usa a rotina existente, revogando outras sessões.

Dados fiscais usam o mesmo cadastro canônico das notas, editável pelo botão específico. O histórico por projeto é somente leitura nesta área e leva ao briefing correspondente. Materiais bloqueados após estorno não são reexpostos pela conta. Nenhum número completo ou CVV de cartão é exibido ou salvo nessa área.

## Arquivo de projetos e busca (F188/F192)
A tela de cliente filtra por nome, domínio, empresa ou identificador e por situação
de pagamento. A navegação exibe até oito projetos por página, sem alterar a
autorização da API: os projetos carregados pertencem exclusivamente à sessão
atual. A interface lista o código de pagamento em português com fallback neutro.
Filtros e paginação são uma visualização local, não uma consulta de terceiros
nem uma alteração de dados financeiros.

## Exportação e portabilidade limitada (F176–F183/F187)
Clientes podem baixar duas representações dos seus dados:
- JSON `GET /api/account/export`: cadastro, dados fiscais e campos autorizados
  de briefings/projetos, com versão de contrato e instante de extração.
- CSV `GET /api/account/projects.csv`: identificador, título, criação, situação
  de pagamento, empresa e domínio, em ponto e vírgula e UTF-8 BOM.

Esses downloads **não são exportação total de dados pessoais, contratos,
mensagens, extratos ou faturas**. Não devem ser anunciados como resposta
integral a solicitação LGPD. Dados internos, senhas, tokens e dados de
outros titulares não são incluídos. O CSV impede interpretação de valores
que comecem como fórmulas, inclusive após certos caracteres invisíveis.
Ambas as rotas exigem sessão de cliente, têm `Cache-Control: no-store`,
auditam a ação e dividem o limite de 20 exportações por hora por conta.
A interface informa erros 401/429 e não baixa respostas de erro como arquivo.
Sessão expirada leva de volta ao login.

## Segurança e registros da própria conta (F169–F191)
O painel apresenta somente contagens de sessões do titular (nunca cookies
ou identificadores de dispositivos), revogação autenticada das demais
sessões e histórico de ações reconhecidas. A consulta original
`GET /api/account/security-events` mantém os dez últimos eventos.
`GET /api/account/security-events?page=N` admite páginas de 1 a 30,
dez eventos por página e resposta `{events,page,has_more}`. A interface
permite consultar atividades anteriores sem expor o identificador interno
do log, IP, identificador de outro usuário ou dados de sessão.

Alteração de senha e e-mail e revogação de outras sessões revalidam
a situação do usuário e da sessão no ponto de gravação, mesmo quando
uma verificação de senha assíncrona começou antes de outra alteração.
Testes sintéticos verificam isolamento e cenários concorrentes.

## Estado de integração
Este conjunto segue na PR consolidada #392, de revisão, sem merge e sem
deploy. A aprovação automatizada dos testes não substitui revisão
independente AppSec/LGPD, homologação visual, plano de rollback ou
aprovação específica do gate de integração (issue #341).

## Integridade de ordenação e concorrência (F194–F196)
O banco possui índices idempotentes em `orders(user_id,created_at DESC,id DESC)`,
sessões por usuário, auditoria por titular e expiração de limites. Empates
de data de criação são desempatos pelo ID do projeto, mantendo a ordem da
listagem estável para o mesmo conjunto de registros.

As rotinas de alteração de senha e revogação de outras sessões revalidam
a existência da sessão atual dentro de uma transação SQLite **após**
as derivações/verificações assíncronas de senha. Se houver logout,
revogação ou alteração concorrente da credencial, respondem com conflito
e não recriam acesso nem revogam sessões de outra pessoa. Os testes
de corrida usam dados exclusivamente sintéticos.

## Carregamento paginado da área do cliente (F201–F206)

A interface usa `GET /api/account?compact=1` para carregar somente cadastro,
dados fiscais, total de projetos e **um briefing pago com empresa informada**
que sirva de sugestão de contato. Não transfere todos os projetos ao abrir
a tela. Ao salvar, usa `POST /api/account?compact=1` e preserva a validação
otimista de versão, sem retornar o histórico inteiro na resposta.

`GET /api/account/projects?page=N&payment=all|paid|other&search=...` lista
**8 projetos por página** do usuário autenticado, com contagem e indicação
`has_more`. A página fica limitada a 1–1000 e a busca a 120 caracteres;
termos são tratados literalmente (sem curingas SQL), com comparação
insensível a maiúsculas e diacríticos em português. Nome e domínio de
briefings só participam da busca quando o pagamento está confirmado.
A interface descarta respostas assíncronas antigas, dá opção de tentar
novamente após falha e nunca usa identificador de outro titular.

As rotas antigas `GET /api/account` e `POST /api/account`, sem o parâmetro
`compact`, mantêm a representação integral por compatibilidade. As exportações
JSON/CSV permanecem integrais e separadas da paginação da interface.

## Arquivos com volume excepcional (F210)

A navegação dinâmica admite até 1000 páginas de oito registros (8000 projetos)
por busca/filtro. Caso o total filtrado ultrapasse esse teto, a API indica
`limited: true`, encerra `has_more` no último resultado permitido e a tela
informa que a exportação completa continua disponível. Não há tentativa de
consultar páginas inválidas nem truncagem silenciosa do CSV/JSON.

## Limite antiabuso das consultas paginadas (F211)

Para reduzir pesquisas repetitivas custosas e preservar outros usuários,
`GET /api/account/projects` limita cada conta a 120 consultas por janela
de 60 segundos. A quota é independente por titular e separada das
exportações (20/hora). A interface informa quando é preciso aguardar e
não recomenda tentativas automáticas contínuas.

## Situação operacional e ordem dos projetos (F213)

O arquivo paginado aceita `phase=all|solicitado|planejamento|desenvolvimento|revisao|entregue` e `sort=newest|oldest`. Os valores são explicitamente permitidos; tentativas com valores desconhecidos recebem erro 400. Os filtros de etapa, pagamento, busca e ordenação são aplicados no SQL **antes** da paginação de oito registros. A tela apresenta as etapas em português e mantém o comportamento anterior quando os parâmetros são omitidos.

## Resumo das etapas (F215)

A consulta compacta retorna `project_summary`, com contagem de projetos nas etapas solicitado, planejamento, desenvolvimento, revisão e entregue, agregada somente pelo identificador da sessão autenticada. A interface exibe o resumo sem abrir ou revelar projetos de outra empresa; a API legada permanece sem novas informações obrigatórias.

## Planilha com etapas opcionais (F216)

O contrato legado `GET /api/account/projects.csv` mantém seis colunas. A interface de cliente passa a baixar `GET /api/account/projects.csv?columns=stage`, que acrescenta a coluna final **Etapa do projeto**. A exportação JSON inclui o campo opcional `phase` por projeto, preservando a versão de contrato legada; os dados continuam isolados por titular e protegidos contra fórmulas CSV.

## Acompanhamento da produção de sites (F218)

Nos projetos do tipo **site** com pagamento confirmado, a API paginada apresenta apenas `production.stage`, `production.progress` (0–100) e a data prevista `production.due_date`. A consulta é filtrada pelo titular; projetos não pagos, projetos sem registro de produção e produtos diferentes de site não recebem dados de produção. A interface apresenta etapa legível, progresso e previsão, sem expor URL de prévia privada, identificadores de equipe ou comentários internos. O acompanhamento não constitui promessa contratual nem libera a publicação.

## Projetos aguardando a revisão do cliente (F220)

A interface oferece o filtro `attention=review`, que retorna somente sites **pagos** do próprio titular cuja produção esteja efetivamente em `stage=review`, prontos para uma ação de aprovação ou ajuste. O resumo compacto acrescenta `requires_review`, contado no servidor com a mesma restrição de usuário/pagamento; o cartão exibe o acesso direto ao fluxo do pedido para revisar a prévia. Nenhuma aprovação ou publicação ocorre automaticamente.

## Acessibilidade de consulta e restauração de filtros (F221)

O controle **Limpar filtros** restaura texto, pagamento, etapa, ordenação e revisões pendentes para seus padrões, reiniciando na primeira página. Durante requisições assíncronas, a lista informa `aria-busy`, removido ao receber resposta ou erro. A navegação pelo teclado mantém indicação visível de foco.
