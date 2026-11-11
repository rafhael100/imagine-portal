# Portal F142 — Resumo de solicitações por situação (somente demonstração)

A tela de Solicitações do Portal já exibia uma fila sintética validada pelo `RequestInboxPreview`, porém não apresentava um resumo facilmente legível da distribuição dos estados.

A F142 acrescenta ao Blade existente um quadro **"Resumo por situação — apenas exemplos"** que deriva seus totais exclusivamente dos mesmos três registros fictícios mostrados na tabela de solicitações, sem nova consulta, novas classes de persistência ou acesso a clientes.

## Regra de integridade de contagens

`DemoRequestInboxStatusBoard` usa `DemoRequestInbox::preview()` e retorna os estados com contagens positivas somente quando o resultado é um conjunto *inteiro*: status de projeção válido, `nextOffset=null`, `total=count(items)`, até 50 linhas, objetos de tipo correto, referências sem repetição e a mesma organização fictícia `DEMO-ORG-AURORA`. Se a página for parcial, um registro pertencer a outra organização ou o DTO contiver informação adulterada, o resumo retorna vazio em vez de inventar totais ou filtrar parte dos dados.

A distribuição atual de exemplo é: **Em análise: 1; Aguardando informações do cliente: 1; Resolvida: 1**. Nenhuma contagem indica fila real, trabalho concluído, prazo, receita ou serviço contratado.

Os testes PHPUnit cobrem os estados esperados, negação por tenant divergente, referência duplicada, página truncada, contagem inconsistente, entrada irregular e a representação HTML da nova tabela. A visualização permanece sem formulário, botão, rota, SLA, envio ou alteração de status.

**NO-GO:** não há leitura de banco real, sessão/MFA ou direitos de cliente. A classe não autoriza transições de solicitações, contato ou atendimento. Publicação, migrações, integrações ou despesas adicionais permanecem bloqueadas até revisão independente e homologação.
