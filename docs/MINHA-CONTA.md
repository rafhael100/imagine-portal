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
