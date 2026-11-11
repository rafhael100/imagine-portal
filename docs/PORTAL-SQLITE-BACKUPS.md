# Backup verificado do SQLite legado do Portal — F208

O utilitário `server/backup-cli.mjs` fornece um ponto de recuperação SQLite
**somente sob solicitação explícita do operador**. Não é rotina agendada,
não faz deploy, não modifica o banco de origem e não substitui um plano
de backup de produção aprovado.

## Execução manual

```bash
node server/backup-cli.mjs /caminho/privado/portal.sqlite /caminho/seguro/backups/portal-2026-11-11.sqlite
```

Os dois caminhos são obrigatórios. A origem deve existir como arquivo regular,
sem link simbólico; é aberta somente para leitura. A biblioteca SQLite cria
um snapshot consistente em arquivo temporário com permissão `0600`,
faz `PRAGMA integrity_check` e só depois publica o arquivo por ligação
atômica sem sobrescrever destino já existente. Em falha, o temporário é removido.
O resultado do terminal informa nome, tamanho e páginas, sem copiar dados da
base para logs.

## Homologação e recuperação

Os testes usam dados sintéticos em diretório temporário, verificam
integridade, consistência do snapshot após modificar a origem, permissão
restrita, bloqueio de sobrescrita e execução da CLI.

**Restaurar é uma operação separada, não implementada automaticamente**:
a recuperação real precisa de cópia testada, isolamento do serviço, janela
de manutenção, backup prévio da base atual e validação independente DBA/
AppSec. Manter os backups fora da web e da árvore pública do projeto.
Nunca fazer upload de bancos de clientes para GitHub.

Uma cópia SQLite **não** é backup de arquivos enviados, credenciais,
provedores, Laravel/PostgreSQL ou serviços externos. Planejar essas
camadas e a política de retenção separadamente.

## Verificação de recuperação sem restauração (F209)

```bash
node server/backup-cli.mjs --verify /caminho/seguro/backups/portal-2026-11-11.sqlite
```

Esta execução abre o arquivo **somente para leitura**, rejeita links
simbólicos, executa `PRAGMA integrity_check` e `foreign_key_check`,
valida as tabelas canônicas `users`, `sessions`, `orders`,
`schema_version` e retorna exclusivamente contagens agregadas.
Não altera, restaura ou publica os dados. A verificação não garante
sozinha recuperabilidade de anexos, serviços externos e banco PostgreSQL.
