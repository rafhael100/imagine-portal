# IMAGINE-OPS-01 · revisão do executor Leaseweb

Escopo inicial de ativação: publicar inventário de leitura por projeto. Este PR
não aplica bootstrap, sudoers, grupos, unidades, regras SSH ou permissões.
Referência: https://github.com/rafhael100/imagine-portal/issues/951

## Inventário

O coletor retorna metadados somente do projeto Compose autorizado. Um relatório
vazio de um projeto sem labels não significa que o host esteja vazio.
Recursos externos e bancos sem labels exigem mapeamento do administrador.
Registrar evidências específicas do ambiente em canal administrativo restrito.

## Instalação inicial para revisão do administrador

- Revisar os fontes e instalar os arquivos como root, sob ancestrais root-owned
  sem permissão de escrita para grupo/outros. Executar Python com `-I`.
  Criar `/etc/imagine-ops/approved`, `/var/lib/imagine-ops` e
  `/usr/local/libexec/imagine-ops`. Política e aprovações com modo 0600.
- Para a primeira etapa instalar apenas `status` e `inventory.py` na pasta de
  handlers. Criar `reports` com proprietário root, grupo dedicado
  `imagine-ops-read` e modo 0750. Autorizar à conta SSH apenas leitura desse
  grupo. O diretório Docker de configuração pertence ao administrador.
- Parametrizar a política com os projetos Compose confirmados por ele. Exemplo:
  `{"approvers":["owner"],"targets":{"n8n-staging":{"status":["current"]}}}`.
  Emitir o JSON de aprovação via identidade administrativa, fora da conta
  Codex. Campos: `request`, `task`, `initiator`, `approver`, `operation`,
  `target`, `version`, `environment` (staging) e `expires` (epoch UTC, máximo 24 h).
- A primeira coleta pode rodar em job administrativo que invoca o executor com
  ID fixo. Enviar stdout ao journal da unidade e manter a trilha de auditoria
  restrita. Autorizar a Juliana a solicitar leitura pelo Cloud e validar
  timestamps, escopo de saída e permanência do bloqueio ao socket Docker.
  A publicação atômica preserva o relatório anterior se a coleta falhar.
- Antes de liberar cada operação mutável, revisar e instalar o respectivo
  handler separado e fazer teste da receita e do preflight. Hoje os cinco
  handlers mutáveis não são instaláveis a partir deste PR. Sem arquivo
  do handler, o executor recusa a solicitação.
- O administrador resolve o ID armazenado em fila confiável. O usuário SSH
  não escreve aprovações, política, handlers ou estados. O executor registra
  início/fim, serializa operações, consome aprovações mutáveis antes de executar
  e descarta stdout/stderr dos handlers. Se houver falha ou interrupção,
  conferir o estado real antes de aprovar outro ID. Uma aprovação de status
  pode ser reutilizada até expirar. Revogar a autorização antes de disparar
  o job, pois o executor carrega um snapshot da política na entrada.

## Matriz

| Operação | Alvo e versão | Gate |
|---|---|---|
| status | Projeto aprovado, versão `current` | Configuração inicial; leitura posterior automática |
| backup-run | Fonte staging e perfil predefinido | Escopo, destino, limites e retenção aprovados |
| backup-verify | Snapshot conhecido | Leitura dentro da política aprovada |
| restore-test | Snapshot conhecido | Aprovação por ensaio isolado; destino fixado no handler |
| deploy-staging | Projeto staging e SHA256 permitido | CI, preflight, rollback e aprovação por digest |
| install-n8n-staging | Projeto staging e digest da receita | Inventário, acesso HTTPS/MFA, recuperação e aprovação específica |

Produção, mudança de rede, privilégios, migração de banco e restauração sobre
serviço real exigem fluxo separado e ficam fora da lista do executor. Juliana
solicita pelo Codex Cloud com projeto, ambiente, objetivo e evidência esperada.
O responsável emite o registro confiável. Ela acompanha pelo ID e valida
timestamp, alvo, resultado e evidência do relatório. O texto de um pedido
não substitui um registro administrativo.

## Testes

```sh
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s ops/leaseweb
sh -n ops/leaseweb/status
```

Cobertura: despacho permitido, replay, status repetido, falha de backup,
aprovação ausente, origem insegura, symlink, versão/alvo/ambiente divergentes,
expiração, operação fora da lista e coleta Docker filtrada. O daemon da VPS,
as permissões de bootstrap e o acesso da Juliana ainda exigem validação no host.
