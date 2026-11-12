# Atualização de precisão em esquemas existentes

A correção #422 preserva microssegundos em bancos criados a partir das migrations candidatas. Bancos já criados precisam de uma migration adicional; editar a migration histórica não os atualiza.

A migration 000015 amplia para timestamp(6) with time zone as dez colunas de segurança de identidades, sessões e desafios de e-mail. Executa as alterações em uma transação, com espera por locks limitada a cinco segundos e cada comando a trinta segundos. Não altera regras de identidade, RLS, grants ou status. A atualização não recupera frações já perdidas em registros históricos. Sua aplicação operacional requer janela revisada e avaliação dos locks e dependências no esquema efetivo.

O teste F116 recria os tipos antigos em uma transação descartável, grava uma identidade fictícia, executa a migration duas vezes e compara valores armazenados e metadados de RLS, proprietário, políticas e ACL de tabela/coluna. Verifica as dez precisões e a recusa explícita de rollback com perda de dados. O finally desfaz toda a preparação do teste. Não usa bases reais nem abre portas de autenticação.

O método down recusa reduzir precisão para evitar arredondamento e divergências em eventos de segurança. Recuperação operacional exige reparo adiante revisado; não executar migrate:rollback indiscriminadamente. O ensaio valida o método up real, mas não homologa toda a cadeia de migrations ou uma base operacional existente.

Login real continua pendente: as rotas Laravel ainda expõem apenas /up, as classes de emissão testadas estão em laboratórios CLI e não devem ser importadas pela borda HTTP, e a aprovação de segurança operacional não foi concedida por CI. Próxima integração deve implementar adaptadores com privilégios mínimos, persistência e revogação de sessão, CSRF e entrega de e-mail, mantendo identidades Portal/CRM independentes.
