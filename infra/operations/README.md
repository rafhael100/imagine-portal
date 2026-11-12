# Checagem local de saúde do desenvolvimento

O script dev-healthcheck.sh verifica /up nos gateways loopback do Portal (18080) e CRM (18081), HTTPS com certificado válido e 401 nas prévias, além dos serviços nginx, docker e certbot.timer. Resolve os hostnames HTTPS para 127.0.0.1 e verifica o certificado normalmente; não mede DNS externo ou disponibilidade da Internet. O 401 comprova o desafio de autenticação esperado, não login funcional. Não reinicia serviços, lê segredos, envia mensagens ou acessa dados de clientes.

Executar como root. O resultado mais recente fica em /var/lib/imagine-dev/health.json (diretório 0700, arquivo 0600, troca atômica). flock evita execuções sobrepostas; cada requisição tem limite de oito segundos. Falhas são registradas no syslog com identificador imagine-dev-healthcheck, sem corpos, cabeçalhos ou credenciais.

Instalação na VPS autorizada: instalar o script como /usr/local/sbin/imagine-dev-healthcheck, proprietário root:root e modo 0700; validar com bash -n e executar uma vez antes de ativar. Em /etc/cron.d/imagine-dev-healthcheck, proprietário root:root e modo 0644, usar:
```cron
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/5 * * * * root /usr/local/sbin/imagine-dev-healthcheck >/dev/null 2>&1
```
O agendamento é cron do servidor; não cria notificações externas nem monitoramento independente. Para desativar, remover somente esse arquivo cron. O script pode ser executado manualmente para diagnóstico. Não substitui backups, restauração testada ou monitoramento externo.
