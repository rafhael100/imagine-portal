# Validação semântica de recibo pendente Portal → Sites

Esta etapa acrescenta um validador de produto independente
(App\Domain\SitesIntegration\PortalSitesPendingReceiptValidator), que compara
o comando CreateProject v1 enviado pelo Portal com os quinze campos devolvidos
pelo receptor real do Sites #512.

O validador exige campos exatamente iguais aos schemas (sem extras), tipos
estritos, referências seguras, identidade de empresa/pedido/contrato/operação,
digest semântico no mesmo algoritmo versionado de Sites, identificador de site
gerado pelo Sites e estado de recepção estritamente pendente:

- status: recorded
- state: awaiting_official_authority
- authorityVerified: false
- clientAccepted: false
- canPublish: false

Toda alteração em empresa, contrato, revisão, chave, digest, estado ou flags é
recusada. A prova pareada Portal/Sites executa a validação usando **o recibo
real** do recorder do Sites num banco PostgreSQL isolado, além de testar
alterações maliciosas do mesmo retorno.

A validação de conteúdo NÃO autentica o transporte ou o emissor. A integração
operacional exigirá canal privado autenticado, concessão mínima de capability,
armazenamento imutável do recibo no Portal, recuperação após resposta perdida,
vínculo seguro com a tela do cliente, e verificações oficiais do pagamento
antes de abrir briefing. É proibido usar este recibo como autorização para
cobrar, iniciar produção ou publicar.

Sem conexão Asaas operacional, merge, deploy ou alterações da VPS.
