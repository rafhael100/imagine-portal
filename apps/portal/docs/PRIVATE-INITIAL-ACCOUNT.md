# Primeira conta de desenvolvimento

A tela de acesso é restrita ao opt-in privado existente. Não existe cadastro público ou identidade criada automaticamente. Sem PORTAL_DEVELOPMENT_ORGANIZATION configurada com UUID válido, exibe acesso em preparação e não apresenta o formulário. O UUID seleciona o contexto da empresa; não é prova de membership nem autorização.

Antes da primeira conta: confirmar o e-mail do operador e a organização de desenvolvimento; implementar e validar o provisionamento administrativo de uso único em transação, com recusa de banco já populado, sem aproveitar fixtures sintéticas como identidades reais. A senha deve ser definida pelo operador, sem argumentos de shell, log ou chat; o MFA deve ser inscrito e confirmado pelo operador antes de ativar a conta. Não presumir e-mail verificado a partir de autor de commit.

Após provisionamento, configurar somente o UUID público da organização no ambiente privado e reiniciar a aplicação. Senha, segredo MFA e chaves permanecem fora do repositório. Os serviços de negócio continuam DENY. Este pacote não provisiona a identidade nem publica o gateway no domínio.
