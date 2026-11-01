# Minha conta

A área do cliente reúne dados de acesso, responsável, empresa, telefones, site, cadastro fiscal, endereço e informações já enviadas em cada projeto. Contatos de briefing podem ser sugeridos no formulário quando ainda não há perfil salvo; a origem é indicada e o cliente confirma ao salvar. Os dados públicos de cada site não são alterados por uma edição no cadastro geral.

Acesso: GET/POST /api/account usam a sessão atual, sem parâmetro de outro usuário. Versões de cadastro evitam sobrescrever edições concorrentes. Telefones, e-mail e URL são validados. Trocar o e-mail de acesso exige a senha atual e encerra as outras sessões; o e-mail fiscal é independente. Alterar senha usa a rotina existente, revogando outras sessões.

Dados fiscais usam o mesmo cadastro canônico das notas, editável pelo botão específico. O histórico por projeto é somente leitura nesta área e leva ao briefing correspondente. Materiais bloqueados após estorno não são reexpostos pela conta. Nenhum número completo ou CVV de cartão é exibido ou salvo nessa área.
