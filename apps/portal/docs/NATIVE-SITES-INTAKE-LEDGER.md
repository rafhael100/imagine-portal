# Recebimento persistente do recibo Sites no Portal (somente laboratório privado)

O novo ledger `portal_onepage.site_intake_receipts` registra **somente** recibos
de solicitação pendente de Sites, depois de validação semântica contra o
CreateProject v1 original. Cada pedido aceita apenas um recibo imutável.
Reenvio idêntico retorna a mesma informação. Reenvio diferente falha; não
altera pedido, contrato nem origem do pagamento.

A gravação exige um login SQL `portal_onepage_intake_recorder`, separado do
login de navegação `portal_onepage_runtime`, com EXECUTE somente na função
`record_pending_site_intake`. O papel é NOLOGIN inicialmente e não é provisionado
automaticamente. Nenhuma rota HTTP permite ao cliente criar recibos.

O PostgreSQL reafirma empresa e contrato no pedido existente e recusa
todas as flags de autoridade/pagamento/publicação e campos extras. O
validador PHP anterior compara ainda o digest semântico derivado do comando
original. Esses passos não são substitutos para autenticação real do emissor
nem garantem que uma cobrança tenha sido recebida.

O leitor SQL `read_my_site_intake` devolve apenas `not_recorded` ou
`recorded`, siteRef e flags expressamente falsas. Ele revalida a sessão nativa
do comprador, empresa e publicação. A API de cliente não pode fornecer
empresa nem documento de autoridade. A visão continua sem status financeiro.

Testes devem rodar com Postgres18 descartável, duas empresas e compradores,
replays, conflitos, tipos de flags, RLS, imutabilidade, revogação e isolamento,
além de usar o recibo **real** obtido do Sites #512. Nenhuma role operacional
é liberada, nenhum serviço é provisionado e nenhum endpoint externo é chamado.

Instalação é explicitamente manual e restrita ao ambiente de homologação
depois de revisão independente. O rollback futuro deste ledger é destrutivo:
não executar sem backup e aprovação. A VPS não é alterada por esta PR.
