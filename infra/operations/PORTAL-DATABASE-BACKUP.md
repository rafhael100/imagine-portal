# Backup local do PostgreSQL de desenvolvimento

portal-db-backup.sh executa como root, em diretório de implantação fixo, com lock e limite por comando. Cria arquivo custom pg_dump com donos/ACL, globals.sql, tar dos segredos privados, commit de origem, horário e hashes. O destino /srv/imagine/backups/portal-db fica 0700, arquivos 0600. Só publica um diretório final com COMPLETE depois de todos os comandos. Em erro, preserva diretório parcial privado e registra falha genérica no syslog.

Instalar como /usr/local/sbin/imagine-portal-db-backup, root:root 0700; testar manualmente antes de configurar cron diário. Não imprime conteúdo dos dumps, senhas, chaves nem hashes de senhas. globals.sql e secrets.tgz são sensíveis; não enviar para GitHub, páginas, artefatos públicos ou suporte. Não há remoção automática de backups.

portal-db-restore-probe.sh recebe um diretório COMPLETE com hashes válidos. Recusa fonte com identidades reais. Cria um banco novo portal_identity_restore_probe no mesmo cluster; se já existir, aborta e não o apaga. Restaura somente nesse banco novo, preservando donos/ACL e validando marcador, oito tabelas RLS FORCE, funções SECURITY DEFINER e ausência de SELECT direto dos runtimes. Remove somente o banco que acabou de criar, inclusive em falha. Não executa globals.sql nem altera o banco de origem.

Esta prova cobre a fundação vazia e restauração lógica no mesmo cluster, não recuperação após perda da VPS. Backup local não protege contra perda do disco; cópia externa, custódia/rotação, restauração em cluster novo e mecanismos para não regredir contadores MFA/revisões/revogações continuam necessários antes de dados reais. Depois de cadastrar identidades, o probe inicial deve falhar; planejar ensaio operacional específico sem reativar sessões.

O healthcheck passa a incluir o estado healthy do contêiner PostgreSQL, além dos sete checks anteriores. Não transforma 401 da prévia em prova de login funcional.
