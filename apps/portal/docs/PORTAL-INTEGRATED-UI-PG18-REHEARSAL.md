# Imagine Portal — primeira integração isolada entre interface e domínio PostgreSQL

## Conteúdo integrado

Esta branch de revisão reúne, **pela primeira vez numa mesma árvore**, duas frentes desenvolvidas separadamente e aprovadas em suas próprias CIs:

1. Interface Laravel Imagine com a logomarca aprovada da versão Node, marca `#F9941D`/`#171B19`, 8 seções e prévia **offline de 11 páginas**: painel de próximas etapas, preparo de Onepage/Landing/Performance AI, contratos e solicitações ilustrativos, menu lateral navegável em arquivos HTML locais com teclado e testes em 7 larguras. Origem: PRs #588, #592, #594, #595, #622, #623, #624; HEAD UI `c4d3fa1de40e2a4495d1c3c678d3126a15e81014`.
2. Fundação Laravel/PostgreSQL de **projetos isolados por empresa**, checklists obrigatórios e solicitações com histórico de transições: PRs #625, #626 e #627; HEAD de banco `83fb4389479678513ec0a1ef48df3b4896334aca`. Preserva o núcleo de autenticação/segurança de revisão dessa árvore e as migrações #019–#021, todas executadas somente em PostgreSQL 18 descartável da CI.

A integração é um commit de **dois pais Git**, com árvore resultante construída sobre o HEAD da base PostgreSQL. Foram incorporadas 42 versões de arquivos da UI que eram novas ou divergentes, preservando **explicitamente as implementações e o teste `DemoContractOverview` existentes no ramo de banco** por seu vínculo comum à organização de exemplo. Os outros 34 arquivos da UI com hash já idêntico à base não foram substituídos. Não houve sobrescrita de migrations, guards, triggers de segurança, roles, credenciais ou configuradores de infraestrutura.

## Critérios para este lote

- Todas as suítes existentes de Laravel e PostgreSQL 18 nas duas versões PHP continuam sendo **obrigatórias**.
- A exportação offline da própria UI deve produzir **11 HTMLs e 1 manifesto**, validando hashes, ausência de links externos/rotas/contas e acessibilidade em Chromium em sete larguras.
- O role de runtime continua **sem SELECT/INSERT/UPDATE** nas tabelas candidatas, com RLS forçada e sem policies permissivas.
- A coexistência física das telas e das tabelas **não liga a UI ao banco**. Isso precisará de um leitor autenticado, com escopo de tenant e aprovação independente, e não deve ser simulado por consultas superusuário.

**Gates NO-GO:** sem envio, aprovação, login público, pagamento, publicação, campanhas ou acesso cliente. As issues #64 (revogação/restore independente) e #341 (integração com autorização AppSec/DBA) seguem abertas. Esta PR deve permanecer em **DRAFT**, sem merge em `main`/`dev`, deploy, uso de dados reais ou alteração da VPS.
