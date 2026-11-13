# Portal Imagine — transporte de comprovante sem vazamento por log

**Estado: baseline fail-closed, sem provedor SMTP contratado, sem envio de mensagens e sem operador real.**

## Mudança entregue

O `apps/portal/.env.example` e a configuração `config/mail.php` passam a adotar `MAIL_MAILER=array` no lugar de `log`. Essa configuração **não envia e-mails nem escreve o corpo de mensagens em logs**. O fallback `failover` também passa de `smtp → log` para `smtp → array`, evitando que uma falha de SMTP transfira o comprovante sigiloso para o log da aplicação. `array` serve apenas como transportador de teste descartável e **não deve ser usado como solução operacional de entrega**.

O novo contrato puro `PortalEmailDeliveryPolicy` exige simultaneamente transporte `smtp` explícito, TLS validado, domínio do remetente confirmado, autorização de provisionamento da primeira conta, aprovação AppSec independente e testemunha externa de anti-rollback. Nenhum outro transporte é considerado apto para transportar segredo de convite.

O arquivo `config/portal_email_delivery.php` mantém os seis sinais necessários como constantes `false`, **não habilitáveis por variável de ambiente**. O código não registra emissor, comando, rota, Mail facade, filas, credenciais, SMTP real, QR ou sessão. Mesmo uma avaliação `true` da política sob dados de teste não autoriza acesso a recursos nem envia mensagens.

## Provas

A suíte `PortalEmailDeliveryPolicyTest` verifica padrão `array`, ausência de fallback `log`, valores de segurança iniciais falsos, recusa de transportes alternativos, recusa caso qualquer autorização falte e ausência de API de entrega/autenticação.

## Requisitos para ativação real

1. Revisão independente AppSec/DBA e liberação operacional de #64 com testemunha monotônica independente do banco/restauração;
2. Domínio e caixa postal remetente verificados, SPF/DKIM/DMARC e política de configuração SMTP/TLS, tratamento seguro de falhas, credenciais e rotação;
3. Endereço do primeiro operador validado e aprovado, política de cadastro sem credenciais em logs, auditoria de entrega e evento de revogação;
4. Serviço transacional de envio e confirmação integrado ao ledger sem replay ou vazamento de token em filas/tracing/erros;
5. Revisão de integração #341 e homologação autorizada antes de alterar gates, `main`, VPS ou produção.

**No-go:** somente PR de revisão em branch isolada. Sem novos custos ou uso de dados reais.
